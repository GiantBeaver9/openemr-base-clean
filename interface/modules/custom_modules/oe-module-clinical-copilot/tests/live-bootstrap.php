<?php

/**
 * PHPUnit bootstrap for the opt-in live LLM eval suite (tests/Live/).
 *
 * Unlike tests/bootstrap.php (tests/Db), this needs no OpenEMR database or
 * globals.php -- the live path is just the module's LLM client factory plus
 * Guzzle/google-auth, all provided by the host project's vendor/. It loads the
 * host autoloader when present and registers the module's PSR-4 mapping as a
 * fallback so it also works under a production `composer install --no-dev`
 * (where the autoload-dev test namespaces are not dumped).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 5);
$hostAutoload = $repoRoot . '/vendor/autoload.php';
if (is_file($hostAutoload)) {
    require $hostAutoload;
}

$moduleSrc = dirname(__DIR__) . '/src';
$moduleTests = __DIR__;

spl_autoload_register(static function (string $class) use ($moduleSrc, $moduleTests): void {
    $prefixes = [
        'OpenEMR\\Modules\\ClinicalCopilot\\Tests\\' => $moduleTests,
        'OpenEMR\\Modules\\ClinicalCopilot\\' => $moduleSrc,
    ];

    foreach ($prefixes as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $base . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
