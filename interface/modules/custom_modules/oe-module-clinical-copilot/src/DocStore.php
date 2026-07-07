<?php

/**
 * DocStore — the append-only repository over mod_copilot_doc (T7).
 *
 * A synthesis document is content-addressed by (pid, fact_digest): the same fact set at
 * the same capability/prompt versions always produces the same digest, so a recurrence
 * serves the original row instead of writing a new one. This class is deliberately
 * insert + read only — there is no mutation method and no rewriting SQL of any kind — so
 * the ledger is a faithful record of exactly what each document contained when it was
 * served. Row I/O is delegated to a DocGateway so the mapping and the append-only
 * guarantee are testable with no database.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Doc\CopilotDoc;
use OpenEMR\Modules\ClinicalCopilot\Doc\DocGateway;

final class DocStore
{
    public function __construct(private readonly DocGateway $gateway)
    {
    }

    /**
     * Persist a document and return its id. If its content address is already stored, the
     * original row's id is returned unchanged — recurrence is served, never re-written.
     */
    public function store(CopilotDoc $doc): int
    {
        $existing = $this->gateway->findByPidAndDigest($doc->pid, $doc->factDigest);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->gateway->insert($doc->toRow());
    }

    /**
     * Resolve the single document for a content address, or null if it was never stored.
     */
    public function findByPidAndDigest(int $pid, string $digest): ?CopilotDoc
    {
        $row = $this->gateway->findByPidAndDigest($pid, $digest);

        return $row === null ? null : CopilotDoc::fromRow($row);
    }

    /**
     * Every document ever served for a patient, oldest first (ORDER BY computed_at).
     *
     * @return list<CopilotDoc>
     */
    public function history(int $pid): array
    {
        return array_map(
            static fn(array $row): CopilotDoc => CopilotDoc::fromRow($row),
            $this->gateway->historyByPid($pid),
        );
    }
}
