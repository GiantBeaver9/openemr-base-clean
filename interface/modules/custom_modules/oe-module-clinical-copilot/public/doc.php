<?php

/**
 * GET /copilot/doc?pid=:pid — the synthesis doc page entry (U8, R5).
 *
 * Thin composition root: bootstrap the host (globals), wire the runtime read-path dependencies
 * (DB-backed capabilities, doc store, Vertex-backed reducer, verifier, DB trace writer), build the
 * host-backed audit logger from the proven session identity, and hand off to DocController, which
 * performs CSRF/ACL/identity checks and renders. All PHI authorization lives in the controller;
 * this file only constructs collaborators and supplies the Bootstrap/Header shell.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(dirname(__DIR__, 4) . "/globals.php");

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Doc\DbDocGateway;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\GlobalConfig;
use OpenEMR\Modules\ClinicalCopilot\Observability\DbTraceWriter;
use OpenEMR\Modules\ClinicalCopilot\Reduce\EgressRedactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Modules\ClinicalCopilot\Reduce\VertexClient;
use OpenEMR\Modules\ClinicalCopilot\Read\EventAuditAdapter;
use OpenEMR\Modules\ClinicalCopilot\Read\ReadPath;
use OpenEMR\Modules\ClinicalCopilot\Controller\DocController;
use OpenEMR\Modules\ClinicalCopilot\SynthesisVersions;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;

$templatePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR;
$twig = (new TwigContainer($templatePath, OEGlobalsBag::getInstance()->getKernel()))->getTwig();

$config = new GlobalConfig($GLOBALS);
$traces = new DbTraceWriter();

$reducer = new Reducer(
    new VertexClient($config),
    new PromptAssembler(),
    new EgressRedactor(),
    $traces,
    $config->modelPro(),
    SynthesisVersions::PROMPT_VERSION,
    Reducer::DEFAULT_MAX_ATTEMPTS,
);

$audit = new EventAuditAdapter(
    (string) ($_SESSION['authUser'] ?? ''),
    (string) ($_SESSION['authProvider'] ?? ''),
);

$readPath = new ReadPath(
    CapabilityFactory::db(),
    new DocStore(new DbDocGateway()),
    $reducer,
    new Verifier(),
    $traces,
    $audit,
);

$controller = new DocController($readPath, $twig, new SystemLogger());

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Clinical Co-Pilot — Pre-visit Summary'); ?></title>
    <?php Header::setupHeader(['common']); ?>
</head>
<body class="body_top">
    <div class="container-fluid mt-3">
        <?php $controller->handle(); ?>
    </div>
</body>
</html>
