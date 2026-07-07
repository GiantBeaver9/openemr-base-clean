<?php

/**
 * Isolated known-answer eval for the U5 capabilities (ARCHITECTURE_COMPLETE.md Capabilities;
 * USERS.md UC1–UC6). Built from the U2 FixtureReaders + fixtures and cross-checked against
 * tests/Fixtures/expected/landmines.json. Covers, per capability:
 *   - ControlProxy: rising A1c trend + derived_delta (+1.36, rising) citing its raw rows
 *     [6101, 6103]; derived_count/derived_span; censored draws excluded from derived math.
 *   - OverdueTests: ACR overdue for 9002 and NOT reorder-suppressed (its pending order is an
 *     A1c, not an ACR); positive suppression when a same-code pending order proves it.
 *   - PendingResults: pending_order for the drawn-but-unresulted 4203; expected_result_date
 *     2026-07-04 derived from turnaround:a1c; preliminary surfaced, never a trend point,
 *     never resets the overdue clock (T10).
 *   - MedResponse: metformin duplicate reconciled across prescriptions+lists with a visible
 *     exclusion; single-source and lists-only meds surfaced; paired with the A1c trend citing
 *     both sides; never asserts causation.
 *   - VitalsTrend: weight/BP/BMI facts whose values exist in the cited form_vitals row.
 *   - DerivedFacts: deterministic builders that cite their raw facts.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Capability\AnalyteTrendIndex;
use OpenEMR\Modules\ClinicalCopilot\Capability\CadenceConfig;
use OpenEMR\Modules\ClinicalCopilot\Capability\ControlProxy;
use OpenEMR\Modules\ClinicalCopilot\Capability\DerivedFacts;
use OpenEMR\Modules\ClinicalCopilot\Capability\FixtureMedReader;
use OpenEMR\Modules\ClinicalCopilot\Capability\FixturePendingOrderSource;
use OpenEMR\Modules\ClinicalCopilot\Capability\FixtureVitalsReader;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedReader;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedRecord;
use OpenEMR\Modules\ClinicalCopilot\Capability\MedResponse;
use OpenEMR\Modules\ClinicalCopilot\Capability\OverdueTests;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingOrder;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingOrderSource;
use OpenEMR\Modules\ClinicalCopilot\Capability\PendingResults;
use OpenEMR\Modules\ClinicalCopilot\Capability\VitalsTrend;
use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Lab\FixtureLabRowSource;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabCadenceConfig;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSlice;
use OpenEMR\Modules\ClinicalCopilot\Lab\UnitConverter;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

/**
 * @param list<Fact> $facts
 * @return list<Fact>
 */
function cc_cap_where(array $facts, callable $pred): array
{
    return array_values(array_filter($facts, $pred));
}

/**
 * @param list<Fact> $facts
 */
function cc_cap_first(array $facts, callable $pred): ?Fact
{
    foreach ($facts as $fact) {
        if ($pred($fact)) {
            return $fact;
        }
    }
    return null;
}

function cc_cap_cites(Fact $fact, string $table, int $pk): bool
{
    foreach ($fact->citations as $citation) {
        if ($citation->table === $table && $citation->pk === $pk) {
            return true;
        }
    }
    return false;
}

/**
 * @return list<int>
 */
function cc_cap_result_pks(Fact $fact): array
{
    $pks = [];
    foreach ($fact->citations as $citation) {
        if ($citation->table === 'procedure_result') {
            $pks[] = $citation->pk;
        }
    }
    sort($pks);
    return $pks;
}

function clinical_copilot_test_CapabilitiesTest(): void
{
    $fixturesDir = __DIR__ . '/../Fixtures';
    $labConfig = LabCadenceConfig::fromFile($fixturesDir . '/mod_copilot_cadence.json');
    $cadence = CadenceConfig::fromFile($fixturesDir . '/mod_copilot_cadence.json');
    $labSource = new FixtureLabRowSource($fixturesDir);
    $slice = new LabSlice($labSource, new UnitConverter($labConfig), $labConfig);

    $landmines = json_decode((string) file_get_contents($fixturesDir . '/expected/landmines.json'), true);
    Assert::that(is_array($landmines) && isset($landmines['landmines']), 'landmines.json loads');
    $lm = $landmines['landmines'];

    // ---- CadenceConfig: the versioned config the capabilities compute from ----
    Assert::equals(365, $cadence->intervalDays('acr'), 'cadence: ACR interval 365 days');
    Assert::equals(180, $cadence->intervalDays('a1c'), 'cadence: A1c interval 180 days');
    Assert::equals(2, $cadence->turnaroundDays('a1c'), 'cadence: A1c turnaround 2 days');
    Assert::equals('a1c', $cadence->analyteForLoinc('4548-4'), 'cadence: 4548-4 → a1c');
    Assert::equals('acr', $cadence->analyteForLoinc('9318-7'), 'cadence: 9318-7 → acr');

    // =====================================================================
    // ControlProxy (UC1, UC2) — L1 rising A1c trend + derived facts.
    // =====================================================================
    $controlProxy = new ControlProxy($slice, $labSource, $cadence);
    $cp9001 = $controlProxy->forPatient(9001);

    Assert::equals('control_proxy@1', $controlProxy->version(), 'ControlProxy version string');

    // Slice trend points are surfaced unchanged.
    $t1 = cc_cap_first($cp9001, static fn(Fact $f): bool => $f->kind === FactKind::TrendPoint && cc_cap_cites($f, 'procedure_result', 6101));
    $t3 = cc_cap_first($cp9001, static fn(Fact $f): bool => $f->kind === FactKind::TrendPoint && cc_cap_cites($f, 'procedure_result', 6103));
    Assert::equals(7.1, $t1?->value?->parsed, 'ControlProxy surfaces trend point 6101 (7.1)');
    Assert::equals(8.46, $t3?->value?->parsed, 'ControlProxy surfaces trend point 6103 (8.46 converted)');
    Assert::equals(Capability::ControlProxy, $t1?->capability, 'trend points are ControlProxy-stamped');

    // derived_delta: overall first→last, rising, citing exactly the raw rows [6101, 6103].
    $delta = cc_cap_first($cp9001, static fn(Fact $f): bool => $f->kind === FactKind::DerivedDelta && $f->hasFlag('analyte:a1c'));
    Assert::that($delta !== null, 'ControlProxy adds a derived_delta for the A1c trend');
    Assert::equals(1.36, $delta?->value?->parsed, 'L1: derived_delta = +1.36 (deterministic)');
    Assert::equals($lm['L1_rising_a1c_trend']['expected']['derived_delta']['delta'], $delta?->value?->parsed, 'cross-check: L1 delta matches landmines.json');
    Assert::that((bool) $delta?->hasFlag('direction:rising'), 'L1: derived_delta direction rising');
    Assert::equals([6101, 6103], $delta !== null ? cc_cap_result_pks($delta) : [], 'L1: derived_delta cites its raw rows [6101, 6103] (V4)');

    // derived_count over the three quantitative draws; derived_span present.
    $count = cc_cap_first($cp9001, static fn(Fact $f): bool => $f->kind === FactKind::DerivedCount && $f->hasFlag('analyte:a1c'));
    Assert::equals(3.0, $count?->value?->parsed, 'ControlProxy derived_count = 3 A1c draws');
    Assert::equals([6101, 6102, 6103], $count !== null ? cc_cap_result_pks($count) : [], 'derived_count cites every raw draw');
    $span = cc_cap_first($cp9001, static fn(Fact $f): bool => $f->kind === FactKind::DerivedSpan && $f->hasFlag('analyte:a1c'));
    Assert::that($span !== null, 'ControlProxy adds a derived_span');
    Assert::equals([6101, 6103], $span !== null ? cc_cap_result_pks($span) : [], 'derived_span cites first and last raw draw');

    // Censored/insufficient trend ⇒ NO derived delta (a <7.0 proves a direction, not a number).
    $cp9003 = $controlProxy->forPatient(9003);
    $deltas9003 = cc_cap_where($cp9003, static fn(Fact $f): bool => $f->kind === FactKind::DerivedDelta && $f->hasFlag('analyte:a1c'));
    Assert::equals(0, count($deltas9003), 'L8: a lone censored A1c yields no derived_delta (no exact math on censored)');

    // =====================================================================
    // PendingResults + OverdueTests plumbing (shared pending-order source).
    // procedure_order_code is not seeded by U2, so inject the ordered LOINCs
    // (this stands in for the production procedure_order_code join).
    // =====================================================================
    $pending = new FixturePendingOrderSource($fixturesDir, [4203 => '4548-4', 4303 => '4548-4']);
    $asOf = new \DateTimeImmutable('2026-07-07'); // fixtures README: today for overdue math

    // ---- OverdueTests (UC1, UC4) — L3 overdue ACR, NOT reorder-suppressed. ----
    $overdue = new OverdueTests($slice, $labSource, $cadence, $pending, $asOf);
    $ov9002 = $overdue->forPatient(9002);

    $acrOverdue = cc_cap_first($ov9002, static fn(Fact $f): bool => $f->kind === FactKind::OverdueItem && $f->hasFlag('analyte:acr'));
    Assert::that($acrOverdue !== null, 'L3: ACR flagged overdue for 9002');
    Assert::equals('2024-11-15', $acrOverdue?->clinicalDate, 'L3: overdue anchored to last ACR draw 2024-11-15');
    Assert::that((bool) cc_cap_cites($acrOverdue ?? $ov9002[0], 'procedure_result', 6201), 'L3: overdue_item cites the last-draw row 6201');
    Assert::that(($acrOverdue?->value?->parsed ?? 0.0) > 0.0, 'L3: overdue_item carries a positive days-overdue number');
    Assert::that(!(bool) $acrOverdue?->hasFlag(OverdueTests::FLAG_REORDER_SUPPRESSED), 'L3: reorder NOT suppressed (9002 pending order is A1c, not ACR)');
    Assert::equals($lm['L3_overdue_urine_acr']['expected']['reorder_note_suppressed'], (bool) $acrOverdue?->hasFlag(OverdueTests::FLAG_REORDER_SUPPRESSED), 'cross-check: L3 reorder_note_suppressed=false');

    // A1c is NOT overdue for 9002 (last draw 2026-05-02 + 180d > as-of).
    $a1cOverdue = cc_cap_first($ov9002, static fn(Fact $f): bool => $f->kind === FactKind::OverdueItem && $f->hasFlag('analyte:a1c'));
    Assert::that($a1cOverdue === null, 'L3: A1c not overdue for 9002');

    // Positive composition: a SAME-code pending order suppresses the reorder note.
    $acrPending = new class implements PendingOrderSource {
        public function pendingOrders(int $pid): array
        {
            return [new PendingOrder(4299, '9318-7', 'routed', '2026-07-01', false)];
        }
    };
    $overdueSuppressed = new OverdueTests($slice, $labSource, $cadence, $acrPending, $asOf);
    $acrSuppressed = cc_cap_first($overdueSuppressed->forPatient(9002), static fn(Fact $f): bool => $f->kind === FactKind::OverdueItem && $f->hasFlag('analyte:acr'));
    Assert::that((bool) $acrSuppressed?->hasFlag(OverdueTests::FLAG_REORDER_SUPPRESSED), 'reorder suppressed ONLY when a same-code pending order proves it');

    // ---- PendingResults (UC1, UC4, UC5) — L7 drawn-but-unresulted + expected date. ----
    $pendingResults = new PendingResults($pending, $slice, $cadence);
    $pr9002 = $pendingResults->forPatient(9002);

    $pendingOrderFact = cc_cap_first($pr9002, static fn(Fact $f): bool => $f->kind === FactKind::PendingOrder && cc_cap_cites($f, 'procedure_order', 4203));
    Assert::that($pendingOrderFact !== null, 'L7: pending_order emitted for the drawn-but-unresulted order 4203');
    Assert::equals(Capability::PendingResults, $pendingOrderFact?->capability, 'L7: pending_order is PendingResults-stamped');
    Assert::that((bool) $pendingOrderFact?->hasFlag('order_status:routed'), 'L7: order_status routed');

    $expected = cc_cap_first($pr9002, static fn(Fact $f): bool => $f->kind === FactKind::ExpectedResultDate && cc_cap_cites($f, 'procedure_order', 4203));
    Assert::that($expected !== null, 'L7: expected_result_date derived for the pending order');
    Assert::equals('2026-07-04', $expected?->value?->raw, 'L7: expected_result_date = 2026-07-04 (collection 2026-07-02 + turnaround:a1c 2d)');
    Assert::equals($lm['L7_drawn_but_unresulted']['expected']['expected_result_date']['value'], $expected?->value?->raw, 'cross-check: L7 expected_result_date matches landmines.json');

    // T10 — pending draws and preliminary results never reset the overdue clock.
    $idx9002 = AnalyteTrendIndex::build($slice->extract(9002), AnalyteTrendIndex::analyteMap($labSource, $cadence, 9002));
    Assert::equals('2026-05-02', $idx9002->lastDrawDate('a1c'), 'T10: 9002 A1c clock = last RESULTED draw 2026-05-02 (pending 2026-07-02 does not count)');

    // L12 — preliminary surfaced by PendingResults, never a trend point, never resets clock.
    $pr9003 = $pendingResults->forPatient(9003);
    $prelim = cc_cap_first($pr9003, static fn(Fact $f): bool => $f->kind === FactKind::PreliminaryResult && cc_cap_cites($f, 'procedure_result', 6303));
    Assert::that($prelim !== null, 'L12: preliminary A1c surfaced in the in-flight section');
    Assert::equals(FactStatus::Preliminary, $prelim?->status, 'L12: status preliminary');
    Assert::equals(8.1, $prelim?->value?->parsed, 'L12: preliminary parsed 8.1');
    Assert::that($prelim?->kind !== FactKind::TrendPoint, 'L12: preliminary is not a trend point');
    $idx9003 = AnalyteTrendIndex::build($slice->extract(9003), AnalyteTrendIndex::analyteMap($labSource, $cadence, 9003));
    Assert::equals('2026-04-10', $idx9003->lastDrawDate('a1c'), 'L12/T10: 9003 A1c clock ignores the 2026-07-01 preliminary');

    // =====================================================================
    // MedResponse (UC1, UC3) — L2 metformin dedup + paired A1c trend, no causation.
    // =====================================================================
    $medReader = new FixtureMedReader($fixturesDir);
    $medResponse = new MedResponse($medReader, $controlProxy);
    $med9001 = $medResponse->forPatient(9001);

    $metformin = cc_cap_first($med9001, static fn(Fact $f): bool => $f->kind === FactKind::MedEvent && $f->hasFlag('drug:metformin'));
    Assert::that($metformin !== null, 'L2: metformin med_event present');
    $metforminEvents = cc_cap_where($med9001, static fn(Fact $f): bool => $f->kind === FactKind::MedEvent && $f->hasFlag('drug:metformin'));
    Assert::equals(1, count($metforminEvents), 'L2: metformin de-duplicated to a single med_event');
    Assert::that((bool) cc_cap_cites($metformin ?? $med9001[0], 'prescriptions', 7101), 'L2/T4: med_event cites the prescriptions source');
    Assert::that((bool) cc_cap_cites($metformin ?? $med9001[0], 'lists', 8101), 'L2/T4: med_event cites the lists source (both sources)');
    Assert::that((bool) $metformin?->hasFlag('dose_stable'), 'L2: metformin dose stable (single dose on record)');
    Assert::that((bool) $metformin?->hasFlag('lab_trend:a1c:rising'), 'L2: paired with the rising A1c trend');
    Assert::that((bool) cc_cap_cites($metformin ?? $med9001[0], 'procedure_result', 6101), 'L2: paired fact cites the lab side (6101)');
    Assert::that((bool) cc_cap_cites($metformin ?? $med9001[0], 'procedure_result', 6103), 'L2: paired fact cites the lab side (6103)');

    // Visible exclusion for the dropped cross-table duplicate (I5).
    $dupExclusion = cc_cap_first($med9001, static fn(Fact $f): bool => $f->kind === FactKind::Exclusion && $f->hasFlag('duplicate_med') && cc_cap_cites($f, 'lists', 8101));
    Assert::that($dupExclusion !== null, 'L2/I5: dropped metformin duplicate surfaced as a visible exclusion');
    Assert::equals(FactStatus::Excluded, $dupExclusion?->status, 'L2: duplicate exclusion status excluded');

    // Never asserts causation — no fact carries any causal token.
    $causal = cc_cap_first($med9001, static function (Fact $f): bool {
        foreach ($f->flags as $flag) {
            if (str_contains($flag, 'caus')) {
                return true;
            }
        }
        return false;
    });
    Assert::that($causal === null, 'UC3: MedResponse never asserts causation');
    Assert::equals($lm['L2_med_dose_a1c_mismatch']['expected']['asserts_causation'], $causal !== null, 'cross-check: L2 asserts_causation=false');

    // Single-source med (prescriptions-only) surfaced, no duplicate exclusion.
    $med9003 = $medResponse->forPatient(9003);
    $metformin9003 = cc_cap_first($med9003, static fn(Fact $f): bool => $f->kind === FactKind::MedEvent && cc_cap_cites($f, 'prescriptions', 7301));
    Assert::that($metformin9003 !== null, 'prescriptions-only metformin (7301) surfaced');
    $dup9003 = cc_cap_where($med9003, static fn(Fact $f): bool => $f->kind === FactKind::Exclusion && $f->hasFlag('duplicate_med'));
    Assert::equals(0, count($dup9003), 'no duplicate exclusion when the med is in a single table');

    // Lists-only (outside) med is surfaced, never dropped (T4).
    $listsOnlyReader = new class implements MedReader {
        public function readMeds(int $pid): array
        {
            return [new MedRecord('lists', 8888, 'Empagliflozin', '', '2025-03-01', true)];
        }
    };
    $listsOnly = (new MedResponse($listsOnlyReader, $controlProxy))->forPatient(9001);
    $outsideMed = cc_cap_first($listsOnly, static fn(Fact $f): bool => $f->kind === FactKind::MedEvent && cc_cap_cites($f, 'lists', 8888));
    Assert::that($outsideMed !== null, 'T4: a med present only in lists is surfaced');

    // =====================================================================
    // VitalsTrend (UC1, UC3) — a flagged value must exist in the row.
    // =====================================================================
    $vitals = (new VitalsTrend(new FixtureVitalsReader($fixturesDir)))->forPatient(9001);
    $weight = cc_cap_first($vitals, static fn(Fact $f): bool => $f->kind === FactKind::Vital && $f->hasFlag('measure:weight') && cc_cap_cites($f, 'form_vitals', 9101));
    Assert::equals('210.0', $weight?->value?->raw, 'VitalsTrend: weight value read verbatim from row 9101');
    Assert::equals(210.0, $weight?->value?->parsed, 'VitalsTrend: weight parsed');
    Assert::equals(Capability::VitalsTrend, $weight?->capability, 'vital facts are VitalsTrend-stamped');
    $bp = cc_cap_first($vitals, static fn(Fact $f): bool => $f->kind === FactKind::Vital && $f->hasFlag('measure:bp') && cc_cap_cites($f, 'form_vitals', 9101));
    Assert::equals('138/84', $bp?->value?->raw, 'VitalsTrend: BP reading exists in the row');
    // Cross-check the invariant directly against the fixture row (no fabrication).
    $vitalsRows = json_decode((string) file_get_contents($fixturesDir . '/form_vitals.json'), true);
    $row9101 = cc_cap_first_row($vitalsRows, 9101);
    Assert::equals($row9101['weight'] ?? null, $weight?->value?->raw, 'VitalsTrend: emitted weight equals the chart row value');

    // =====================================================================
    // DerivedFacts — builder proven directly (cites its raw facts / anchor).
    // =====================================================================
    $derived = new DerivedFacts();
    $orderCitation = new \OpenEMR\Modules\ClinicalCopilot\Fact\Citation('procedure_order', 4203, 'date_collected', \OpenEMR\Modules\ClinicalCopilot\Fact\DateSource::Collected);
    $exp = $derived->expectedResultDate(9002, '2026-07-02', 2, 'turnaround:a1c@cadence@1', $orderCitation, Capability::PendingResults, 'pending_results@1');
    Assert::equals('2026-07-04', $exp?->value?->raw, 'DerivedFacts::expectedResultDate = collection + turnaround days');
    Assert::equals(FactKind::ExpectedResultDate, $exp?->kind, 'DerivedFacts::expectedResultDate kind');
    Assert::that((bool) cc_cap_cites($exp ?? $orderFactStub(), 'procedure_order', 4203), 'DerivedFacts::expectedResultDate cites its anchoring order');
}

/**
 * @param mixed $rows
 * @return array<string, mixed>
 */
function cc_cap_first_row($rows, int $id): array
{
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (is_array($row) && ($row['id'] ?? null) === $id) {
                return $row;
            }
        }
    }
    return [];
}

/**
 * Non-null Fact stub so a failed builder call cannot fatal the cite assertion above.
 */
function orderFactStub(): Fact
{
    return new Fact(
        Capability::PendingResults,
        'pending_results@1',
        FactKind::PendingOrder,
        0,
        null,
        \OpenEMR\Modules\ClinicalCopilot\Fact\DateSource::Collected,
        null,
        FactStatus::Unstated,
        [],
        [new \OpenEMR\Modules\ClinicalCopilot\Fact\Citation('none', 0, null, \OpenEMR\Modules\ClinicalCopilot\Fact\DateSource::Collected)],
    );
}
