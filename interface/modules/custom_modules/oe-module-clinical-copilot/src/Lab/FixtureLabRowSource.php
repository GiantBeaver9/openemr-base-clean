<?php

/**
 * FixtureLabRowSource — the isolated-test LabRowSource. Reads the U2 fixture JSON
 * (procedure_order / procedure_report / procedure_result) and performs the SAME 3-table
 * join the Db impl performs in SQL, in PHP, so LabSlice runs with no database.
 *
 * Join (mirrors host ProcedureService, `activity = 1` only):
 *   procedure_order (patient_id, activity=1) → procedure_report (procedure_order_id)
 *   → procedure_result (procedure_report_id). One LabRow per result row.
 *
 * Reader convention (per fixtures README): any key beginning with `_` is documentation
 * only and never appears on real host rows — this reader only ever reads the real
 * columns by name, so `_note`/`_about` keys are ignored for free.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

final class FixtureLabRowSource implements LabRowSource
{
    public function __construct(private readonly string $fixturesDir)
    {
    }

    public function fetchForPatient(int $pid): array
    {
        $orders = $this->load('procedure_order.json');
        $reports = $this->load('procedure_report.json');
        $results = $this->load('procedure_result.json');

        // Index reports by order id and results by report id for the join.
        $reportsByOrder = [];
        foreach ($reports as $report) {
            $orderId = $this->intOrNull($report['procedure_order_id'] ?? null);
            if ($orderId !== null) {
                $reportsByOrder[$orderId][] = $report;
            }
        }
        $resultsByReport = [];
        foreach ($results as $result) {
            $reportId = $this->intOrNull($result['procedure_report_id'] ?? null);
            if ($reportId !== null) {
                $resultsByReport[$reportId][] = $result;
            }
        }

        $rows = [];
        foreach ($orders as $order) {
            if ($this->intOrNull($order['patient_id'] ?? null) !== $pid) {
                continue;
            }
            if ($this->intOrNull($order['activity'] ?? null) !== 1) {
                continue; // activity = 1 only (soft-delete gate, D7)
            }
            $orderId = $this->intOrNull($order['procedure_order_id'] ?? null);
            if ($orderId === null) {
                continue;
            }

            foreach ($reportsByOrder[$orderId] ?? [] as $report) {
                $reportId = $this->intOrNull($report['procedure_report_id'] ?? null);
                if ($reportId === null) {
                    continue;
                }
                foreach ($resultsByReport[$reportId] ?? [] as $result) {
                    $resultId = $this->intOrNull($result['procedure_result_id'] ?? null);
                    if ($resultId === null) {
                        continue;
                    }
                    $rows[] = new LabRow(
                        $orderId,
                        $reportId,
                        $resultId,
                        $pid,
                        1,
                        $this->str($result['result_code'] ?? ''),
                        $this->str($result['result_text'] ?? ''),
                        $this->str($result['result'] ?? ''),
                        $this->str($result['units'] ?? ''),
                        $this->str($result['result_data_type'] ?? ''),
                        $this->str($result['result_status'] ?? ''),
                        $this->str($result['abnormal'] ?? ''),
                        $this->str($result['range'] ?? ''),
                        $this->nullableStr($report['date_collected'] ?? null),
                        $this->nullableStr($order['date_collected'] ?? null),
                        $this->nullableStr($result['date'] ?? null),
                        $this->nullableStr($report['date_report'] ?? null),
                    );
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function load(string $file): array
    {
        $path = rtrim($this->fixturesDir, '/') . '/' . $file;
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Fixture not readable: ' . $path);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Fixture is not a JSON array: ' . $path);
        }
        /** @var list<array<string, mixed>> $decoded */
        return array_values($decoded);
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private function nullableStr(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return $this->str($value);
    }
}
