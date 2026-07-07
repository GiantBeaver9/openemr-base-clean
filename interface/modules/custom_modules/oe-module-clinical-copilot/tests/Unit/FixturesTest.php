<?php

/**
 * Isolated tests for the U2 synthetic fixtures.
 *
 * Guards that every fixture file is valid JSON in the host-table column shape,
 * that all four synthetic patients (9001-9004) are present, and that each
 * landmine from the build spec (ARCHITECTURE_COMPLETE.md U2) has its known-answer
 * row so downstream contract tests (U4/U5) can consume it with no database.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

/**
 * Load a fixture JSON array from tests/Fixtures.
 *
 * @return list<array<string, mixed>>
 */
function cc_load_fixture(string $file): array
{
    $path = __DIR__ . '/../Fixtures/' . $file;
    $json = file_get_contents($path);
    Assert::that($json !== false, "fixture readable: {$file}");
    $decoded = json_decode((string) $json, true);
    Assert::that(is_array($decoded), "fixture is a JSON array: {$file}");
    /** @var list<array<string, mixed>> $decoded */
    return is_array($decoded) ? $decoded : [];
}

/**
 * Index fixture rows by a key column (documentation `_`-keys ignored).
 *
 * @param list<array<string, mixed>> $rows
 * @return array<int|string, array<string, mixed>>
 */
function cc_index_by(array $rows, string $key): array
{
    $out = [];
    foreach ($rows as $row) {
        if (array_key_exists($key, $row)) {
            $out[$row[$key]] = $row;
        }
    }
    return $out;
}

function clinical_copilot_test_FixturesTest(): void
{
    // Every fixture file is valid JSON in list shape.
    $patients = cc_load_fixture('patient_data.json');
    $orders = cc_load_fixture('procedure_order.json');
    $reports = cc_load_fixture('procedure_report.json');
    $results = cc_load_fixture('procedure_result.json');
    $rx = cc_load_fixture('prescriptions.json');
    $lists = cc_load_fixture('lists.json');
    $vitals = cc_load_fixture('form_vitals.json');
    $cadence = cc_load_fixture('mod_copilot_cadence.json');

    // Four synthetic patients with stable pids + idempotency markers.
    $byPid = cc_index_by($patients, 'pid');
    foreach ([9001, 9002, 9003, 9004] as $pid) {
        Assert::that(isset($byPid[$pid]), "patient {$pid} present");
        Assert::equals('CCPILOT-' . $pid, $byPid[$pid]['pubpid'] ?? null, "patient {$pid} carries idempotency marker");
    }

    $order = cc_index_by($orders, 'procedure_order_id');
    $report = cc_index_by($reports, 'procedure_report_id');
    $result = cc_index_by($results, 'procedure_result_id');

    // L1 rising A1c trend (9001): 7.1 -> 7.8 -> mmol/mol draw.
    Assert::equals('7.1', $result[6101]['result'] ?? null, 'L1 A1c draw #1 = 7.1');
    Assert::equals('7.8', $result[6102]['result'] ?? null, 'L1 A1c draw #2 = 7.8');
    Assert::equals('4548-4', $result[6101]['result_code'] ?? null, 'L1 uses A1c LOINC 4548-4');

    // L10 mmol/mol A1c value (9001).
    Assert::equals('mmol/mol', $result[6103]['units'] ?? null, 'L10 mmol/mol unit present');
    Assert::equals('69', $result[6103]['result'] ?? null, 'L10 mmol/mol value = 69');

    // L2 med-dose-vs-A1c mismatch (9001): stable metformin in BOTH sources (union dedup).
    $rxById = cc_index_by($rx, 'id');
    $listById = cc_index_by($lists, 'id');
    Assert::equals('500', $rxById[7101]['dosage'] ?? null, 'L2 metformin 500 mg (unchanged)');
    Assert::equals('medication', $listById[8101]['type'] ?? null, 'L2 duplicate med in lists(type=medication) for union dedup');

    // L3 overdue urine ACR (9002): last ACR draw 2024-11 + annual cadence.
    Assert::equals('9318-7', $result[6201]['result_code'] ?? null, 'L3 urine ACR LOINC 9318-7');
    Assert::that(str_starts_with((string) ($result[6201]['date'] ?? ''), '2024-11'), 'L3 last ACR drawn 2024-11 (overdue)');
    $cadenceKeys = array_map(static fn(array $r): string => (string) ($r['config_key'] ?? ''), $cadence);
    Assert::that(in_array('code_set:acr', $cadenceKeys, true), 'L3 cadence config has ACR interval');

    // L4 late-arriving / backdated lab (9004): C1 fallback (report+order date_collected NULL).
    Assert::that(array_key_exists('date_collected', $report[5402] ?? []) && $report[5402]['date_collected'] === null, 'L4 report date_collected NULL (C1 falls through)');
    Assert::that(array_key_exists('date_collected', $order[4402] ?? []) && $order[4402]['date_collected'] === null, 'L4 order date_collected NULL (C1 falls through)');
    Assert::that(!empty($result[6402]['date']), 'L4 result.date present -> becomes fallback clinical_date');

    // L5 corrected lab in-place UPDATE (9004): single row, status corrected, no sibling.
    Assert::equals('corrected', $result[6401]['result_status'] ?? null, 'L5 in-place row status = corrected');
    $a1c9004 = array_filter(
        $results,
        static fn(array $r): bool => ($r['procedure_report_id'] ?? null) === 5401,
    );
    Assert::equals(1, count($a1c9004), 'L5 in-place correction leaves exactly ONE row (no superseded sibling)');

    // L6 corrected lab new-row correction (9002): final + corrected, same report+code+date.
    Assert::equals('final', $result[6202]['result_status'] ?? null, 'L6 original row status = final');
    Assert::equals('corrected', $result[6203]['result_status'] ?? null, 'L6 correction row status = corrected');
    Assert::that(
        ($result[6202]['result_code'] ?? null) === ($result[6203]['result_code'] ?? '_')
        && ($result[6202]['date'] ?? null) === ($result[6203]['date'] ?? '_'),
        'L6 both rows share result_code + clinical date (supersession group)'
    );
    Assert::that((6203 > 6202), 'L6 correction row has the higher procedure_result_id (tie-break winner)');

    // L7 drawn-but-unresulted order (9002): order + report exist, ZERO result rows.
    Assert::that(isset($order[4203]), 'L7 pending order 4203 present');
    Assert::equals('routed', $order[4203]['order_status'] ?? null, 'L7 order status routed (in-flight)');
    Assert::that(isset($report[5203]), 'L7 report 5203 present');
    $resultsForPendingReport = array_filter(
        $results,
        static fn(array $r): bool => ($r['procedure_report_id'] ?? null) === 5203,
    );
    Assert::equals(0, count($resultsForPendingReport), 'L7 pending report has NO result rows');

    // L8 "<7.0" censored value (9003).
    Assert::equals('<7.0', $result[6301]['result'] ?? null, 'L8 censored value literal "<7.0"');

    // L9 unitless value (9003): empty units.
    Assert::equals('', $result[6302]['units'] ?? '_', 'L9 unitless value has empty units');
    Assert::equals('142', $result[6302]['result'] ?? null, 'L9 unitless glucose value present');

    // L11 unrecognized result_status (9003).
    Assert::equals('transcribed', $result[6304]['result_status'] ?? null, 'L11 unrecognized status "transcribed"');

    // L12 preliminary result (9003).
    Assert::equals('preliminary', $result[6303]['result_status'] ?? null, 'L12 preliminary result status');

    // Vitals present for VitalsTrend (each patient has >=1 row).
    $vitalPids = array_map(static fn(array $r): mixed => $r['pid'] ?? null, $vitals);
    foreach ([9001, 9002, 9003, 9004] as $pid) {
        Assert::that(in_array($pid, $vitalPids, true), "vitals present for patient {$pid}");
    }

    // Expected known-answer file covers all 12 landmines.
    $expectedJson = file_get_contents(__DIR__ . '/../Fixtures/expected/landmines.json');
    Assert::that($expectedJson !== false, 'expected/landmines.json readable');
    $expected = json_decode((string) $expectedJson, true);
    Assert::that(is_array($expected) && isset($expected['landmines']), 'expected/landmines.json has landmines map');
    $expectedKeys = is_array($expected) && isset($expected['landmines']) && is_array($expected['landmines'])
        ? array_keys($expected['landmines'])
        : [];
    Assert::equals(12, count($expectedKeys), 'expected file documents all 12 landmines');
    foreach (
        [
            'L1_rising_a1c_trend', 'L2_med_dose_a1c_mismatch', 'L3_overdue_urine_acr',
            'L4_late_arriving_lab', 'L5_corrected_lab_in_place', 'L6_corrected_lab_new_row',
            'L7_drawn_but_unresulted', 'L8_censored_value_lt7', 'L9_unitless_value',
            'L10_mmol_mol_a1c', 'L11_unrecognized_status', 'L12_preliminary_result',
        ] as $key
    ) {
        Assert::that(in_array($key, $expectedKeys, true), "expected known-answer present: {$key}");
    }
}
