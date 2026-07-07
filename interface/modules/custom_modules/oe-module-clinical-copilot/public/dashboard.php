<?php

/**
 * GET /copilot/dashboard — the in-app observability dashboard (§3.3, R4).
 *
 * ACL-gated (admin) and audit-logged (§3.2 — the trace UI is itself audited, by the
 * Dashboard class). Renders the click-through: overview tiles → per-kind request list →
 * span waterfall → single-span payload, selected by the `view` query param. All data is
 * computed by the pure Metrics over the read-only TraceQuery; the host Header supplies the
 * Bootstrap shell and every value is escaped at the Twig sink (autoescape is OFF globally).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(dirname(__DIR__, 4) . "/globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertEvaluator;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertInputs;
use OpenEMR\Modules\ClinicalCopilot\Observability\AlertThresholds;
use OpenEMR\Modules\ClinicalCopilot\Observability\CadenceConfigStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\CircuitBreakerStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\Dashboard;
use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceQuery;

// The trace UI holds PHI-derived payloads: admin-gated (§3.2), not the feature ACL.
if (!AclMain::aclCheckCore('admin', 'super') && !AclMain::aclCheckCore('admin', 'users')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$userId = (int) ($_SESSION['authUserID'] ?? 0);
$templatePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR;
$twig = (new TwigContainer($templatePath, OEGlobalsBag::getInstance()->getKernel()))->getTwig();

$traces = new TraceQuery();
$dashboard = new Dashboard($traces, $twig, new SystemLogger());

// 24-hour window for the tiles / lists.
$sinceIso = (new \DateTimeImmutable('-24 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

$view = (string) ($_GET['view'] ?? 'overview');

try {
    switch ($view) {
        case 'requests':
            $kind = isset($_GET['kind']) ? (string) $_GET['kind'] : null;
            $body = $dashboard->renderRequestList($sinceIso, $kind, $userId);
            break;
        case 'waterfall':
            $correlationId = (string) ($_GET['correlation_id'] ?? '');
            $body = $dashboard->renderWaterfall($correlationId, $userId);
            break;
        case 'payload':
            $spanId = (string) ($_GET['span_id'] ?? '');
            $body = $dashboard->renderPayload($spanId, $userId);
            break;
        case 'overview':
        default:
            $alerts = mod_copilot_dashboard_alerts($sinceIso);
            $body = $dashboard->renderOverview($sinceIso, $userId, $alerts);
            break;
    }
} catch (\Throwable $e) {
    (new SystemLogger())->error('Clinical Co-Pilot dashboard render failed', [
        'view' => $view,
        'exception' => $e,
    ]);
    http_response_code(500);
    $body = '<div class="alert alert-danger">' . xlt('The dashboard could not be rendered.') . '</div>';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Clinical Co-Pilot — Observability'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top">
    <div class="container-fluid mt-3">
        <?php echo $body; ?>
    </div>
</body>
</html>
<?php

/**
 * Assemble the firing-alert banner for the overview from the metrics window + heartbeat +
 * breaker/spend signals. Best-effort: any query failure yields no banner rather than a
 * broken page.
 *
 * @return list<\OpenEMR\Modules\ClinicalCopilot\Observability\Alert>
 */
function mod_copilot_dashboard_alerts(string $sinceIso): array
{
    try {
        $traces = new TraceQuery();
        $rows = $traces->windowSpans($sinceIso);
        $summary = Metrics::summary($rows);

        $p95 = $summary['p95_by_kind']['chat_turn'] ?? null;

        $maxToolFailure = 0.0;
        foreach ($summary['tool_failure_by_tool'] as $stats) {
            if ($stats['failure_rate'] > $maxToolFailure) {
                $maxToolFailure = $stats['failure_rate'];
            }
        }

        $verification = $summary['verification'];
        $verFailRate = $verification['total'] > 0 ? 1.0 - $verification['pass_rate'] : 0.0;

        $config = new CadenceConfigStore();
        $dailyCap = $config->getFloat(CircuitBreakerStore::KEY_DAILY_CAP, 50.0);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s.v');
        $hourStart = $now->setTime((int) $now->format('H'), 0, 0)->format('Y-m-d H:i:s.v');
        $weekStart = $now->modify('-7 days')->format('Y-m-d H:i:s.v');

        $dailySpend = mod_copilot_spend_since($dayStart);
        $hourlySpend = mod_copilot_spend_since($hourStart);
        $weekSpend = mod_copilot_spend_since($weekStart);
        $hourlyBaseline = $weekSpend / (7.0 * 24.0);

        $lastWorker = $summary['last_worker_span_at'];
        $heartbeatAge = is_string($lastWorker) ? (time() - (int) strtotime($lastWorker)) : null;

        $frozen = mod_copilot_count(
            "SELECT COUNT(*) AS c FROM mod_copilot_chat_session WHERE status = 'frozen'",
        );

        $inputs = new AlertInputs(
            wrongPatientTrips: $frozen,
            p95ChatTurnMs: is_int($p95) ? $p95 : null,
            errorRate: (float) $summary['error_rate'],
            maxToolFailureRate: $maxToolFailure,
            verificationFailureRate: $verFailRate,
            hourlyBurnUsd: $hourlySpend,
            trailingHourlyBaselineUsd: $hourlyBaseline,
            dailySpendUsd: $dailySpend,
            dailyCapUsd: $dailyCap,
            workerHeartbeatAgeSec: $heartbeatAge,
            workerTickIntervalSec: 300,
        );

        return AlertEvaluator::evaluate($inputs, new AlertThresholds());
    } catch (\Throwable $e) {
        (new SystemLogger())->error('Clinical Co-Pilot alert assembly failed', ['exception' => $e]);
        return [];
    }
}

function mod_copilot_spend_since(string $sinceIso): float
{
    $value = \OpenEMR\Common\Database\QueryUtils::fetchSingleValue(
        "SELECT COALESCE(SUM(cost_usd), 0) AS spend FROM mod_copilot_trace WHERE started_at >= ?",
        'spend',
        [$sinceIso],
    );
    return is_numeric($value) ? (float) $value : 0.0;
}

function mod_copilot_count(string $sql): int
{
    $value = \OpenEMR\Common\Database\QueryUtils::fetchSingleValue($sql, 'c', []);
    return is_numeric($value) ? (int) $value : 0;
}
