<?php

/**
 * PromptFactWindow: 2-year recency window + per-series cap on dense facts; sparse facts kept whole.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\PromptFactWindow;
use PHPUnit\Framework\TestCase;

final class PromptFactWindowTest extends TestCase
{
    public function testDenseSeriesAreCappedPerCapabilityUnitWithinTheWindow(): void
    {
        $facts = [];
        // 30 A1c (%) + 30 glucose (mg/dL), all within the last ~10 months so
        // the 2-year window keeps them and the per-series count cap (15) binds.
        // Two distinct series (by unit) must NOT share one cap.
        $base = new \DateTimeImmutable('2026-06-01');
        for ($i = 1; $i <= 30; $i++) {
            $date = $base->modify('-' . ($i * 10) . ' days')->format('Y-m-d');
            $facts[] = self::trend('a1c-' . $i, '%', $date, 7.0, $i);
            $facts[] = self::trend('glu-' . $i, 'mg/dL', $date, 120.0, 1000 + $i);
        }
        $facts[] = self::nonValue('med-1', 'med_response', 'med_event', '2026-01-01', 2001);
        $facts[] = self::nonValue('med-2', 'med_response', 'med_event', '2026-01-01', 2002);
        $facts[] = self::nonValue('od-1', 'overdue_tests', 'overdue_item', '2026-01-01', 3001);

        $windowed = PromptFactWindow::forChat($facts);

        $byKind = [];
        $byUnit = [];
        foreach ($windowed as $f) {
            $byKind[$f->kind->value] = ($byKind[$f->kind->value] ?? 0) + 1;
            if ($f->kind->value === 'trend_point') {
                $unit = $f->value?->unitCanonical ?? '';
                $byUnit[$unit] = ($byUnit[$unit] ?? 0) + 1;
            }
        }

        self::assertSame(15, $byUnit['%'], 'A1c trend capped to 15 within the window');
        self::assertSame(15, $byUnit['mg/dL'], 'glucose trend capped to 15, independent of A1c');
        self::assertSame(2, $byKind['med_event'], 'medications are sparse and all kept');
        self::assertSame(1, $byKind['overdue_item'], 'overdue items are sparse and all kept');
    }

    public function testDenseHistoryOlderThanTwoYearsIsDroppedButSparseFactsSurvive(): void
    {
        $facts = [
            self::trend('recent-1', '%', '2026-05-01', 7.2, 1),
            self::trend('recent-2', '%', '2026-01-01', 7.4, 2),
            self::trend('old-1', '%', '2023-01-01', 9.0, 3),   // > 2yr before the newest (2026-05)
            self::trend('old-2', '%', '2021-06-01', 9.5, 4),
            // A medication started 5 years ago but still on the chart: sparse, kept.
            self::nonValue('med-active', 'med_response', 'med_event', '2021-03-01', 9),
        ];

        $ids = array_map(static fn (Fact $f): string => $f->factId, PromptFactWindow::forChat($facts));

        self::assertContains('recent-1', $ids);
        self::assertContains('recent-2', $ids);
        self::assertNotContains('old-1', $ids, 'trend history older than 2 years must not travel to the model');
        self::assertNotContains('old-2', $ids);
        self::assertContains('med-active', $ids, 'a still-listed medication is kept regardless of age');
    }

    public function testUnderTheCapEverythingRecentSurvives(): void
    {
        $facts = [
            self::trend('a', '%', '2026-01-01', 7.1, 1),
            self::trend('b', '%', '2026-04-01', 7.3, 2),
            self::nonValue('m', 'med_response', 'med_event', '2026-01-01', 9),
        ];

        self::assertCount(3, PromptFactWindow::forChat($facts));
    }

    public function testNarrativeKeepsTheLastTwentyVisitsAndDropsOlder(): void
    {
        // 30 monthly A1c draws (30 distinct visit dates) + a 2020 medication.
        $facts = [];
        $base = new \DateTimeImmutable('2026-06-01');
        for ($i = 0; $i < 30; $i++) {
            $date = $base->modify('-' . $i . ' months')->format('Y-m-d');
            $facts[] = self::trend('a1c-' . $i, '%', $date, 7.0, $i + 1);
        }
        $facts[] = self::nonValue('med', 'med_response', 'med_event', '2020-01-01', 999);

        $windowed = PromptFactWindow::forNarrative($facts, 20);
        $trendCount = count(array_filter($windowed, static fn (Fact $f): bool => $f->kind->value === 'trend_point'));
        $ids = array_map(static fn (Fact $f): string => $f->factId, $windowed);

        self::assertSame(20, $trendCount, 'the narrative keeps the last 20 visits of trend history');
        self::assertContains('a1c-0', $ids, 'the most recent visit is kept');
        self::assertNotContains('a1c-29', $ids, 'the 30th-most-recent visit is dropped');
        self::assertContains('med', $ids, 'a 2020 medication is still kept for the narrative');
    }

    private static function trend(string $id, string $unit, string $date, float $parsed, int $pk): Fact
    {
        return Fact::fromArray([
            'fact_id' => $id,
            'capability' => 'control_proxy',
            'capability_version' => '1',
            'kind' => 'trend_point',
            'pid' => 42,
            'clinical_date' => $date,
            'date_source' => 'collected',
            'value' => ['raw' => (string) $parsed, 'parsed' => $parsed, 'comparator' => 'none', 'unit_original' => $unit, 'unit_canonical' => $unit, 'conversion_version' => null],
            'status' => 'final',
            'flags' => [],
            'citations' => [['table' => 'procedure_result', 'pk' => $pk, 'field' => 'result', 'date_source' => 'collected']],
        ]);
    }

    private static function nonValue(string $id, string $capability, string $kind, string $date, int $pk): Fact
    {
        return Fact::fromArray([
            'fact_id' => $id,
            'capability' => $capability,
            'capability_version' => '1',
            'kind' => $kind,
            'pid' => 42,
            'clinical_date' => $date,
            'date_source' => 'collected',
            'value' => null,
            'status' => 'final',
            'flags' => [],
            'citations' => [['table' => 'procedure_result', 'pk' => $pk, 'field' => null, 'date_source' => 'collected']],
        ]);
    }
}
