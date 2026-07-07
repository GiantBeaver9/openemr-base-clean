<?php

/**
 * DbDocGateway — the mod_copilot_doc-backed DocGateway (append-only, T7).
 *
 * All writes go through QueryUtils parameterized binds; the module is read-only to core
 * tables and only ever writes its own mod_copilot_* tables. This gateway is insert +
 * select only: the content-addressed ledger is never rewritten. A racing insert on the
 * same (pid, fact_digest) unique key is resolved by re-reading and serving the original
 * row, so callers still get a stable id.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Doc;

use OpenEMR\Common\Database\QueryUtils;

final class DbDocGateway implements DocGateway
{
    private const COLUMNS = 'id, pid, fact_digest, doc_type, appt_id, doc, capability_versions, '
        . 'prompt_version, computed_at, correlation_id, llm_latency_ms, tokens_in, tokens_out, '
        . 'cost_usd, excluded_counts';

    public function insert(array $row): int
    {
        $sql = "INSERT INTO mod_copilot_doc
            (pid, fact_digest, doc_type, appt_id, doc, capability_versions, prompt_version,
             computed_at, correlation_id, llm_latency_ms, tokens_in, tokens_out, cost_usd, excluded_counts)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $binds = [
            $row['pid'],
            $row['fact_digest'],
            $row['doc_type'],
            $row['appt_id'],
            $row['doc'],
            $row['capability_versions'],
            $row['prompt_version'],
            $row['computed_at'],
            $row['correlation_id'],
            $row['llm_latency_ms'],
            $row['tokens_in'],
            $row['tokens_out'],
            $row['cost_usd'],
            $row['excluded_counts'],
        ];

        try {
            return (int) QueryUtils::sqlInsert($sql, $binds);
        } catch (\Throwable $e) {
            // Unique-key collision on a racing write: the content address is already stored,
            // so re-read and serve the original row rather than surfacing the error.
            $existing = $this->findByPidAndDigest((int) $row['pid'], (string) $row['fact_digest']);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            throw $e;
        }
    }

    public function findByPidAndDigest(int $pid, string $digest): ?array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM mod_copilot_doc WHERE pid = ? AND fact_digest = ? LIMIT 1';
        $rows = QueryUtils::fetchRecords($sql, [$pid, $digest]);

        return $rows[0] ?? null;
    }

    public function historyByPid(int $pid): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM mod_copilot_doc WHERE pid = ? ORDER BY computed_at';

        return array_values(QueryUtils::fetchRecords($sql, [$pid]));
    }
}
