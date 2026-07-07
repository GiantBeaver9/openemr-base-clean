<?php

/**
 * DocGateway — the tiny persistence seam under DocStore.
 *
 * Keeping raw row I/O behind this interface lets the append-only guarantee and the
 * row <-> CopilotDoc mapping be isolated-tested with an in-memory implementation, with no
 * database. Two implementations exist: InMemoryDocGateway (tests) and DbDocGateway
 * (mod_copilot_doc via QueryUtils). The interface is intentionally insert + read only —
 * there is no update or remove method, mirroring the append-only ledger (T7).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Doc;

interface DocGateway
{
    /**
     * Persist a new row and return its generated id. The (pid, fact_digest) unique key is
     * authoritative: a recurring pair is served as the original row, never a second write.
     *
     * @param array<string, mixed> $row insert payload from CopilotDoc::toRow()
     * @return int the id of the stored (or already-stored) row
     */
    public function insert(array $row): int;

    /**
     * Look up the single row for a content address, or null if it has never been stored.
     *
     * @return array<string, mixed>|null
     */
    public function findByPidAndDigest(int $pid, string $digest): ?array;

    /**
     * All rows for a patient, oldest first (ORDER BY computed_at).
     *
     * @return list<array<string, mixed>>
     */
    public function historyByPid(int $pid): array;
}
