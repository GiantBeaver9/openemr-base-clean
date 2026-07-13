<?php

/**
 * Clinical Co-Pilot -- Week 2 intake upload: create a patient from an intake PDF.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Page bootstrap contract (docs/build-notes.md): flags set BEFORE globals.php.
$ignoreAuth = false;

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Config\LlmRuntimeConfig;
use OpenEMR\Modules\ClinicalCopilot\Controller\IngestController;
use OpenEMR\Modules\ClinicalCopilot\Ingest\UploadedDocument;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// CSRF -> ACL -> session identity (build-notes.md page bootstrap contract).
if ($isPost) {
    CsrfUtils::checkCsrfInput(INPUT_POST, $session, dieOnFail: true);
}

if (!AclMain::aclCheckCore('patients', 'med') || !AclMain::aclCheckCore('clinical_copilot', 'copilot_access')) {
    http_response_code(403);
    echo xlt('Access denied');
    exit;
}

$authUserId = (int)($session->get('authUserID') ?? 0);
$webRoot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleBase = $webRoot . '/interface/modules/custom_modules/oe-module-clinical-copilot/public';

if ($isPost && ($_POST['action'] ?? '') === 'upload') {
    $upload = UploadedDocument::fromFilesEntry($_FILES['document'] ?? null);
    if ($upload === null) {
        renderIntakeForm($moduleBase, $webRoot, error: xl('Please choose a PDF or image to upload.'));
        exit;
    }

    $controller = IngestController::createDefault();
    $result = $controller->ingestIntake($upload->bytes, $upload->filename, $upload->mimeType, $authUserId);

    header('Location: ' . $moduleBase . '/extraction_review.php?extraction_id=' . $result->extractionId);
    exit;
}

renderIntakeForm($moduleBase, $webRoot);

function renderIntakeForm(string $moduleBase, string $webRoot, string $error = ''): void
{
    $twig = (new TwigContainer(dirname(__DIR__) . '/templates', OEGlobalsBag::getInstance()->getKernel()))->getTwig();
    echo $twig->render('oe-module-clinical-copilot/intake_upload.html.twig', [
        'post_url' => $moduleBase . '/intake_upload.php',
        'web_root' => $webRoot,
        'error' => $error,
        'llm_configured' => LlmRuntimeConfig::llmConfigured(),
    ]);
}
