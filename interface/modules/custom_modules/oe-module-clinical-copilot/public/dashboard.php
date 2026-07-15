<?php

/**
 * Clinical Co-Pilot -- observability dashboard (ARCHITECTURE.md §3.3).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// Read-only-session -- never sets $sessionAllowWrite (even the breaker
// admin actions below are single-row config writes to mod_copilot_cadence,
// never anything requiring a write-session).
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Observability\Metrics\MetricsService;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceCircuitBreaker;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimit\CadenceConfigStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\ReadyCheck;
use OpenEMR\Modules\ClinicalCopilot\Observability\TracePayloadStore;
use OpenEMR\Modules\ClinicalCopilot\Verify\CheckId;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationPolicy;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost) {
    CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);
}

// ARCHITECTURE.md §3.2: "Access to the trace UI is ACL-gated (admin) and
// itself audit-logged." Gated on the host's admin section (mirrors
// oe-module-dashboard-context's own AdminController pattern) AND this
// module's own ACL section, so a site can independently deny the copilot
// even to a technical admin.
$isAdmin = AclMain::aclCheckCore('admin', 'super') || AclMain::aclCheckCore('admin', 'users');
if (!$isAdmin || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$authUser = (string)($session->get('authUser') ?? '');
$authProvider = (string)($session->get('authProvider') ?? '');
$configStore = new CadenceConfigStore();

// Populated only when the "Run evals" button is pressed (a POST action below).
$evalResult = null;
$evalError = null;

if ($isPost) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'breaker_force_open') {
        $configStore->forceOpen($authUser, (string)($_POST['reason'] ?? ''));
        EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: circuit breaker manually forced open');
    } elseif ($action === 'breaker_reset') {
        $configStore->manualReset($authUser);
        EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: circuit breaker manually reset');
    } elseif ($action === 'run_evals') {
        // The 50-case boolean-rubric gate, on demand from the UI — the SAME
        // deterministic engine the CLI/CI gate uses (ops/eval/run-evals.php),
        // with no live model or DB. EvalGate lives under ops/eval/ (not src/),
        // so require it explicitly; its src/ collaborators autoload normally.
        require_once dirname(__DIR__) . '/ops/eval/EvalGate.php';
        try {
            $evalResult = (new \OpenEMR\Modules\ClinicalCopilot\Ops\Eval\EvalGate(dirname(__DIR__) . '/ops/eval'))->run();
        } catch (\Throwable $e) {
            $evalError = 'Eval run failed to start (see server log).';
            (new \OpenEMR\Common\Logging\SystemLogger())->error('ClinicalCopilot: dashboard eval run failed', ['exception' => $e]);
        }
        EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: eval gate run from dashboard');
    }
}

EventAuditLogger::getInstance()->newEvent('security', $authUser, $authProvider, 1, 'Clinical Co-Pilot: observability dashboard viewed');

$metricsService = new MetricsService();
$windowHours = is_numeric($_GET['window_hours'] ?? null) ? max(1, min(8760, (int)$_GET['window_hours'])) : 24;
$since = new DateTimeImmutable("-{$windowHours} hours");

$correlationId = (string)($_GET['correlation_id'] ?? '');
$payloadRef = (string)($_GET['payload_ref'] ?? '');
$kindFilter = ($_GET['kind'] ?? '') !== '' ? (string)$_GET['kind'] : null;
$statusFilter = ($_GET['status'] ?? '') !== '' ? (string)$_GET['status'] : null;

$waterfall = null;
$payloadRefs = [];
if ($correlationId !== '') {
    $waterfall = $metricsService->spanWaterfall($correlationId);
    $payloadRefs = (new TracePayloadStore())->forCorrelationId($correlationId);
    // Drilling into one request's spans can surface pid-scoped clinical
    // data (facts, prompt text) -- audited as a chart-adjacent view, same as
    // any other chart-data access (ARCHITECTURE.md §4).
    EventAuditLogger::getInstance()->newEvent('patient-record', $authUser, $authProvider, 1, "Clinical Co-Pilot dashboard span drill-down, correlation_id={$correlationId}");
}

$payload = $payloadRef !== '' ? (new TracePayloadStore())->fetch($payloadRef) : null;
$payloadJson = $payload !== null ? json_encode($payload['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null;

$metrics = $metricsService->overview($since);

// Verification panel, named and enforcement-aware. The V1-V6 content gate is a
// runtime policy (VerificationPolicy): when it is NOT enforced, the checks still
// RUN and record verdicts (so the ledger shows what would have failed) but they
// do not block/degrade a turn — so a "fail" is advisory, not a blocked turn.
// Patient identity (V3) is enforced unconditionally (cross-patient PHI guard).
$verifyTally = is_array($metrics['verification_pass_fail_by_check'] ?? null) ? $metrics['verification_pass_fail_by_check'] : [];
$verifyGateEnforced = VerificationPolicy::gateEnforced();
$verificationChecks = [];
foreach (CheckId::cases() as $check) {
    $counts = is_array($verifyTally[$check->value] ?? null) ? $verifyTally[$check->value] : ['passed' => 0, 'failed' => 0];
    $verificationChecks[] = [
        'code' => $check->value,
        'label' => $check->label(),
        'passed' => (int)($counts['passed'] ?? 0),
        'failed' => (int)($counts['failed'] ?? 0),
        'enforced' => $verifyGateEnforced || $check === CheckId::PatientIdentity,
    ];
}

$dashboardUrl = OEGlobalsBag::getInstance()->getWebRoot() . '/interface/modules/custom_modules/oe-module-clinical-copilot/public/dashboard.php';

$templateVars = [
    'window_hours' => $windowHours,
    'metrics' => $metrics,
    'verification_checks' => $verificationChecks,
    'verify_gate_enforced' => $verifyGateEnforced,
    'alerts' => $metricsService->recentFiredAlerts(),
    'breaker' => (new CadenceCircuitBreaker())->snapshot(),
    'limits' => $configStore->limits(),
    'ready' => (new ReadyCheck())->check(),
    'requests' => $metricsService->requestList($kindFilter, $statusFilter, 100),
    'kind_filter' => $kindFilter,
    'status_filter' => $statusFilter,
    'correlation_id' => $correlationId,
    'waterfall' => $waterfall,
    'payload_refs' => $payloadRefs,
    'payload_ref' => $payloadRef,
    'payload' => $payload,
    'payload_json' => $payloadJson,
    'eval_result' => $evalResult,
    'eval_error' => $evalError,
    'dashboard_url' => $dashboardUrl,
];

$twig = (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
echo $twig->render('oe-module-clinical-copilot/dashboard.html.twig', $templateVars);
