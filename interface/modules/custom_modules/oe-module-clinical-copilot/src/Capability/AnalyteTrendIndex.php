<?php

/**
 * AnalyteTrendIndex — groups a LabSlice fact list into per-analyte trend series.
 *
 * The lab-slice Facts (trend_point / preliminary / exclusion) do not carry the analyte
 * (a LOINC lives on the raw `procedure_result` row, not on the Fact), so this helper joins
 * each trend point back to its analyte via a `procedure_result_id → analyte` map (built
 * from the same LabRowSource + code-set config the slice used) and yields the ordered,
 * per-analyte trend series that ControlProxy (derived deltas) and OverdueTests (last-draw
 * clock) both consume. Ordering is deterministic: clinical date ascending, ties by result
 * id — so derived facts are stable across runs.
 *
 * Only `trend_point` facts are indexed: preliminary results are never trend points and
 * never reset the overdue clock (C2/T10); exclusions are visible but out of the series.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabRowSource;

final class AnalyteTrendIndex
{
    /**
     * @param array<string, list<Fact>> $trendsByAnalyte analyte → trend points (date asc)
     */
    private function __construct(private readonly array $trendsByAnalyte)
    {
    }

    /**
     * Build the `procedure_result_id → analyte` map for one patient from a LabRowSource.
     *
     * @return array<int, string>
     */
    public static function analyteMap(LabRowSource $source, CadenceConfig $cadence, int $pid): array
    {
        $map = [];
        foreach ($source->fetchForPatient($pid) as $row) {
            $analyte = $cadence->analyteForLoinc($row->resultCode);
            if ($analyte !== null) {
                $map[$row->procedureResultId] = $analyte;
            }
        }
        return $map;
    }

    /**
     * @param list<Fact>          $facts            LabSlice output
     * @param array<int, string>  $analyteByResultId procedure_result_id → analyte
     */
    public static function build(array $facts, array $analyteByResultId): self
    {
        /** @var array<string, list<Fact>> $grouped */
        $grouped = [];
        foreach ($facts as $fact) {
            if ($fact->kind !== FactKind::TrendPoint) {
                continue;
            }
            $analyte = self::analyteFor($fact, $analyteByResultId);
            if ($analyte === null) {
                continue;
            }
            $grouped[$analyte][] = $fact;
        }

        foreach ($grouped as $analyte => $points) {
            usort($points, static function (Fact $a, Fact $b): int {
                $cmp = ($a->clinicalDate ?? '') <=> ($b->clinicalDate ?? '');
                if ($cmp !== 0) {
                    return $cmp;
                }
                return self::firstPk($a) <=> self::firstPk($b);
            });
            $grouped[$analyte] = $points;
        }

        return new self($grouped);
    }

    /**
     * @return list<string>
     */
    public function analytes(): array
    {
        return array_keys($this->trendsByAnalyte);
    }

    /**
     * @return list<Fact>
     */
    public function trendPoints(string $analyte): array
    {
        return $this->trendsByAnalyte[$analyte] ?? [];
    }

    /**
     * Latest draw date for an analyte among presented (clock-resetting) trend points —
     * the OverdueTests clock. Null if the analyte has no presented draw.
     */
    public function lastDrawDate(string $analyte): ?string
    {
        $latest = null;
        foreach ($this->trendPoints($analyte) as $point) {
            if (!$point->status->resetsOverdueClock()) {
                continue;
            }
            if ($point->clinicalDate === null) {
                continue;
            }
            if ($latest === null || $point->clinicalDate > $latest) {
                $latest = $point->clinicalDate;
            }
        }
        return $latest;
    }

    /**
     * The presented trend point that carries a given clinical date (for last-draw citation).
     */
    public function drawOn(string $analyte, string $clinicalDate): ?Fact
    {
        foreach ($this->trendPoints($analyte) as $point) {
            if ($point->status->resetsOverdueClock() && $point->clinicalDate === $clinicalDate) {
                return $point;
            }
        }
        return null;
    }

    /**
     * @param array<int, string> $analyteByResultId
     */
    private static function analyteFor(Fact $fact, array $analyteByResultId): ?string
    {
        foreach ($fact->citations as $citation) {
            if ($citation->table === 'procedure_result' && isset($analyteByResultId[$citation->pk])) {
                return $analyteByResultId[$citation->pk];
            }
        }
        return null;
    }

    private static function firstPk(Fact $fact): int
    {
        foreach ($fact->citations as $citation) {
            if ($citation->table === 'procedure_result') {
                return $citation->pk;
            }
        }
        return 0;
    }
}
