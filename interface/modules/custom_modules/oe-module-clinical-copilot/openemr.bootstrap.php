<?php

/**
 * Module loader entry point — called by the OpenEMR module system (ModulesApplication).
 *
 * Registers the PSR-4 namespace and wires the module's event subscriptions. Kept
 * intentionally thin: all behavior lives in src/Bootstrap.php so it is testable.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\ClinicalCopilot;

/**
 * @var \OpenEMR\Core\ModulesClassLoader $classLoader
 */
$classLoader->registerNamespaceIfNotExists(
    'OpenEMR\\Modules\\ClinicalCopilot\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src'
);

/**
 * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher Injected by the OpenEMR module loader
 */
$bootstrap = new Bootstrap($eventDispatcher, $GLOBALS['kernel'] ?? null);
$bootstrap->subscribeToEvents();
