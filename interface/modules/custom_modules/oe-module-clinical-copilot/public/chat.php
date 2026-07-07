<?php

/**
 * chat.php — the pinned chat surface (ARCHITECTURE.md §1.3).
 *
 * GET renders the copilot chat page for one patient: the chat panel with the facts panel ALWAYS
 * beside it (recovery asymmetry, §6 — the facts and summary never depend on the live LLM). POST is
 * one synchronous agent turn, delegated to ChatController (CSRF → ACL → identity → 409 guard →
 * audit → agent → append-only persist → SSE/JSON). The endpoint is contractually read-only-session:
 * it never sets $sessionAllowWrite=true, so a long-held turn cannot serialize the physician's tabs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Read-only session (§1.3): never opt into write mode, so the turn holds no session write lock.
$sessionAllowWrite = false;

require_once(dirname(__DIR__, 4) . "/globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Chat\DbSessionGateway;
use OpenEMR\Modules\ClinicalCopilot\Chat\SeedBuilder;
use OpenEMR\Modules\ClinicalCopilot\Chat\SessionStore;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;
use OpenEMR\Modules\ClinicalCopilot\Doc\DbDocGateway;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Fact\CanonicalSerializer;

// ACL at page top (§4) — every copilot surface requires patients/med.
if (!AclMain::aclCheckCore('patients', 'med')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$authUserId = (int) ($_SESSION['authUserID'] ?? 0);

// POST → one agent turn, handled by the controller (it re-checks CSRF/ACL/identity itself).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    ChatController::fromGlobals($GLOBALS)->handle();
    exit;
}

// GET → render the chat page for a patient.
$pid = (int) ($_GET['pid'] ?? 0);
if ($pid <= 0) {
    http_response_code(400);
    echo xlt('A patient must be selected to open the Clinical Co-Pilot chat.');
    exit;
}

try {
    EventAuditLogger::getInstance()->newEvent(
        'patient-record',
        (string) $authUserId,
        '',
        1,
        'Clinical Co-Pilot chat page opened (action=view)',
        $pid,
        'open-emr',
        'view',
    );
} catch (\Throwable) {
    // Auditing failure must not take the page down.
}

$narrative = '';
$facts = [];
$sessionId = 0;
$banner = null;

try {
    $factory = CapabilityFactory::db();
    $docStore = new DocStore(new DbDocGateway());
    $historyDocs = $docStore->history($pid);
    $doc = $historyDocs === [] ? null : $historyDocs[count($historyDocs) - 1];

    $seed = (new SeedBuilder())->build($factory, $pid, $doc);
    $narrative = $seed->narrative;
    $facts = (new CanonicalSerializer())->canonicalize($seed->facts->facts);

    $store = new SessionStore(new DbSessionGateway());
    $session = $store->open($pid, $authUserId, $doc?->id, $seed->factDigest);
    $sessionId = $session->id;
} catch (\Throwable $e) {
    (new SystemLogger())->error('Clinical Co-Pilot chat page seed failed', ['pid' => $pid, 'exception' => $e]);
    $banner = xlt('The pre-visit synthesis is temporarily unavailable — the chat can still retrieve facts on request.');
}

$csrfToken = CsrfUtils::collectCsrfToken(SessionWrapperFactory::getInstance()->getActiveSession());

$templatePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR;
$twig = (new TwigContainer($templatePath, OEGlobalsBag::getInstance()->getKernel()))->getTwig();

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Clinical Co-Pilot — Chat'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top">
<div class="container-fluid mt-3">
    <?php
    echo $twig->render('chat.html.twig', [
        'pid' => $pid,
        'session_id' => $sessionId,
        'narrative' => $narrative,
        'facts' => $facts,
        'csrf_token' => $csrfToken,
        'post_url' => 'chat.php',
        'status_url' => 'status.php',
        'seed_banner' => $banner,
    ]);
    ?>
</div>
</body>
</html>
