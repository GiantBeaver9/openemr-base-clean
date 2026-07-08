<?php

/**
 * PromptFactWindow: caps dense series to a recent slice, keeps sparse facts whole.
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
    public function testDenseSeriesAreCappedPerCapabilityUnitAndSparseFactsKeptWhole(): void
    {
        $facts = [];
        // 30 A1c (%) trend points + 30 glucose (mg/dL) trend points -- two
        // distinct series that must NOT share one cap.
        for ($i = 1; $i <= 30; $i++) {
            $facts[] = self::trend('a1c-' . $i, '%', sprintf('2020-%02d-01', ($i % 12) + 1), 7.0, $i);
            $facts[] = self::trend('glu-' . $i, 'mg/dL', sprintf('2019-%02d-01', ($i % 12) + 1), 120.0, 1000 + $i);
        }
        // Sparse, decision-bearing facts: always kept.
        $facts[] = self::nonValue('med-1', 'med_response', 'med_event', 2001);
        $facts[] = self::nonValue('med-2', 'med_response', 'med_event', 2002);
        $facts[] = self::nonValue('od-1', 'overdue_tests', 'overdue_item', 3001);

        $windowed = PromptFactWindow::forPrompt($facts, 15);

        $byKind = [];
        $byUnit = [];
        foreach ($windowed as $f) {
            $byKind[$f->kind->value] = ($byKind[$f->kind->value] ?? 0) + 1;
            $unit = $f->value?->unitCanonical ?? '';
            if ($f->kind->value === 'trend_point') {
                $byUnit[$unit] = ($byUnit[$unit] ?? 0) + 1;
            }
        }

        self::assertSame(15, $byUnit['%'], 'A1c trend capped to 15');
        self::assertSame(15, $byUnit['mg/dL'], 'glucose trend capped to 15, independent of A1c');
        self::assertSame(2, $byKind['med_event'], 'medications are sparse and all kept');
        self::assertSame(1, $byKind['overdue_item'], 'overdue items are sparse and all kept');
    }

    public function testUnderTheCapEverythingSurvives(): void
    {
        $facts = [
            self::trend('a', '%', '2026-01-01', 7.1, 1),
            self::trend('b', '%', '2026-04-01', 7.3, 2),
            self::nonValue('m', 'med_response', 'med_event', 9),
        ];

        self::assertCount(3, PromptFactWindow::forPrompt($facts, 15));
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

    private static function nonValue(string $id, string $capability, string $kind, int $pk): Fact
    {
        return Fact::fromArray([
            'fact_id' => $id,
            'capability' => $capability,
            'capability_version' => '1',
            'kind' => $kind,
            'pid' => 42,
            'clinical_date' => '2026-01-01',
            'date_source' => 'collected',
            'value' => null,
            'status' => 'final',
            'flags' => [],
            'citations' => [['table' => 'procedure_result', 'pk' => $pk, 'field' => null, 'date_source' => 'collected']],
        ]);
    }
}
