<?php

/**
 * SessionStore — the append-only repository over the chat session + turn ledger (§1.1, T7).
 *
 * Wraps a SessionGateway to provide the session lifecycle the controller and agent need: lazy
 * open (reuse the physician's open conversation for this patient, else create a pinned one),
 * load, append-only turn writes with auto-assigned seq, the freeze transition (the only mutation),
 * and the atomic one-active-turn slot. Row I/O lives in the gateway so this orchestration is
 * isolated-testable with no database.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimitConfig;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimitDecision;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimiter;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationVerdict;

final class SessionStore
{
    public function __construct(private readonly SessionGateway $gateway)
    {
    }

    /**
     * Lazily open a pid-pinned session: reuse the most recent ACTIVE session for (pid, user) if
     * one exists (a frozen one is never reused — it is terminal evidence), else create one pinned
     * to $pid server-side with the seed digest and originating doc id.
     */
    public function open(int $pid, int $userId, ?int $docId, string $factDigest, ?string $createdAt = null): ChatSession
    {
        $existing = $this->gateway->findActiveSessionForPatient($pid, $userId);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->gateway->createSession($pid, $userId, $docId, $factDigest, $createdAt ?? $this->now());
        $session = $this->gateway->findSession($id);
        if ($session === null) {
            // Should never happen; surface loudly rather than continue with a null session.
            throw new \RuntimeException('Chat session could not be loaded immediately after creation.');
        }
        return $session;
    }

    public function load(int $id): ?ChatSession
    {
        return $this->gateway->findSession($id);
    }

    /**
     * Freeze a session (V3 SEV-1). The status transition is the only mutation the ledger allows;
     * the session and its turns are preserved as incident evidence (§3.5).
     */
    public function freeze(int $sessionId): void
    {
        $this->gateway->updateStatus($sessionId, ChatSessionStatus::Frozen);
    }

    /**
     * Append a turn, assigning the next seq automatically. Returns the new turn's id.
     */
    public function appendTurn(
        int $sessionId,
        TurnRole $role,
        string $content,
        string $correlationId,
        ?string $toolCalls = null,
        ?VerificationVerdict $verdict = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?float $costUsd = null,
    ): int {
        $turn = new ChatTurn(
            $sessionId,
            $this->gateway->nextSeq($sessionId),
            $role,
            $content,
            $toolCalls,
            $verdict === null ? null : (string) json_encode($verdict->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $correlationId,
            $tokensIn,
            $tokensOut,
            $costUsd,
            null,
            $this->now(),
        );
        return $this->gateway->appendTurn($turn);
    }

    /**
     * @return list<ChatTurn>
     */
    public function turns(int $sessionId): array
    {
        return $this->gateway->turnsForSession($sessionId);
    }

    public function acquireTurnSlot(int $sessionId): bool
    {
        return $this->gateway->acquireTurnSlot($sessionId);
    }

    public function releaseTurnSlot(int $sessionId): void
    {
        $this->gateway->releaseTurnSlot($sessionId);
    }

    /**
     * Evaluate the §3.7 rate limits for a would-be turn via the pure RateLimiter. `$slotHeld`
     * is whether THIS request acquired the one-active-turn slot; a second concurrent POST that
     * failed to acquire it is denied with a 409 (SessionTurnInProgress).
     */
    public function rateLimit(int $sessionId, int $userId, bool $slotHeld, ?RateLimitConfig $config = null): RateLimitDecision
    {
        $counts = $this->gateway->rateLimitCounts($sessionId, $userId, $slotHeld);
        return RateLimiter::decide($counts, $config ?? new RateLimitConfig());
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
