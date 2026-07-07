<?php

/**
 * ModuleManagerListener — Laminas Module Manager hooks for enable/disable/unregister.
 *
 * Additivity (I9): unregister removes only the module's background_services row. The
 * mod_copilot_* tables are dropped by cleanup.sql on uninstall, which the Module Manager
 * gates behind an explicit confirmation with export-before-drop (T7 / OPEN-2).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        }
        return $currentActionStatus;
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\ClinicalCopilot\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    private function unregister($modId, $currentActionStatus): mixed
    {
        // Only the module's own worker row — never touch the ledger tables here (T7).
        sqlQuery("DELETE FROM `background_services` WHERE `name` = ?", ['mod_copilot_warm']);
        return $currentActionStatus;
    }
}
