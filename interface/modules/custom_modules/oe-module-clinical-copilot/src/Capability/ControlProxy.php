<?php

/**
 * ControlProxy (UC1, UC2) — the glycemic/lipid control capability.
 *
 * It consumes the U4 LabSlice for the tracked analytes (A1c / glucose / lipids), surfacing
 * the slice's ControlProxy-stamped facts unchanged (trend_point, preliminary_result,
 * exclusion — the hard-won C1–C4 data-quality rules live there, not here) and ADDS the
 * derived facts the slice does not produce: per-analyte derived_delta (first draw → last
 * draw), derived_count, and derived_span. Every derived number is computed deterministically
 * by DerivedFacts and cites the raw trend-point rows it came from (V4), so downstream prose
 * never does arithmetic — the fact carries the number.
 *
 * Derived facts are computed only from quantitative, non-censored draws (a `<7.0` proves a
 * direction, never an exact delta — C3), and each carries an `analyte:<name>` flag so other
 * capabilities (MedResponse pairing) can find the series without re-deriving it.
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
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabRowSource;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSlice;

final class ControlProxy implements CapabilityInterface
{
    public const VERSION = 'control_proxy@1';

    public function __construct(
        private readonly LabSlice $slice,
        private readonly LabRowSource $source,
        private readonly CadenceConfig $cadence,
        private readonly DerivedFacts $derived = new DerivedFacts(),
        private readonly string $version = self::VERSION,
    ) {
    }

    public function forPatient(int $pid): array
    {
        $facts = $this->slice->extract($pid);

        $analyteByResultId = AnalyteTrendIndex::analyteMap($this->source, $this->cadence, $pid);
        $index = AnalyteTrendIndex::build($facts, $analyteByResultId);

        foreach ($index->analytes() as $analyte) {
            $points = $index->trendPoints($analyte);

            $count = $this->derived->count($points, Capability::ControlProxy, $this->version, $analyte);
            if ($count !== null) {
                $facts[] = $count;
            }
            $delta = $this->derived->delta($points, Capability::ControlProxy, $this->version, $analyte);
            if ($delta !== null) {
                $facts[] = $delta;
            }
            $span = $this->derived->span($points, Capability::ControlProxy, $this->version, $analyte);
            if ($span !== null) {
                $facts[] = $span;
            }
        }

        return $facts;
    }

    public function version(): string
    {
        return $this->version;
    }
}
