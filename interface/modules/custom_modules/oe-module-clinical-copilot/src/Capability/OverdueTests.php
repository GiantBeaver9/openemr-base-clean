<?php

/**
 * OverdueTests (UC1, UC4) — monitoring gaps, proven from the chart.
 *
 * For each analyte with a cadence interval it takes the latest presented draw (from the
 * LabSlice's clock-resetting trend points — preliminary/excluded rows never count, C2/T10)
 * and emits an `overdue_item` ONLY when last-draw + interval falls before the as-of date:
 * overdue-ness is proved by a cited draw, never asserted from an absence. The days-overdue
 * number rides on the fact (prose never computes it).
 *
 * It composes with PendingResults (UC4's "overdue BUT already drawn — do not reorder"):
 * a reorder-suppression flag is attached ONLY when an active pending order for the SAME
 * analyte proves the specimen is already in flight. Per the fixtures, patient 9002's single
 * pending order is an A1c, not an ACR — so its overdue ACR is NOT suppressed.
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
use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabRowSource;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSlice;

final class OverdueTests implements CapabilityInterface
{
    public const VERSION = 'overdue_tests@1';

    public const FLAG_REORDER_SUPPRESSED = 'reorder_suppressed';

    private readonly \DateTimeImmutable $asOf;

    public function __construct(
        private readonly LabSlice $slice,
        private readonly LabRowSource $source,
        private readonly CadenceConfig $cadence,
        private readonly PendingOrderSource $pendingOrders,
        ?\DateTimeImmutable $asOf = null,
        private readonly string $version = self::VERSION,
    ) {
        // Date-only anchor; overdue math is calendar-day arithmetic (no TZ/time drift).
        $this->asOf = ($asOf ?? new \DateTimeImmutable('today'))->setTime(0, 0);
    }

    public function forPatient(int $pid): array
    {
        $labFacts = $this->slice->extract($pid);
        $analyteByResultId = AnalyteTrendIndex::analyteMap($this->source, $this->cadence, $pid);
        $index = AnalyteTrendIndex::build($labFacts, $analyteByResultId);

        $pendingAnalytes = $this->pendingAnalytes($pid);

        $facts = [];
        foreach ($this->cadence->analytesWithInterval() as $analyte) {
            $lastDraw = $index->lastDrawDate($analyte);
            if ($lastDraw === null) {
                continue; // no draw on record → cannot prove overdue
            }
            $interval = $this->cadence->intervalDays($analyte);
            if ($interval === null) {
                continue;
            }

            try {
                $due = (new \DateTimeImmutable($lastDraw))->setTime(0, 0)->modify('+' . $interval . ' days');
            } catch (\Throwable) {
                continue;
            }
            if ($due >= $this->asOf) {
                continue; // not overdue yet
            }

            $daysOverdue = (int) $due->diff($this->asOf)->days;
            $drawFact = $index->drawOn($analyte, $lastDraw);
            if ($drawFact === null) {
                continue;
            }

            $flags = ['analyte:' . $analyte, 'interval_days:' . $interval];
            if (in_array($analyte, $pendingAnalytes, true)) {
                $flags[] = self::FLAG_REORDER_SUPPRESSED;
            }

            $facts[] = new Fact(
                Capability::OverdueTests,
                $this->version,
                FactKind::OverdueItem,
                $pid,
                $lastDraw,
                $drawFact->dateSource,
                new FactValue(
                    'overdue by ' . $daysOverdue . ' days',
                    (float) $daysOverdue,
                    Comparator::None,
                    'days',
                    'days',
                    null,
                ),
                FactStatus::Unstated,
                $flags,
                $drawFact->citations,
            );
        }

        return $facts;
    }

    /**
     * Analytes with an active pending order (the reorder-suppression proof set).
     *
     * @return list<string>
     */
    private function pendingAnalytes(int $pid): array
    {
        $analytes = [];
        foreach ($this->pendingOrders->pendingOrders($pid) as $order) {
            if ($order->loinc === null) {
                continue;
            }
            $analyte = $this->cadence->analyteForLoinc($order->loinc);
            if ($analyte !== null && !in_array($analyte, $analytes, true)) {
                $analytes[] = $analyte;
            }
        }
        return $analytes;
    }

    public function version(): string
    {
        return $this->version;
    }
}
