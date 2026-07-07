<?php

/**
 * DbSessionGateway — SessionGateway backed by mod_copilot_chat_session / mod_copilot_chat_turn.
 *
 * Module-owned tables only (the module is strictly read-only to every core table, T6). Turns are
 * append-only (INSERT only, T7); the single mutation is the status→frozen transition. The
 * one-active-turn slot uses a MySQL named advisory lock (GET_LOCK/RELEASE_LOCK), which is
 * connection-scoped, so two concurrent requests on two connections cannot both hold it — the
 * deterministic double-submit / two-tabs guard (§3.7) that the caller maps to HTTP 409.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimitCounts;

final class DbSessionGateway implements SessionGateway
{
    private const LOCK_TIMEOUT_SECONDS = 0;

    public function createSession(
        int $pid,
        int $userId,
        ?int $docId,
        string $factDigest,
        string $createdAt,
    ): int {
        return (int) QueryUtils::sqlInsert(
            "INSERT INTO mod_copilot_chat_session (pid, user_id, doc_id, fact_digest, status, created_at)
             VALUES (?, ?, ?, ?, 'active', ?)",
            [$pid, $userId, $docId, $factDigest, $createdAt],
        );
    }

    public function findSession(int $id): ?ChatSession
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT id, pid, user_id, doc_id, fact_digest, status, created_at
             FROM mod_copilot_chat_session WHERE id = ?",
            [$id],
        );
        $row = $rows[0] ?? null;
        return is_array($row) ? ChatSession::fromRow($row) : null;
    }

    public function findActiveSessionForPatient(int $pid, int $userId): ?ChatSession
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT id, pid, user_id, doc_id, fact_digest, status, created_at
             FROM mod_copilot_chat_session
             WHERE pid = ? AND user_id = ? AND status = 'active'
             ORDER BY id DESC LIMIT 1",
            [$pid, $userId],
        );
        $row = $rows[0] ?? null;
        return is_array($row) ? ChatSession::fromRow($row) : null;
    }

    public function updateStatus(int $id, ChatSessionStatus $status): void
    {
        QueryUtils::sqlStatementThrowException(
            "UPDATE mod_copilot_chat_session SET status = ? WHERE id = ?",
            [$status->value, $id],
        );
    }

    public function appendTurn(ChatTurn $turn): int
    {
        $row = $turn->toRow();
        return (int) QueryUtils::sqlInsert(
            "INSERT INTO mod_copilot_chat_turn
                (session_id, seq, role, content, tool_calls, verification_verdict, correlation_id,
                 tokens_in, tokens_out, cost_usd, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $row['session_id'],
                $row['seq'],
                $row['role'],
                $row['content'],
                $row['tool_calls'],
                $row['verification_verdict'],
                $row['correlation_id'],
                $row['tokens_in'],
                $row['tokens_out'],
                $row['cost_usd'],
                $turn->createdAt ?? date('Y-m-d H:i:s'),
            ],
        );
    }

    public function nextSeq(int $sessionId): int
    {
        $value = QueryUtils::fetchSingleValue(
            "SELECT COALESCE(MAX(seq), 0) + 1 AS next_seq FROM mod_copilot_chat_turn WHERE session_id = ?",
            'next_seq',
            [$sessionId],
        );
        return is_numeric($value) ? (int) $value : 1;
    }

    public function turnsForSession(int $sessionId): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT id, session_id, seq, role, content, tool_calls, verification_verdict, correlation_id,
                    tokens_in, tokens_out, cost_usd, created_at
             FROM mod_copilot_chat_turn WHERE session_id = ? ORDER BY seq ASC",
            [$sessionId],
        );
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = ChatTurn::fromRow($row);
            }
        }
        return $out;
    }

    public function rateLimitCounts(int $sessionId, int $userId, bool $slotHeld): RateLimitCounts
    {
        $turnsInSession = $this->count(
            "SELECT COUNT(*) AS c FROM mod_copilot_chat_turn WHERE session_id = ? AND role = 'user'",
            [$sessionId],
        );
        $activeSessionsForUser = $this->count(
            "SELECT COUNT(*) AS c FROM mod_copilot_chat_session WHERE user_id = ? AND status = 'active'",
            [$userId],
        );
        $turnsForUserThisHour = $this->count(
            "SELECT COUNT(*) AS c
             FROM mod_copilot_chat_turn t
             JOIN mod_copilot_chat_session s ON s.id = t.session_id
             WHERE s.user_id = ? AND t.role = 'user' AND t.created_at >= (NOW() - INTERVAL 1 HOUR)",
            [$userId],
        );

        return new RateLimitCounts(
            activeTurnsInSession: $slotHeld ? 0 : 1,
            turnsInSession: $turnsInSession,
            activeSessionsForUser: $activeSessionsForUser,
            turnsForUserThisHour: $turnsForUserThisHour,
        );
    }

    public function acquireTurnSlot(int $sessionId): bool
    {
        $value = QueryUtils::fetchSingleValue(
            "SELECT GET_LOCK(?, ?) AS got",
            'got',
            [$this->lockName($sessionId), self::LOCK_TIMEOUT_SECONDS],
        );
        return (int) $value === 1;
    }

    public function releaseTurnSlot(int $sessionId): void
    {
        QueryUtils::fetchSingleValue(
            "SELECT RELEASE_LOCK(?) AS released",
            'released',
            [$this->lockName($sessionId)],
        );
    }

    /**
     * @param list<mixed> $binds
     */
    private function count(string $sql, array $binds): int
    {
        $value = QueryUtils::fetchSingleValue($sql, 'c', $binds);
        return is_numeric($value) ? (int) $value : 0;
    }

    private function lockName(int $sessionId): string
    {
        return 'mod_copilot_turn_' . $sessionId;
    }
}
