<?php

/**
 * Isolated tests for AlertEvaluator (U12b, §3.5) — pure evaluation of the seven alerts.
 *
 * Guards: each alert fires exactly on its threshold, the quiet path fires only the
 * heartbeat when data is fresh-less, wrong-patient is Sev-1, spend fires on burn OR cap.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Observability\AlertEvaluator;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertId;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertInputs;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertSeverity;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertThresholds;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

/**
 * @param list<\OpenEMR\Modules\ClinicalCopilot\Observability\Alert> $alerts
 */
function cc_has_alert(array $alerts, AlertId $id): bool
{
    foreach ($alerts as $a) {
        if ($a->id === $id) {
            return true;
        }
    }
    return false;
}

function clinical_copilot_test_AlertEvaluatorTest(): void
{
    $t = new AlertThresholds();

    // A "quiet" baseline: everything healthy, heartbeat fresh (age 5s, tick 5s => stale after 10s).
    $quiet = new AlertInputs(
        wrongPatientTrips: 0,
        p95ChatTurnMs: 1000,
        errorRate: 0.0,
        maxToolFailureRate: 0.0,
        verificationFailureRate: 0.0,
        hourlyBurnUsd: 1.0,
        trailingHourlyBaselineUsd: 5.0,
        dailySpendUsd: 10.0,
        dailyCapUsd: 100.0,
        workerHeartbeatAgeSec: 5,
        workerTickIntervalSec: 5,
    );
    Assert::equals(0, count(AlertEvaluator::evaluate($quiet, $t)), 'a healthy window fires no alerts');

    // Wrong-patient: single occurrence => Sev-1.
    $wp = new AlertInputs(1, 1000, 0.0, 0.0, 0.0, 1.0, 5.0, 10.0, 100.0, 5, 5);
    $wpAlerts = AlertEvaluator::evaluate($wp, $t);
    Assert::that(cc_has_alert($wpAlerts, AlertId::WrongPatientGuard), 'a single wrong-patient trip fires the guard alert');
    Assert::equals(AlertSeverity::Sev1, $wpAlerts[0]->severity, 'wrong-patient guard is Sev-1');

    // p95 latency over 15s.
    $lat = new AlertInputs(0, 15001, 0.0, 0.0, 0.0, 1.0, 5.0, 10.0, 100.0, 5, 5);
    Assert::that(cc_has_alert(AlertEvaluator::evaluate($lat, $t), AlertId::P95Latency), 'p95 over 15s fires the latency alert');
    $latOk = new AlertInputs(0, 15000, 0.0, 0.0, 0.0, 1.0, 5.0, 10.0, 100.0, 5, 5);
    Assert::that(!cc_has_alert(AlertEvaluator::evaluate($latOk, $t), AlertId::P95Latency), 'p95 exactly at threshold does not fire (strict >)');

    // Error rate over 5%.
    $err = new AlertInputs(0, 1000, 0.06, 0.0, 0.0, 1.0, 5.0, 10.0, 100.0, 5, 5);
    Assert::that(cc_has_alert(AlertEvaluator::evaluate($err, $t), AlertId::ErrorRate), 'error rate over 5% fires');

    // Tool failure rate over 2%.
    $tool = new AlertInputs(0, 1000, 0.0, 0.03, 0.0, 1.0, 5.0, 10.0, 100.0, 5, 5);
    Assert::that(cc_has_alert(AlertEvaluator::evaluate($tool, $t), AlertId::ToolFailureRate), 'a tool over 2% failure fires');

    // Verification failure over 10%.
    $ver = new AlertInputs(0, 1000, 0.0, 0.0, 0.11, 1.0, 5.0, 10.0, 100.0, 5, 5);
    Assert::that(cc_has_alert(AlertEvaluator::evaluate($ver, $t), AlertId::VerificationFailureRate), 'verification failure over 10% fires');

    // Spend: hourly burn > 2x baseline (baseline 5 => threshold 10; burn 11).
    $burn = new AlertInputs(0, 1000, 0.0, 0.0, 0.0, 11.0, 5.0, 10.0, 100.0, 5, 5);
    Assert::that(cc_has_alert(AlertEvaluator::evaluate($burn, $t), AlertId::LlmSpend), 'hourly burn over 2x baseline fires spend alert');

    // Spend: daily cap reached even with quiet burn.
    $cap = new AlertInputs(0, 1000, 0.0, 0.0, 0.0, 1.0, 5.0, 100.0, 100.0, 5, 5);
    Assert::that(cc_has_alert(AlertEvaluator::evaluate($cap, $t), AlertId::LlmSpend), 'daily cap reached fires spend alert');

    // Heartbeat stale: age 11s with tick 5s (stale after 10s).
    $stale = new AlertInputs(0, 1000, 0.0, 0.0, 0.0, 1.0, 5.0, 10.0, 100.0, 11, 5);
    Assert::that(cc_has_alert(AlertEvaluator::evaluate($stale, $t), AlertId::WorkerHeartbeatStale), 'heartbeat older than 2x tick fires');

    // Heartbeat missing entirely (null age) => stale.
    $none = new AlertInputs(0, 1000, 0.0, 0.0, 0.0, 1.0, 5.0, 10.0, 100.0, null, 5);
    Assert::that(cc_has_alert(AlertEvaluator::evaluate($none, $t), AlertId::WorkerHeartbeatStale), 'an absent heartbeat is stale (dead worker)');
}
