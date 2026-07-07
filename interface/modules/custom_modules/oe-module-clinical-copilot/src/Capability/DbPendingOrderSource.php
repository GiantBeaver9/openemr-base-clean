<?php

/**
 * DbPendingOrderSource — the production PendingOrderSource over the host tables.
 *
 * Driven from `procedure_order WHERE activity = 1` (the soft-delete gate lives on the order,
 * P4), LEFT-joined to `procedure_order_code` (the ordered LOINC), `procedure_report`, and
 * `procedure_result`. LEFT joins keep resultless orders in the result set — the whole point,
 * since those are the in-flight orders. Per order we aggregate the result statuses and mark
 * it pending when its status is pending/routed OR no final/corrected result exists. Module
 * is READ-ONLY to these core tables; every value is a parameterized bind.
 *
 * Framework-coupled: needs the OpenEMR DB stack, so it is exercised in-stack, not by the
 * isolated runner; it is `php -l`-clean. FixturePendingOrderSource is its isolated twin.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Common\Database\QueryUtils;

final class DbPendingOrderSource implements PendingOrderSource
{
    private const PENDING_STATUSES = ['pending', 'routed'];
    private const RESULTED_STATUSES = ['final', 'corrected'];

    private const SQL = <<<'SQL'
        SELECT
            po.procedure_order_id   AS procedure_order_id,
            po.order_status         AS order_status,
            po.date_collected       AS order_date_collected,
            poc.procedure_code      AS loinc,
            prep.date_collected     AS report_date_collected,
            pr.result_status        AS result_status
        FROM procedure_order po
        LEFT JOIN procedure_order_code poc ON poc.procedure_order_id = po.procedure_order_id
        LEFT JOIN procedure_report prep    ON prep.procedure_order_id = po.procedure_order_id
        LEFT JOIN procedure_result pr      ON pr.procedure_report_id = prep.procedure_report_id
        WHERE po.patient_id = ? AND po.activity = 1
        SQL;

    public function pendingOrders(int $pid): array
    {
        /** @var list<array<string, mixed>> $records */
        $records = QueryUtils::fetchRecords(self::SQL, [$pid]);

        /** @var array<int, array{status: string, loinc: ?string, collected: ?string, hasFinal: bool}> $byOrder */
        $byOrder = [];
        foreach ($records as $record) {
            $orderId = (int) ($record['procedure_order_id'] ?? 0);
            if ($orderId === 0) {
                continue;
            }
            if (!isset($byOrder[$orderId])) {
                $byOrder[$orderId] = [
                    'status' => strtolower(trim($this->str($record['order_status'] ?? ''))),
                    'loinc' => null,
                    'collected' => null,
                    'hasFinal' => false,
                ];
            }

            $loinc = $this->str($record['loinc'] ?? '');
            if ($loinc !== '' && $byOrder[$orderId]['loinc'] === null) {
                $byOrder[$orderId]['loinc'] = $loinc;
            }

            $collected = $this->normalizeDate($this->nullableStr($record['report_date_collected'] ?? null))
                ?? $this->normalizeDate($this->nullableStr($record['order_date_collected'] ?? null));
            if ($collected !== null && $byOrder[$orderId]['collected'] === null) {
                $byOrder[$orderId]['collected'] = $collected;
            }

            $resultStatus = strtolower(trim($this->str($record['result_status'] ?? '')));
            if (in_array($resultStatus, self::RESULTED_STATUSES, true)) {
                $byOrder[$orderId]['hasFinal'] = true;
            }
        }

        $pending = [];
        foreach ($byOrder as $orderId => $data) {
            $isPending = in_array($data['status'], self::PENDING_STATUSES, true) || !$data['hasFinal'];
            if (!$isPending) {
                continue;
            }
            $pending[] = new PendingOrder(
                $orderId,
                $data['loinc'],
                $data['status'],
                $data['collected'],
                $data['hasFinal'],
            );
        }

        return $pending;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '0000-00-00')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($trimmed))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
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
