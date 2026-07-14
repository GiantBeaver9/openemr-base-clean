<?php

/**
 * Week 2 eval gate runner — 50-case golden set, boolean rubrics, exit-code gate.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

/*
 * The spec's HARD GATE: a 50-case golden set with BOOLEAN rubrics (not 1-10
 * ratings) that blocks regressions before they reach the demo. The evaluation
 * engine lives in EvalGate (ops/eval/EvalGate.php) so the SAME logic backs both
 * this CLI gate AND the dashboard's "Run evals" button — this file is just the
 * CLI face: argument handling, the human report, baseline update, exit code.
 *
 * It stays DETERMINISTIC and needs NO live model or database: every case
 * supplies the model's output verbatim and is fed through the SAME production
 * code paths (ExtractionSchema, ExtractionClient with a stub, the RAG
 * retriever) the app uses. Introduce a real regression (break schema
 * validation, drop a citation, let the retriever return the wrong chunk) and a
 * rubric's pass-rate falls below baseline, and this process exits non-zero.
 *
 * Rubric categories (spec §6): schema_valid, citation_present,
 * factually_consistent, safe_refusal, no_phi_in_logs.
 *
 * Usage:
 *   php ops/eval/run-evals.php                 # gate: compare to baseline, exit 0/1
 *   php ops/eval/run-evals.php --update-baseline  # rewrite baseline.json from this run
 */

use OpenEMR\Modules\ClinicalCopilot\Ops\Eval\EvalGate;

$moduleRoot = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'OpenEMR\\Modules\\ClinicalCopilot\\';
    if (str_starts_with($class, $prefix)) {
        $file = $moduleRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// EvalGate lives under ops/eval/ (not src/), so require it explicitly.
require_once __DIR__ . '/EvalGate.php';

$evalDir = __DIR__;
$updateBaseline = in_array('--update-baseline', $argv, true);

try {
    $result = (new EvalGate($evalDir))->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'eval: ' . $e->getMessage() . "\n");
    exit(2);
}

$rates = $result['rates'];
$tally = $result['tally'];

if ($updateBaseline) {
    file_put_contents(
        $evalDir . '/baseline.json',
        json_encode(['rubrics' => $rates, 'case_count' => $result['case_count']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
    );
    echo "eval: baseline updated (" . $result['case_count'] . " cases)\n";
    printReport($rates, $tally, [], []);
    exit(0);
}

printReport($rates, $tally, $result['failures'], $result['regressions']);

if (!$result['passed']) {
    fwrite(STDERR, "\neval: GATE FAILED — " . count($result['regressions']) . " rubric regression(s).\n");
    exit(1);
}

echo "\neval: GATE PASSED — all rubrics at or above baseline.\n";
exit(0);

/**
 * @param array<string, float> $rates
 * @param array<string, array{pass: int, total: int}> $tally
 * @param list<string> $failures
 * @param list<string> $regressions
 */
function printReport(array $rates, array $tally, array $failures, array $regressions): void
{
    echo "\nClinical Co-Pilot — Week 2 eval gate\n";
    echo str_repeat('-', 52) . "\n";
    foreach ($rates as $rubric => $rate) {
        $t = $tally[$rubric] ?? ['pass' => 0, 'total' => 0];
        printf("  %-22s %5.1f%%  (%d/%d)\n", $rubric, $rate * 100, $t['pass'], $t['total']);
    }
    if ($failures !== []) {
        echo "\n  failing cases:\n";
        foreach ($failures as $f) {
            echo "    - {$f}\n";
        }
    }
    if ($regressions !== []) {
        echo "\n  regressions:\n";
        foreach ($regressions as $r) {
            echo "    ! {$r}\n";
        }
    }
}
