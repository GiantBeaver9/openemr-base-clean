<?php

/**
 * Isolated contract eval for the U4 lab slice (ARCHITECTURE_COMPLETE.md C1–C4).
 *
 * Built from FixtureLabRowSource + the real U2 fixtures and cross-checked against
 * tests/Fixtures/expected/landmines.json. Covers every landmine the lab slice owns:
 * comparator censoring (<7.0 not 7.0), supersession (both in-place and new-row variants),
 * cannot-be-done ≠ clock reset, unitless excluded-but-visible, mmol/mol → % conversion
 * (69 → 8.46%), unrecognized-status visible exclusion, late/backdated lab resolving via
 * C1 fallback (date_source=fallback), and the two out-of-range proofs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\ExclusionReason;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Lab\DateResolver;
use OpenEMR\Modules\ClinicalCopilot\Lab\FixtureLabRowSource;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabCadenceConfig;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSlice;
use OpenEMR\Modules\ClinicalCopilot\Lab\StatusResolver;
use OpenEMR\Modules\ClinicalCopilot\Lab\UnitConverter;
use OpenEMR\Modules\ClinicalCopilot\Lab\ValueParser;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

/**
 * Find the fact whose sole citation points at a given procedure_result pk.
 *
 * @param list<Fact> $facts
 */
function cc_lab_fact_for(array $facts, int $resultId): ?Fact
{
    foreach ($facts as $fact) {
        foreach ($fact->citations as $citation) {
            if ($citation->table === 'procedure_result' && $citation->pk === $resultId) {
                return $fact;
            }
        }
    }
    return null;
}

function clinical_copilot_test_LabSliceTest(): void
{
    $fixturesDir = __DIR__ . '/../Fixtures';
    $config = LabCadenceConfig::fromFile($fixturesDir . '/mod_copilot_cadence.json');
    $source = new FixtureLabRowSource($fixturesDir);
    $slice = new LabSlice($source, new UnitConverter($config), $config);

    // Cross-check anchor: the landmine known-answer map.
    $landmines = json_decode((string) file_get_contents($fixturesDir . '/expected/landmines.json'), true);
    Assert::that(is_array($landmines) && isset($landmines['landmines']), 'landmines.json loads');

    // ---- Unit-level resolvers (the pieces the contract is built from) ----

    // C1 date precedence: collected wins; fallback only when both collected dates empty.
    $dr = new DateResolver();
    $collected = $dr->resolve('2025-09-10 08:20:00', '2025-09-10 08:00:00', '2025-09-11 14:00:00', '2025-09-11 14:00:00');
    Assert::equals('2025-09-10', $collected->clinicalDate, 'C1: report.date_collected is authoritative');
    Assert::equals(DateSource::Collected, $collected->source, 'C1: authoritative date_source=collected');
    $fallback = $dr->resolve(null, null, '2026-03-05 09:00:00', '2026-06-25 11:30:00');
    Assert::equals('2026-03-05', $fallback->clinicalDate, 'C1: falls through to result.date when collected dates NULL');
    Assert::equals(DateSource::Fallback, $fallback->source, 'C1: fallback date_source=fallback');

    // C3 value parsing: comparator censoring, numeric type gating, no coercion.
    $vp = new ValueParser();
    $censored = $vp->parse('<7.0', 'N');
    Assert::equals(7.0, $censored->parsed, 'C3: <7.0 retains 7.0 for direction');
    Assert::equals(Comparator::Lt, $censored->comparator, 'C3: <7.0 comparator is lt');
    Assert::that($censored->isCensored(), 'C3: <7.0 is censored');
    Assert::equals(null, $vp->parse('', 'N')->parsed, 'C3: empty string is not coerced to 0');
    Assert::equals(null, $vp->parse('7.5', 'F')->parsed, 'C3: F data type is never numeric');
    Assert::equals(69.0, $vp->parse('69', 'N')->parsed, 'C3: plain integer parses');

    // C4 unit conversion: mmol/mol → % via the whitelist; empty unit → no math.
    $uc = new UnitConverter($config);
    $a1cConv = $uc->convert('4548-4', 69.0, 'mmol/mol');
    Assert::equals(8.46, $a1cConv->canonicalValue, 'C4: 69 mmol/mol → 8.46% NGSP');
    Assert::equals('%', $a1cConv->unitCanonical, 'C4: A1c canonical unit is %');
    Assert::equals('conv:a1c@1', $a1cConv->conversionVersion, 'C4: converted fact carries conversion version');
    $identity = $uc->convert('4548-4', 7.1, '%');
    Assert::equals(7.1, $identity->canonicalValue, 'C4: canonical-unit value passes through');
    Assert::equals(null, $identity->conversionVersion, 'C4: identity pass-through has no conversion version');
    $noUnit = $uc->convert('2345-7', 142.0, '');
    Assert::that($noUnit->isUnitless(), 'C4: empty unit → no unit, no math');

    // C2 status resolver: unperformed vs unrecognized, both exclude, neither resets clock.
    $sr = new StatusResolver();
    Assert::equals(FactStatus::Final, $sr->resolve('final')->status, 'C2: final → final');
    Assert::equals(FactStatus::Unstated, $sr->resolve('')->status, "C2: '' → unstated");
    $cannot = $sr->resolve('cannot be done');
    Assert::that($cannot->isExcluded(), 'C2: cannot be done is excluded');
    Assert::equals(ExclusionReason::UnperformedStatus, $cannot->exclusionReason, 'C2: cannot be done → unperformed');
    Assert::that(!$cannot->resetsOverdueClock(), 'C2: cannot be done does NOT reset the overdue clock');
    $unknown = $sr->resolve('transcribed');
    Assert::equals(ExclusionReason::UnrecognizedStatus, $unknown->exclusionReason, 'C2: transcribed → unrecognized');
    Assert::that(!$unknown->resetsOverdueClock(), 'C2: unrecognized does NOT reset the overdue clock');

    // ---- End-to-end LabSlice over the fixtures, per landmine ----

    $facts9001 = $slice->extract(9001);
    $facts9002 = $slice->extract(9002);
    $facts9003 = $slice->extract(9003);
    $facts9004 = $slice->extract(9004);

    foreach ([$facts9001, $facts9002, $facts9003, $facts9004] as $set) {
        foreach ($set as $fact) {
            Assert::that($fact->citations !== [], 'every emitted fact carries ≥1 citation');
            Assert::equals(Capability::ControlProxy, $fact->capability, 'lab-slice facts are ControlProxy-stamped');
        }
    }

    // L1 rising A1c trend: three trend points, last converted from mmol/mol.
    $t1 = cc_lab_fact_for($facts9001, 6101);
    $t2 = cc_lab_fact_for($facts9001, 6102);
    $t3 = cc_lab_fact_for($facts9001, 6103);
    Assert::equals(FactKind::TrendPoint, $t1?->kind, 'L1: 6101 is a trend point');
    Assert::equals('2025-09-10', $t1?->clinicalDate, 'L1: 6101 clinical_date via C1 collected');
    Assert::equals(7.1, $t1?->value?->parsed, 'L1: 6101 parsed 7.1');
    Assert::equals(7.8, $t2?->value?->parsed, 'L1: 6102 parsed 7.8');
    Assert::equals(8.46, $t3?->value?->parsed, 'L1/L10: 6103 parsed 8.46 (converted)');
    Assert::equals('%', $t3?->value?->unitCanonical, 'L10: 6103 canonical unit %');
    Assert::equals('mmol/mol', $t3?->value?->unitOriginal, 'L10: 6103 original unit preserved');
    Assert::equals('conv:a1c@1', $t3?->value?->conversionVersion, 'L10: 6103 conversion version');
    Assert::that((bool) $t1?->hasFlag(Flag::OUT_OF_RANGE_BY_LAB_FLAG), 'L1: 6101 out_of_range_by_lab_flag (abnormal high + range)');
    Assert::that((bool) $t3?->hasFlag(Flag::OUT_OF_RANGE_BY_LAB_FLAG), 'L1: 6103 out_of_range_by_lab_flag');

    // L4 late/backdated lab: clinical_date via C1 fallback.
    $late = cc_lab_fact_for($facts9004, 6402);
    Assert::equals('2026-03-05', $late?->clinicalDate, 'L4: backdated clinical_date = result.date');
    Assert::equals(DateSource::Fallback, $late?->dateSource, 'L4: date_source=fallback');
    Assert::equals(7.9, $late?->value?->parsed, 'L4: parsed 7.9');
    foreach (($late?->citations ?? []) as $c) {
        Assert::equals(DateSource::Fallback, $c->dateSource, 'L4: citation carries fallback date_source');
    }

    // L5 corrected in-place: single row, status corrected, NO superseded sibling.
    $inPlace = cc_lab_fact_for($facts9004, 6401);
    Assert::equals(FactKind::TrendPoint, $inPlace?->kind, 'L5: 6401 presented as a trend point');
    Assert::equals(FactStatus::Corrected, $inPlace?->status, 'L5: 6401 status corrected');
    Assert::equals(7.4, $inPlace?->value?->parsed, 'L5: 6401 parsed 7.4');
    Assert::that(!(bool) $inPlace?->hasFlag(Flag::superseded(1)), 'L5: in-place correction has NO superseded_1 flag (no sibling)');

    // L6 corrected new-row: 6203 corrected wins, 6202 becomes a visible superseded exclusion.
    $winner = cc_lab_fact_for($facts9002, 6203);
    $loser = cc_lab_fact_for($facts9002, 6202);
    Assert::equals(FactKind::TrendPoint, $winner?->kind, 'L6: 6203 wins, presented as trend point');
    Assert::equals(FactStatus::Corrected, $winner?->status, 'L6: winner status corrected');
    Assert::equals(7.6, $winner?->value?->parsed, 'L6: winner parsed 7.6');
    Assert::that((bool) $winner?->hasFlag(Flag::superseded(1)), 'L6: winner flags supersedes 1 prior');
    Assert::equals(FactKind::Exclusion, $loser?->kind, 'L6: 6202 becomes a visible exclusion');
    Assert::that((bool) $loser?->isExclusion(), 'L6: 6202 is an exclusion');
    Assert::that((bool) $loser?->hasFlag(Flag::superseded(1)), 'L6: loser carries superseded_1');
    Assert::that((bool) $loser?->hasFlag(Flag::excludedReason(ExclusionReason::Superseded)), 'L6: loser excluded_reason:superseded');

    // L8 censored value: comparator lt, censored flag, presented, no exact numeric claim.
    $cens = cc_lab_fact_for($facts9003, 6301);
    Assert::equals(Comparator::Lt, $cens?->value?->comparator, 'L8: comparator lt');
    Assert::that((bool) $cens?->hasFlag(Flag::CENSORED), 'L8: censored flag set');
    Assert::equals(FactKind::TrendPoint, $cens?->kind, 'L8: censored value still presented');
    Assert::that(!(bool) $cens?->hasFlag(Flag::OUT_OF_RANGE_BY_VALUE), 'L8: no by-value proof on a censored value');

    // L9 unitless value: excluded but VISIBLE, reason no_unit.
    $unitless = cc_lab_fact_for($facts9003, 6302);
    Assert::equals(FactKind::Exclusion, $unitless?->kind, 'L9: unitless is an exclusion');
    Assert::equals(FactStatus::Excluded, $unitless?->status, 'L9: unitless status excluded');
    Assert::that((bool) $unitless?->hasFlag(Flag::excludedReason(ExclusionReason::NoUnit)), 'L9: excluded_reason:no_unit');
    Assert::equals('142', $unitless?->value?->raw, 'L9: raw value shown verbatim (visible)');
    Assert::equals(null, $unitless?->value?->unitCanonical, 'L9: no canonical unit (excluded from math)');

    // L11 unrecognized status: excluded, visible, reason unrecognized_status.
    $unrec = cc_lab_fact_for($facts9003, 6304);
    Assert::equals(FactKind::Exclusion, $unrec?->kind, 'L11: transcribed → exclusion');
    Assert::that((bool) $unrec?->hasFlag(Flag::excludedReason(ExclusionReason::UnrecognizedStatus)), 'L11: excluded_reason:unrecognized_status');

    // L12 preliminary: in-flight, NOT a trend point, does not reset the clock.
    $prelim = cc_lab_fact_for($facts9003, 6303);
    Assert::equals(FactKind::PreliminaryResult, $prelim?->kind, 'L12: preliminary_result kind');
    Assert::equals(FactStatus::Preliminary, $prelim?->status, 'L12: status preliminary');
    Assert::equals(8.1, $prelim?->value?->parsed, 'L12: parsed 8.1');
    Assert::that($prelim?->kind !== FactKind::TrendPoint, 'L12: preliminary is not a trend point');

    // I5 — no silent exclusion: every excluded row surfaced; unitless counted.
    $counts = LabSlice::excludedCountsByReason($facts9003);
    Assert::equals(1, $counts['no_unit'] ?? 0, 'I5: unitless exclusion counted (rate input)');
    Assert::equals(1, $counts['unrecognized_status'] ?? 0, 'I5: unrecognized exclusion counted');

    // Cross-check a couple of values directly against the landmine known-answers.
    $lm = $landmines['landmines'];
    Assert::equals($lm['L8_censored_value_lt7']['expected']['comparator'], $cens?->value?->comparator->value, 'cross-check: L8 comparator matches landmines.json');
    Assert::equals($lm['L10_mmol_mol_a1c']['expected']['parsed_canonical'], $t3?->value?->parsed, 'cross-check: L10 parsed_canonical matches landmines.json');
    Assert::equals($lm['L6_corrected_lab_new_row']['expected']['winner_result_id'], $winner !== null ? 6203 : 0, 'cross-check: L6 winner is 6203');
}
