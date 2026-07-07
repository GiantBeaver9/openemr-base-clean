<?php

/**
 * Clinical Co-Pilot background worker entry point.
 *
 * Registered against the `clinical_copilot_worker` background_services row
 * (`function` = clinicalCopilotWorkerRun, `require_once` = this file). The
 * host background-services runner requires this file and calls the function
 * by name on logged-in AJAX ticks / cron (build-notes.md "Background
 * services"). This is a stub only: it exists so U1 installs/enables cleanly
 * with a resolvable function. The real warm-worker sweep (appointment-window
 * scan, digest-miss regeneration, per-tick LLM budget) is built in U9 and
 * will replace this body without changing the background_services row.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

if (!function_exists('clinicalCopilotWorkerRun')) {
    /**
     * Stub worker tick. Returns true (no-op success) so the background
     * services framework records a clean run. I7: worker absence/failure
     * degrades warm-latency only, never read-path correctness — a no-op
     * tick is therefore always a safe implementation until U9 lands.
     *
     * @return bool
     */
    function clinicalCopilotWorkerRun(): bool
    {
        return true;
    }
}
