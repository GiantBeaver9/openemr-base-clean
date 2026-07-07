<?php

/**
 * ChatSession — an immutable snapshot of one pid-pinned chat session row (§1.1).
 *
 * The pin is STRUCTURAL: `pid` is set server-side at creation and is the only patient a
 * turn can ever touch — no tool accepts a patient argument (§1.2), so the model cannot
 * reach another chart. `userId` is the host authUserID and is re-checked on every turn
 * (§1.3). `factDigest` is the content address of the seed fact set; every turn re-checks
 * it for mid-conversation drift (T19). `status` is active until a V3 trip freezes it (§2.3).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

final readonly class ChatSession
{
    public function __construct(
        public int $id,
        public int $pid,
        public int $userId,
        public ?int $docId,
        public string $factDigest,
        public ChatSessionStatus $status,
        public string $createdAt,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * A copy with a new status — the session row is append-only in spirit; this is the
     * in-memory reflection of a persisted status change (freeze). The DB update is a
     * status-only transition, never a rewrite of history.
     */
    public function withStatus(ChatSessionStatus $status): self
    {
        return new self(
            $this->id,
            $this->pid,
            $this->userId,
            $this->docId,
            $this->factDigest,
            $status,
            $this->createdAt,
        );
    }

    /**
     * Reconstruct from a stored row (DB drivers return every column as a string).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $status = ChatSessionStatus::tryFrom((string) ($row['status'] ?? 'active')) ?? ChatSessionStatus::Active;

        return new self(
            (int) $row['id'],
            (int) $row['pid'],
            (int) $row['user_id'],
            ($row['doc_id'] ?? null) === null || $row['doc_id'] === '' ? null : (int) $row['doc_id'],
            (string) $row['fact_digest'],
            $status,
            (string) ($row['created_at'] ?? ''),
        );
    }
}
