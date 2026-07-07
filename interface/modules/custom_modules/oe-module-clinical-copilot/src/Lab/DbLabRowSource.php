<?php

/**
 * DbLabRowSource — the production LabRowSource: the C1–C4 lab slice's 3-table join,
 * executed against the host tables via QueryUtils (parameterized binds only, never
 * string-concatenated SQL). Module is READ-ONLY to these core tables.
 *
 * The join is DRIVEN FROM `procedure_order WHERE activity = 1` (audit finding P4 — the
 * `activity`/soft-delete gate lives on the order), then INNER-joined through
 * `procedure_report` to `procedure_result`. INNER joins mean orders without a result are
 * not returned here (drawn-but-unresulted orders are PendingResults' concern, not the
 * lab slice's). `procedure_result` has no pid, so patient scope is enforced solely by
 * the bound `procedure_order.patient_id`.
 *
 * Framework-coupled: this file cannot run under the isolated runner (it needs the
 * OpenEMR DB stack); it is `php -l`-clean and exercised in-stack. FixtureLabRowSource is
 * its isolated twin.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Common\Database\QueryUtils;

final class DbLabRowSource implements LabRowSource
{
    /**
     * Driven from procedure_order (activity gate, P4); `range` and `date` are backticked
     * as reserved words. Column names match the host schema (see fixtures README).
     */
    private const SQL = <<<'SQL'
        SELECT
            po.procedure_order_id        AS procedure_order_id,
            po.patient_id                AS patient_id,
            po.activity                  AS activity,
            po.date_collected            AS order_date_collected,
            prep.procedure_report_id     AS procedure_report_id,
            prep.date_collected          AS report_date_collected,
            prep.date_report             AS report_date_report,
            pr.procedure_result_id       AS procedure_result_id,
            pr.result_code               AS result_code,
            pr.result_text               AS result_text,
            pr.result                    AS result,
            pr.units                     AS units,
            pr.result_data_type          AS result_data_type,
            pr.result_status             AS result_status,
            pr.abnormal                  AS abnormal,
            pr.`range`                   AS `range`,
            pr.`date`                    AS result_date
        FROM procedure_order po
        JOIN procedure_report prep ON prep.procedure_order_id = po.procedure_order_id
        JOIN procedure_result pr   ON pr.procedure_report_id = prep.procedure_report_id
        WHERE po.patient_id = ? AND po.activity = 1
        SQL;

    public function fetchForPatient(int $pid): array
    {
        /** @var list<array<string, mixed>> $records */
        $records = QueryUtils::fetchRecords(self::SQL, [$pid]);

        $rows = [];
        foreach ($records as $record) {
            $rows[] = new LabRow(
                (int) ($record['procedure_order_id'] ?? 0),
                (int) ($record['procedure_report_id'] ?? 0),
                (int) ($record['procedure_result_id'] ?? 0),
                $pid,
                (int) ($record['activity'] ?? 0),
                $this->str($record['result_code'] ?? ''),
                $this->str($record['result_text'] ?? ''),
                $this->str($record['result'] ?? ''),
                $this->str($record['units'] ?? ''),
                $this->str($record['result_data_type'] ?? ''),
                $this->str($record['result_status'] ?? ''),
                $this->str($record['abnormal'] ?? ''),
                $this->str($record['range'] ?? ''),
                $this->nullableStr($record['report_date_collected'] ?? null),
                $this->nullableStr($record['order_date_collected'] ?? null),
                $this->nullableStr($record['result_date'] ?? null),
                $this->nullableStr($record['report_date_report'] ?? null),
            );
        }

        return $rows;
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
