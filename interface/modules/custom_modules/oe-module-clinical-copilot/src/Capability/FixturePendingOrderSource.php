<?php

/**
 * FixturePendingOrderSource — the isolated-test PendingOrderSource.
 *
 * Reads the U2 procedure_order / procedure_report / procedure_result fixtures and classifies
 * each activity=1 order as in-flight when its status is `pending`/`routed` OR it has no
 * final/corrected result row (only preliminary, or none at all). It performs the order→report
 * →result walk in PHP, mirroring the Db impl's SQL.
 *
 * Ordered LOINC: U2's fixtures/seed do not populate `procedure_order_code`, so for a
 * genuinely resultless order the analyte cannot be read from chart rows. Two fallbacks make
 * the isolated tests faithful without touching U2 files: an optional injected
 * `procedure_order_id → LOINC` map (stands in for the production `procedure_order_code`
 * join), then the LOINC of any result row the order does have (e.g. a preliminary result).
 *
 * `_`-prefixed documentation keys never appear on real rows and are ignored for free.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

final class FixturePendingOrderSource implements PendingOrderSource
{
    private const PENDING_STATUSES = ['pending', 'routed'];
    private const RESULTED_STATUSES = ['final', 'corrected'];

    /**
     * @param array<int, string> $orderCodes procedure_order_id → LOINC (stands in for the
     *                                        production procedure_order_code join)
     */
    public function __construct(
        private readonly string $fixturesDir,
        private readonly array $orderCodes = [],
    ) {
    }

    public function pendingOrders(int $pid): array
    {
        $orders = $this->load('procedure_order.json');
        $reports = $this->load('procedure_report.json');
        $results = $this->load('procedure_result.json');

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

        $pending = [];
        foreach ($orders as $order) {
            if ($this->intOrNull($order['patient_id'] ?? null) !== $pid) {
                continue;
            }
            if ($this->intOrNull($order['activity'] ?? null) !== 1) {
                continue;
            }
            $orderId = $this->intOrNull($order['procedure_order_id'] ?? null);
            if ($orderId === null) {
                continue;
            }

            $orderStatus = strtolower(trim($this->str($order['order_status'] ?? '')));
            $reportCollected = null;
            $hasFinal = false;
            $resultLoinc = null;

            foreach ($reportsByOrder[$orderId] ?? [] as $report) {
                if ($reportCollected === null) {
                    $reportCollected = $this->normalizeDate($this->nullableStr($report['date_collected'] ?? null));
                }
                $reportId = $this->intOrNull($report['procedure_report_id'] ?? null);
                if ($reportId === null) {
                    continue;
                }
                foreach ($resultsByReport[$reportId] ?? [] as $result) {
                    $resultLoinc ??= ($this->str($result['result_code'] ?? '') ?: null);
                    $status = strtolower(trim($this->str($result['result_status'] ?? '')));
                    if (in_array($status, self::RESULTED_STATUSES, true)) {
                        $hasFinal = true;
                    }
                }
            }

            $isPending = in_array($orderStatus, self::PENDING_STATUSES, true) || !$hasFinal;
            if (!$isPending) {
                continue;
            }

            $collectionDate = $reportCollected
                ?? $this->normalizeDate($this->nullableStr($order['date_collected'] ?? null));

            $pending[] = new PendingOrder(
                $orderId,
                $this->orderCodes[$orderId] ?? $resultLoinc,
                $orderStatus,
                $collectionDate,
                $hasFinal,
            );
        }

        return $pending;
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
