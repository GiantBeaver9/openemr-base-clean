<?php

/**
 * Clinical Co-Pilot -- pre-visit synthesis doc page (U8: read path, facts-first
 * synthesis, history view, manual Regenerate).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
// This page is read-only-session -- it never sets $sessionAllowWrite.
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Controller\DocController;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\DocViewModel;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// CSRF -> ACL -> session identity, in that order (build-notes.md's page
// bootstrap contract). CSRF only applies to the state-changing Regenerate
// POST; a plain GET view performs no write and needs no token.
if ($isPost) {
    CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);
}

// Gate on both the chart-wide 'patients'/'med' section AND this module's
// own 'clinical_copilot' section (ARCHITECTURE.md §4) -- a site can deny
// the copilot independently of chart access, and a denial here is a clean
// ACL deny, not obscurity.
if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$authUserId = (int)($session->get('authUserID') ?? 0);

// pid is an int chart identifier, never PHI itself (docs/build-notes.md /
// ARCHITECTURE.md §4: "pid in GET is fine"); it stays in the query string on
// both the view GET and the Regenerate form's POST target URL.
$rawPid = $_GET['pid'] ?? null;
$pid = is_numeric($rawPid) ? (int)$rawPid : 0;

$controller = DocController::createDefault();

if ($pid <= 0) {
    $viewData = ['found' => false, 'result' => null, 'history' => [], 'patient' => null];
} elseif ($isPost && ($_POST['action'] ?? '') === 'regenerate') {
    $viewData = $controller->regenerate($pid, $authUserId);
} else {
    $viewData = $controller->view($pid, $authUserId);
}

$webRoot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleUrl = $webRoot . '/interface/modules/custom_modules/oe-module-clinical-copilot/public/doc.php';

$templateVars = [
    'found' => $viewData['found'],
    'patient' => $viewData['patient'],
    'pid' => $pid,
    'doc_url' => $moduleUrl . '?pid=' . $pid,
    'history_rows' => $viewData['found'] ? DocViewModel::historyRows($viewData['history']) : [],
];

if ($viewData['found']) {
    $templateVars['doc'] = DocViewModel::summary($viewData['result']);
    $templateVars['view_model'] = DocViewModel::build($viewData['result'], $webRoot);
} else {
    $templateVars['doc'] = ['capability_crash' => false];
    $templateVars['view_model'] = ['narrative' => [], 'facts_by_capability' => [], 'in_flight' => [], 'exclusions' => []];
}

$twig = (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
echo $twig->render('oe-module-clinical-copilot/doc.html.twig', $templateVars);
