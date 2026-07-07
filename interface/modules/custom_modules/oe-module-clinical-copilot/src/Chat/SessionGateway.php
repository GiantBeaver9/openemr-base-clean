<?php

/**
 * SessionGateway — the tiny persistence seam under SessionStore (mirrors DocGateway's role).
 *
 * Keeping raw row I/O behind this interface lets the session lifecycle — lazy creation, the
 * server-side pin, append-only turns, the freeze transition, and the rate-limit counts — be
 * isolated-tested with an in-memory implementation and no database. Two impls: InMemorySessionGateway
 * (tests) and DbSessionGateway (mod_copilot_chat_session / _turn via QueryUtils). The interface is
 * append-only for turns (there is no updateTurn/removeTurn); the ONLY mutation is the status→frozen
 * transition (a SEV-1 state change, never a rewrite of history).
 *
 * The `acquireTurnSlot`/`releaseTurnSlot` pair is the atomic one-active-turn-per-session mechanism
 * (§3.7): a second concurrent turn cannot acquire the slot, which the caller maps to HTTP 409.
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

interface SessionGateway
{
    /**
     * Insert a new pid-pinned session row and return its id. The pid is the server-side pin —
     * callers pass the authenticated patient context, never a model-supplied value.
     */
    public function createSession(
        int $pid,
        int $userId,
        ?int $docId,
        string $factDigest,
        string $createdAt,
    ): int;

    public function findSession(int $id): ?ChatSession;

    /**
     * The most recent ACTIVE session for a (pid, user) pair, or null — supports lazy "open the
     * copilot page → reuse the open conversation, else create one" without a second surface.
     */
    public function findActiveSessionForPatient(int $pid, int $userId): ?ChatSession;

    /**
     * Transition a session's status (the only mutation — a freeze). Append-only otherwise.
     */
    public function updateStatus(int $id, ChatSessionStatus $status): void;

    /**
     * Append a turn row and return its id. Never updates or deletes (T7).
     */
    public function appendTurn(ChatTurn $turn): int;

    /**
     * The next turn ordinal for a session (max(seq)+1, 1-based).
     */
    public function nextSeq(int $sessionId): int;

    /**
     * All persisted turns for a session, ordered by seq.
     *
     * @return list<ChatTurn>
     */
    public function turnsForSession(int $sessionId): array;

    /**
     * The rate-limit counts for a (session, user) at the request boundary (§3.7), for the pure
     * RateLimiter. `activeTurnsInSession` reflects the in-flight slot, not a stored column.
     */
    public function rateLimitCounts(int $sessionId, int $userId, bool $slotHeld): RateLimitCounts;

    /**
     * Atomically acquire the one-active-turn slot for a session. Returns false if a turn is
     * already running (→ HTTP 409). Idempotent release via releaseTurnSlot.
     */
    public function acquireTurnSlot(int $sessionId): bool;

    public function releaseTurnSlot(int $sessionId): void;
}
