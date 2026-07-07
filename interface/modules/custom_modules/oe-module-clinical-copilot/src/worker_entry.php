<?php

/**
 * worker_entry.php — the global-namespace bridge the host background-service framework calls.
 *
 * The `background_services` row shipped in table.sql registers `mod_copilot_warm` with
 * function=`mod_copilot_warm_run` and require_once=this file. `BackgroundServiceRunner`
 * (driven by `library/ajax/execute_background_services.php` on the cron/AJAX tick) dispatches
 * background jobs by symbolic function name, so the entry point MUST be a global function — hence
 * this thin shim, mirroring the pattern in oe-module-faxsms/library/run_notifications.php.
 *
 * Everything past CLI-to-legacy translation lives in the typed
 * `OpenEMR\Modules\ClinicalCopilot\Worker`. This file only:
 *   - wires the runtime read path (DB-backed capabilities, doc store, Vertex reducer, verifier,
 *     DB trace writer) exactly as public/doc.php does, but with NO AuditLogger — a pre-clinic warm
 *     is a system pre-compute, not a physician view, so it must not emit a PHI-access audit entry;
 *   - wires the observability collaborators the alert evaluation reads (trace query + cadence config);
 *   - runs exactly one tick and catches \Throwable, so a worker error is logged and the host
 *     background-service loop is never broken (I7: the worker degrades latency, never correctness).
 *
 * The framework has already bootstrapped globals/site context before requiring this file, so (like
 * run_notifications.php) it does not re-require globals.php.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Doc\DbDocGateway;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\GlobalConfig;
use OpenEMR\Modules\ClinicalCopilot\Observability\CadenceConfigStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\DbTraceWriter;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceQuery;
use OpenEMR\Modules\ClinicalCopilot\Read\ReadPath;
use OpenEMR\Modules\ClinicalCopilot\Reduce\EgressRedactor;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptAssembler;
use OpenEMR\Modules\ClinicalCopilot\Reduce\Reducer;
use OpenEMR\Modules\ClinicalCopilot\Reduce\VertexClient;
use OpenEMR\Modules\ClinicalCopilot\SynthesisVersions;
use OpenEMR\Modules\ClinicalCopilot\Verify\Verifier;
use OpenEMR\Modules\ClinicalCopilot\Worker;

if (!function_exists('mod_copilot_warm_run')) {
    /**
     * Background-service entry point: run one Clinical Co-Pilot warm + alert tick.
     */
    function mod_copilot_warm_run(): void
    {
        $logger = new SystemLogger();
        try {
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

            // No AuditLogger: warming is a pre-compute, not a view (ReadPath defaults to NullAuditLogger).
            $readPath = new ReadPath(
                CapabilityFactory::db(),
                new DocStore(new DbDocGateway()),
                $reducer,
                new Verifier(),
                $traces,
            );

            $cadence = new CadenceConfigStore();
            $budget = $cadence->getInt('worker:per_tick_llm_budget', Worker::DEFAULT_PER_TICK_LLM_BUDGET);

            $worker = new Worker(
                $readPath,
                $traces,
                $logger,
                $budget,
                null,
                new TraceQuery(),
                $cadence,
            );

            $summary = $worker->runFromDb();
            $logger->debug('Clinical Co-Pilot warm tick complete', $summary);
        } catch (\Throwable $e) {
            // A worker error must never break the host background-service loop (I7).
            $logger->error('Clinical Co-Pilot warm tick failed', ['exception' => $e]);
        }
    }
}
