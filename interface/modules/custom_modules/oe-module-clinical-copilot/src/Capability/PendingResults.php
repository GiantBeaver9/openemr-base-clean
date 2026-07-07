<?php

/**
 * PendingResults (UC1, UC4, UC5) — the in-flight capability: what's drawn but not resulted.
 *
 * An unresulted order is an ABSENCE (order exists, result does not) — invisible to every
 * chart view, where silence reads as "no recent lab" and invites the duplicate order (T10).
 * So absence-of-result is a first-class fact here. From its own PendingOrderSource it emits
 * a `pending_order` fact per in-flight order, and a derived `expected_result_date` (collection
 * + turnaround days, from versioned cadence config — the fact carries the date, prose never
 * computes it). A pending order NEVER counts as a result and NEVER resets the overdue clock
 * (structurally: OverdueTests reads only presented trend points, never these).
 *
 * Preliminary results are surfaced here too (the in-flight section, UC5) but are read back
 * from the LabSlice — reusing the slice's C3/C4 parse rather than opening a second parsing
 * path — and re-stamped as PendingResults facts: labeled, never a trend point, never a clock
 * reset (C2/T10).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSlice;

final class PendingResults implements CapabilityInterface
{
    public const VERSION = 'pending_results@1';

    private const ORDER_TABLE = 'procedure_order';

    public function __construct(
        private readonly PendingOrderSource $orders,
        private readonly LabSlice $slice,
        private readonly CadenceConfig $cadence,
        private readonly DerivedFacts $derived = new DerivedFacts(),
        private readonly string $version = self::VERSION,
    ) {
    }

    public function forPatient(int $pid): array
    {
        $facts = [];

        foreach ($this->orders->pendingOrders($pid) as $order) {
            $facts[] = $this->pendingOrderFact($pid, $order);

            $expected = $this->expectedResultDateFact($pid, $order);
            if ($expected !== null) {
                $facts[] = $expected;
            }
        }

        foreach ($this->preliminaryFacts($pid) as $fact) {
            $facts[] = $fact;
        }

        return $facts;
    }

    private function pendingOrderFact(int $pid, PendingOrder $order): Fact
    {
        $flags = ['order_status:' . $order->orderStatus];
        $analyte = $order->loinc !== null ? $this->cadence->analyteForLoinc($order->loinc) : null;
        if ($analyte !== null) {
            $flags[] = 'analyte:' . $analyte;
        }

        return new Fact(
            Capability::PendingResults,
            $this->version,
            FactKind::PendingOrder,
            $pid,
            $order->collectionDate,
            DateSource::Collected,
            null,
            FactStatus::Unstated,
            $flags,
            [new Citation(self::ORDER_TABLE, $order->procedureOrderId, 'order_status', DateSource::Collected)],
        );
    }

    private function expectedResultDateFact(int $pid, PendingOrder $order): ?Fact
    {
        if ($order->collectionDate === null || $order->loinc === null) {
            return null;
        }
        $analyte = $this->cadence->analyteForLoinc($order->loinc);
        if ($analyte === null) {
            return null;
        }
        $days = $this->cadence->turnaroundDays($analyte);
        if ($days === null) {
            return null;
        }

        return $this->derived->expectedResultDate(
            $pid,
            $order->collectionDate,
            $days,
            'turnaround:' . $analyte . '@' . $this->cadence->version(),
            new Citation(self::ORDER_TABLE, $order->procedureOrderId, 'date_collected', DateSource::Collected),
            Capability::PendingResults,
            $this->version,
        );
    }

    /**
     * Preliminary results, re-read from the LabSlice (reusing its parse — no second parsing
     * path) and re-stamped as PendingResults in-flight facts.
     *
     * @return list<Fact>
     */
    private function preliminaryFacts(int $pid): array
    {
        $out = [];
        foreach ($this->slice->extract($pid) as $fact) {
            if ($fact->kind !== FactKind::PreliminaryResult) {
                continue;
            }
            $out[] = new Fact(
                Capability::PendingResults,
                $this->version,
                FactKind::PreliminaryResult,
                $pid,
                $fact->clinicalDate,
                $fact->dateSource,
                $fact->value,
                FactStatus::Preliminary,
                $fact->flags,
                $fact->citations,
            );
        }
        return $out;
    }

    public function version(): string
    {
        return $this->version;
    }
}
