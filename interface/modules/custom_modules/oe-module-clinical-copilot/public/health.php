<?php

/**
 * GET /copilot/health — unauthenticated liveness (§3.4, R6).
 *
 * Returns ONLY module-enabled + module version. Deliberately checks NO dependencies: a DB
 * or LLM outage must not fail liveness and get the app pointlessly restarted by an
 * orchestrator. Dependency health is a separate concern answered by ready.php. Bootstraps
 * the host globals (for the enabled flag) with auth disabled — this endpoint is public.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Public endpoint: no session/auth required. Must be set before globals.php bootstraps.
$ignoreAuth = true;
$sessionAllowWrite = false;

require_once(dirname(__DIR__, 4) . "/globals.php");

use OpenEMR\Modules\ClinicalCopilot\GlobalConfig;

$version = 'unknown';
try {
    require __DIR__ . '/../version.php';
    if (isset($module_version) && is_string($module_version)) {
        $version = $module_version;
    }
} catch (\Throwable) {
    // Version file is static; failure here still must not fail liveness.
}

$enabled = false;
try {
    $enabled = (new GlobalConfig($GLOBALS))->isEnabled();
} catch (\Throwable) {
    // Reading the flag must never fail liveness — report not-enabled rather than erroring.
    $enabled = false;
}

header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'enabled' => $enabled,
    'version' => $version,
], JSON_THROW_ON_ERROR);
