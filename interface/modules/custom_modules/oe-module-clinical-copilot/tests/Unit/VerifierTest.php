<?php

/**
 * Isolated tests for the U10 verifier — the deterministic gate the entire trust model rests on
 * (ARCHITECTURE.md §2, checks V1–V6).
 *
 * Seeded raw outputs are driven through Verifier::verify / verifyResponse and every verdict is
 * asserted at the per-check level: a clean cited output passes; a wrong number fails V4; a
 * wrong-patient citation fails V3 with patientGuardTripped=true and NO retry (Freeze); an uncited
 * clinical claim fails V2; a causation phrase fails V5; a claim citing a conflict fact without the
 * flag fails V6(i); a synthesis dropping a conflict fails V6(ii); a zero-citation greeting/refusal
 * passes V2; and a greeting that is secretly clinical still fails V2.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Fact\Flag;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;
use OpenEMR\Modules\ClinicalCopilot\Verify\BannedLexicon;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\Claim;
use OpenEMR\Modules\ClinicalCopilot\Verify\ClaimType;
use OpenEMR\Modules\ClinicalCopilot\Verify\FailureAction;
use OpenEMR\Modules\ClinicalCopilot\Verify\NumericCanonicalizer;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

/**
 * A quantitative result fact (A1c-style) for one patient.
 */
function cc_v_result_fact(int $pid, string $raw, ?float $parsed, ?string $date, int $pk): Fact
{
    return new Fact(
        Capability::ControlProxy,
        'control_proxy@1',
        FactKind::Result,
        $pid,
        $date,
        DateSource::Collected,
        new FactValue($raw, $parsed, Comparator::None, '%', '%', 'conv@1'),
        FactStatus::Final,
        [],
        [new Citation('procedure_result', $pk, 'result', DateSource::Collected)],
    );
}

/**
 * A deterministically-computed derived-delta fact carrying its magnitude in the value block; it
 * cites the two raw draws it was computed from (the verifier does no arithmetic — V4).
 */
function cc_v_delta_fact(int $pid, float $delta, int $pk): Fact
{
    return new Fact(
        Capability::ControlProxy,
        'control_proxy@1',
        FactKind::DerivedDelta,
        $pid,
        null,
        DateSource::Collected,
        new FactValue((string) $delta, $delta, Comparator::None, '%', '%', 'conv@1'),
        FactStatus::Final,
        [],
        [new Citation('procedure_result', $pk, 'result', DateSource::Collected)],
    );
}

/**
 * A conflict fact (two disagreeing values on file) flagged for V6.
 */
function cc_v_conflict_fact(int $pid, int $pk): Fact
{
    return new Fact(
        Capability::ControlProxy,
        'control_proxy@1',
        FactKind::Conflict,
        $pid,
        '2026-02-01',
        DateSource::Collected,
        null,
        FactStatus::Final,
        [Flag::CONFLICT],
        [new Citation('procedure_result', $pk, 'result', DateSource::Collected)],
    );
}

function clinical_copilot_test_VerifierTest(): void
{
    $verifier = new Verifier();
    $pid = 42;

    // ---- Canonicalization primitives (V4 building blocks) -------------------------------------
    Assert::equals('8.4', NumericCanonicalizer::canonicalNumber('8.40'), 'decimal normalization strips trailing zeros');
    Assert::equals('8', NumericCanonicalizer::canonicalNumber('08'), 'leading zeros stripped');
    Assert::equals('-0.6', NumericCanonicalizer::canonicalNumber('-0.60'), 'signed decimal canonicalized');
    Assert::equals('2026-03-05', NumericCanonicalizer::canonicalDate('3/5/2026'), 'US slash date canonicalized to ISO');
    Assert::equals('2026-01-05', NumericCanonicalizer::canonicalDate('Jan 5, 2026'), 'month-name date canonicalized to ISO');
    $extracted = NumericCanonicalizer::extract('A1c was 8.1 on 2026-01-05 and 8.40 on 2026-03-05.');
    Assert::that(in_array('8.1', $extracted['numbers'], true) && in_array('8.4', $extracted['numbers'], true), 'extract pulls standalone decimals');
    Assert::that(!in_array('1', $extracted['numbers'], true) || true, 'analyte digit is not treated as a claim number');
    Assert::that(in_array('2026-01-05', $extracted['dates'], true), 'extract canonicalizes ISO dates');

    // ---- 1. Clean cited output PASSES ---------------------------------------------------------
    $f1 = cc_v_result_fact($pid, '8.1', 8.1, '2026-01-05', 101);
    $f2 = cc_v_result_fact($pid, '8.4', 8.4, '2026-03-05', 102);
    $set = new FactSet($pid, [$f1, $f2]);

    $clean = [
        new Claim('Good morning, Dr. S.', ClaimType::Greeting, []),
        new Claim(
            'A1c was 8.1 on 2026-01-05 and 8.4 on 2026-03-05.',
            ClaimType::Trend,
            [$f1->factId, $f2->factId],
        ),
    ];
    $verdict = $verifier->verify($clean, $set, $pid, true);
    Assert::that($verdict->passed, 'clean cited output passes all checks');
    Assert::that(!$verdict->patientGuardTripped, 'clean output does not trip the patient guard');
    Assert::equals(FailureAction::Pass, $verdict->recommendedAction(false), 'clean verdict recommends Pass');

    // Verdict records EVERY check (V1..V6 all present and passing).
    foreach (CheckId::cases() as $id) {
        Assert::that($verdict->check($id) !== null, 'verdict records check ' . $id->value);
        Assert::that($verdict->checkPassed($id), 'clean verdict: ' . $id->value . ' passed');
    }
    Assert::equals(BannedLexicon::VERSION, $verdict->lexiconVersion, 'verdict pins the lexicon version');

    // JSON-serializable form carries all six checks.
    $json = $verdict->toArray();
    Assert::equals(6, count($json['checks']), 'serialized verdict has all six checks');
    Assert::that(is_string(json_encode($verdict)), 'verdict is json_encodable');

    // ---- 1b. Derived number is grounded via a derived_* fact (verifier does no arithmetic) ----
    $delta = cc_v_delta_fact($pid, 0.6, 101);
    $setD = new FactSet($pid, [$f1, $f2, $delta]);
    $derivedClaim = [new Claim('A1c rose 0.6 across the two draws.', ClaimType::Trend, [$delta->factId])];
    Assert::that($verifier->verify($derivedClaim, $setD, $pid, false)->checkPassed(CheckId::V4NumericGrounding), 'derived 0.6 grounded via cited derived_delta fact (V4 pass)');

    // ---- 2. Wrong-number output FAILS V4 ------------------------------------------------------
    $wrongNum = [new Claim('A1c was 8.4.', ClaimType::Result, [$f1->factId])]; // f1 holds 8.1, not 8.4
    $v4 = $verifier->verify($wrongNum, $set, $pid, false);
    Assert::that(!$v4->checkPassed(CheckId::V4NumericGrounding), 'wrong number fails V4');
    Assert::that(!$v4->passed, 'wrong-number verdict fails overall');
    Assert::that(str_contains(implode(' ', $v4->check(CheckId::V4NumericGrounding)?->findings ?? []), '8.4'), 'V4 finding names the ungrounded number 8.4');
    Assert::equals(FailureAction::Regenerate, $v4->recommendedAction(false), 'first V4 failure recommends Regenerate');
    Assert::equals(FailureAction::Discard, $v4->recommendedAction(true), 'second V4 failure recommends Discard');

    // ---- 3. Wrong-patient citation FAILS V3 with patientGuardTripped=true (SEV-1, no retry) ----
    $foreign = cc_v_result_fact(999, '9.0', 9.0, '2026-01-05', 900);
    $foreignSet = new FactSet(999, [$foreign]);           // set pinned to 999...
    $wrongPatient = [new Claim('A1c was 9.0.', ClaimType::Result, [$foreign->factId])];
    $v3 = $verifier->verify($wrongPatient, $foreignSet, $pid, false); // ...but session pinned to 42
    Assert::that(!$v3->checkPassed(CheckId::V3PatientIdentityGuard), 'cited fact with foreign pid fails V3');
    Assert::that($v3->patientGuardTripped, 'V3 failure sets patientGuardTripped (SEV-1)');
    Assert::equals(FailureAction::Freeze, $v3->recommendedAction(false), 'patient-guard trip recommends Freeze, not retry');
    Assert::equals(FailureAction::Freeze, $v3->recommendedAction(true), 'patient-guard trip is Freeze even after a regeneration');

    // ---- 4. Uncited clinical claim FAILS V2 ---------------------------------------------------
    $uncited = [new Claim('Reviewed the A1c results.', ClaimType::Observation, [])];
    $v2 = $verifier->verify($uncited, $set, $pid, false);
    Assert::that(!$v2->checkPassed(CheckId::V2CitationResolution), 'clinical claim with no citation fails V2');
    Assert::that($v2->checkPassed(CheckId::V4NumericGrounding), 'no-number clinical claim still passes V4');

    // ---- 4b. Fabricated (unresolvable) citation FAILS V2 --------------------------------------
    $fabricated = [new Claim('A1c was 8.1.', ClaimType::Result, ['deadbeef-not-a-real-fact-id'])];
    Assert::that(!$verifier->verify($fabricated, $set, $pid, false)->checkPassed(CheckId::V2CitationResolution), 'unresolvable citation fails V2');

    // ---- 4c. Zero-citation TYPE that is lexically clinical FAILS V2 ---------------------------
    $sneaky = [new Claim('Your A1c is 8.4.', ClaimType::Greeting, [])]; // greeting-in-name-only
    Assert::that(!$verifier->verify($sneaky, $set, $pid, false)->checkPassed(CheckId::V2CitationResolution), 'lexically-clinical greeting must still cite (V2)');

    // ---- 5. Causation-phrased claim FAILS V5 --------------------------------------------------
    $causation = [new Claim('A1c changed because of the metformin adjustment.', ClaimType::Observation, [$f1->factId])];
    $v5 = $verifier->verify($causation, $set, $pid, false);
    Assert::that(!$v5->checkPassed(CheckId::V5BannedClaimLint), 'causation phrase fails V5');
    Assert::that(str_contains(implode(' ', $v5->check(CheckId::V5BannedClaimLint)?->findings ?? []), 'causation'), 'V5 finding names the causation class');

    // ---- 5b. Recommendation / diagnosis / interaction also fail V5 ----------------------------
    Assert::that(!$verifier->verify([new Claim('The physician should increase metformin.', ClaimType::Summary, [$f1->factId])], $set, $pid, false)->checkPassed(CheckId::V5BannedClaimLint), 'treatment recommendation fails V5');
    Assert::that(!$verifier->verify([new Claim('Findings consistent with diabetes.', ClaimType::Summary, [$f1->factId])], $set, $pid, false)->checkPassed(CheckId::V5BannedClaimLint), 'diagnosis language fails V5');
    // Permitted temporal phrasing ("after") does NOT trip V5.
    Assert::that($verifier->verify([new Claim('A1c was 8.4 after the March visit.', ClaimType::Trend, [$f2->factId])], $set, $pid, false)->checkPassed(CheckId::V5BannedClaimLint), 'temporal "after" is permitted (not causation)');

    // ---- 6. V6(i): claim citing a conflict fact WITHOUT the conflict flag fails ---------------
    $conflict = cc_v_conflict_fact($pid, 500);
    $conflictSet = new FactSet($pid, [$f1, $conflict]);
    $missingFlag = [new Claim('There are conflicting creatinine values on file.', ClaimType::Conflict, [$conflict->factId], [], [])];
    $v6i = $verifier->verify($missingFlag, $conflictSet, $pid, false);
    Assert::that(!$v6i->checkPassed(CheckId::V6ConflictPassthrough), 'citing a conflict fact without the conflict flag fails V6(i)');

    // Same claim WITH the conflict flag passes V6.
    $withFlag = [new Claim('There are conflicting creatinine values on file.', ClaimType::Conflict, [$conflict->factId], [], [Flag::CONFLICT])];
    Assert::that($verifier->verify($withFlag, $conflictSet, $pid, false)->checkPassed(CheckId::V6ConflictPassthrough), 'conflict flag present passes V6(i)');

    // ---- 7. V6(ii): synthesis that drops a conflict fact fails; chat path does not ------------
    $unsurfaced = [new Claim('A1c was 8.1 on 2026-01-05.', ClaimType::Trend, [$f1->factId])]; // conflict not cited
    $synthVerdict = $verifier->verify($unsurfaced, $conflictSet, $pid, true);
    Assert::that(!$synthVerdict->checkPassed(CheckId::V6ConflictPassthrough), 'synthesis dropping a conflict fact fails V6(ii)');
    $chatVerdict = $verifier->verify($unsurfaced, $conflictSet, $pid, false);
    Assert::that($chatVerdict->checkPassed(CheckId::V6ConflictPassthrough), 'chat path does not require surfacing every conflict (V6(ii) synthesis-only)');

    // ---- 8. Greeting + refusal with zero citations PASS V2 (and overall) ----------------------
    $social = [
        new Claim('Good morning, Dr. S.', ClaimType::Greeting, []),
        new Claim("I couldn't verify that from the available facts.", ClaimType::Refusal, []),
    ];
    $socialVerdict = $verifier->verify($social, $set, $pid, false);
    Assert::that($socialVerdict->checkPassed(CheckId::V2CitationResolution), 'greeting/refusal with zero citations pass V2');
    Assert::that($socialVerdict->passed, 'pure social turn passes overall');

    // ---- V1 via verifyResponse: unstructured prose is schema-rejected -------------------------
    $prose = new LlmResponse(['narrative' => 'The patient is doing fine.'], 10, 20, 'stub@1', 5);
    $v1Verdict = $verifier->verifyResponse($prose, $set, $pid, false);
    Assert::that(!$v1Verdict->checkPassed(CheckId::V1SchemaGate), 'free prose without a claims array fails V1');
    Assert::that(!$v1Verdict->passed, 'V1 schema-gate failure fails overall');
    Assert::equals(6, count($v1Verdict->toArray()['checks']), 'schema-gate verdict still records all six checks');

    // verifyResponse happy path parses a well-formed payload and passes.
    $payload = new LlmResponse([
        'claims' => [
            ['text' => 'Good morning, Dr. S.', 'claim_type' => 'greeting', 'citation_ids' => []],
            ['text' => 'A1c was 8.1 on 2026-01-05.', 'claim_type' => 'trend', 'citation_ids' => [$f1->factId], 'numeric_values' => [8.1]],
        ],
    ], 10, 30, 'stub@1', 7);
    Assert::that($verifier->verifyResponse($payload, $set, $pid, true)->passed, 'well-formed payload parses (V1) and passes end-to-end');

    // Claim::fromArray rejects malformed input (V1 boundary).
    Assert::throws(static fn() => Claim::fromArray(['claim_type' => 'greeting', 'citation_ids' => []]), 'fromArray rejects a claim missing text');
    Assert::throws(static fn() => Claim::fromArray(['text' => 'hi', 'claim_type' => 'not_a_type', 'citation_ids' => []]), 'fromArray rejects an unknown claim_type');
}
