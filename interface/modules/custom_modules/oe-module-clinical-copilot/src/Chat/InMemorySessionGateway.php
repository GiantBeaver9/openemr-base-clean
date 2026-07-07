<?php

/**
 * InMemorySessionGateway — a database-free SessionGateway for isolated tests.
 *
 * Holds sessions and append-only turns in PHP arrays with auto-incrementing ids, and models the
 * one-active-turn slot as an in-memory set — so the pin, the lazy-create/reuse behaviour, the
 * freeze transition, the append-only turn ledger, and the 409 concurrency guard are all exercised
 * without a stack. Turns are never mutated once appended (T7); the only session mutation is the
 * status→frozen transition.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimitCounts;

final class InMemorySessionGateway implements SessionGateway
{
    /** @var array<int, ChatSession> */
    private array $sessions = [];

    /** @var list<ChatTurn> */
    private array $turns = [];

    /** @var array<int, true> session ids with an in-flight turn slot held */
    private array $slots = [];

    private int $nextSessionId = 1;

    public function createSession(
        int $pid,
        int $userId,
        ?int $docId,
        string $factDigest,
        string $createdAt,
    ): int {
        $id = $this->nextSessionId++;
        $this->sessions[$id] = new ChatSession(
            $id,
            $pid,
            $userId,
            $docId,
            $factDigest,
            ChatSessionStatus::Active,
            $createdAt,
        );
        return $id;
    }

    public function findSession(int $id): ?ChatSession
    {
        return $this->sessions[$id] ?? null;
    }

    public function findActiveSessionForPatient(int $pid, int $userId): ?ChatSession
    {
        $found = null;
        foreach ($this->sessions as $session) {
            if (
                $session->pid === $pid
                && $session->userId === $userId
                && $session->status === ChatSessionStatus::Active
            ) {
                // most recent wins (highest id)
                if ($found === null || $session->id > $found->id) {
                    $found = $session;
                }
            }
        }
        return $found;
    }

    public function updateStatus(int $id, ChatSessionStatus $status): void
    {
        $session = $this->sessions[$id] ?? null;
        if ($session === null) {
            return;
        }
        $this->sessions[$id] = $session->withStatus($status);
    }

    public function appendTurn(ChatTurn $turn): int
    {
        $id = count($this->turns) + 1;
        $this->turns[] = new ChatTurn(
            $turn->sessionId,
            $turn->seq,
            $turn->role,
            $turn->content,
            $turn->toolCalls,
            $turn->verificationVerdict,
            $turn->correlationId,
            $turn->tokensIn,
            $turn->tokensOut,
            $turn->costUsd,
            $id,
            $turn->createdAt,
        );
        return $id;
    }

    public function nextSeq(int $sessionId): int
    {
        $max = 0;
        foreach ($this->turns as $turn) {
            if ($turn->sessionId === $sessionId && $turn->seq > $max) {
                $max = $turn->seq;
            }
        }
        return $max + 1;
    }

    public function turnsForSession(int $sessionId): array
    {
        $out = array_values(array_filter(
            $this->turns,
            static fn(ChatTurn $t): bool => $t->sessionId === $sessionId,
        ));
        usort($out, static fn(ChatTurn $a, ChatTurn $b): int => $a->seq <=> $b->seq);
        return $out;
    }

    public function rateLimitCounts(int $sessionId, int $userId, bool $slotHeld): RateLimitCounts
    {
        $turnsInSession = 0;
        foreach ($this->turns as $turn) {
            if ($turn->sessionId === $sessionId && $turn->role === TurnRole::User) {
                $turnsInSession++;
            }
        }

        $activeSessionsForUser = 0;
        foreach ($this->sessions as $session) {
            if ($session->userId === $userId && $session->status === ChatSessionStatus::Active) {
                $activeSessionsForUser++;
            }
        }

        // In tests the hourly count equals the user's total user-turns (no wall clock).
        $turnsForUserThisHour = 0;
        $userSessionIds = [];
        foreach ($this->sessions as $session) {
            if ($session->userId === $userId) {
                $userSessionIds[$session->id] = true;
            }
        }
        foreach ($this->turns as $turn) {
            if (isset($userSessionIds[$turn->sessionId]) && $turn->role === TurnRole::User) {
                $turnsForUserThisHour++;
            }
        }

        return new RateLimitCounts(
            activeTurnsInSession: $slotHeld ? 0 : 1,
            turnsInSession: $turnsInSession,
            activeSessionsForUser: $activeSessionsForUser,
            turnsForUserThisHour: $turnsForUserThisHour,
        );
    }

    public function acquireTurnSlot(int $sessionId): bool
    {
        if (isset($this->slots[$sessionId])) {
            return false;
        }
        $this->slots[$sessionId] = true;
        return true;
    }

    public function releaseTurnSlot(int $sessionId): void
    {
        unset($this->slots[$sessionId]);
    }
}
