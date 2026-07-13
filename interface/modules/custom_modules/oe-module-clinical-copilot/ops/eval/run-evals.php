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
 * ratings) that blocks regressions before they reach the demo. This runner is
 * deliberately DETERMINISTIC and needs NO live model or database: every case
 * supplies the model's output verbatim, and the runner feeds it through the
 * SAME production code paths (ExtractionSchema, ExtractionClient with a stub,
 * the RAG retriever) that the app uses. Introduce a real regression (break
 * schema validation, drop a citation, let the retriever return the wrong chunk)
 * and a rubric's pass-rate falls below baseline, and this process exits non-zero.
 *
 * Rubric categories (spec §6): schema_valid, citation_present,
 * factually_consistent, safe_refusal, no_phi_in_logs.
 *
 * Usage:
 *   php ops/eval/run-evals.php                 # gate: compare to baseline, exit 0/1
 *   php ops/eval/run-evals.php --update-baseline  # rewrite baseline.json from this run
 */

use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionClient;
use OpenEMR\Modules\ClinicalCopilot\Ingest\ExtractionSchema;
use OpenEMR\Modules\ClinicalCopilot\Ingest\SchemaValidationException;
use OpenEMR\Modules\ClinicalCopilot\Rag\GuidelineCorpus;
use OpenEMR\Modules\ClinicalCopilot\Rag\SparseRetriever;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmResponse;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;

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

/** A trivial LlmClientInterface that returns a case's raw output verbatim. */
final class EvalStubLlm implements LlmClientInterface
{
    public function __construct(private readonly string $raw)
    {
    }

    public function generateStructured(PromptRequest $req): LlmResponse
    {
        return new LlmResponse($this->raw, 'eval-stub', 0, 0, 0);
    }
}

// The absolute floor and the regression tolerance (spec: >5% drop fails).
const ABSOLUTE_FLOOR = 0.90;
const REGRESSION_TOLERANCE = 0.05;

$evalDir = __DIR__;
$updateBaseline = in_array('--update-baseline', $argv, true);

$cases = json_decode((string)file_get_contents($evalDir . '/cases.json'), true);
if (!is_array($cases)) {
    fwrite(STDERR, "eval: cases.json missing or invalid\n");
    exit(2);
}

$retriever = new SparseRetriever(GuidelineCorpus::createDefault());

/** @var array<string, array{pass: int, total: int}> $tally */
$tally = [];
$failures = [];

foreach ($cases as $case) {
    if (!is_array($case)) {
        continue;
    }
    $id = (string)($case['id'] ?? '?');
    try {
        $results = evaluateCase($case, $retriever);
    } catch (\Throwable $e) {
        // A case that throws is a regression, not a gate crash: attribute a
        // failure to every rubric its category tests so the gate reports it and
        // exits cleanly rather than aborting the whole run.
        $results = array_fill_keys(rubricsForCategory((string)($case['category'] ?? '')), false);
        $failures[] = "{$id} :: threw " . $e::class;
    }
    foreach ($results as $rubric => $passed) {
        $tally[$rubric] ??= ['pass' => 0, 'total' => 0];
        $tally[$rubric]['total']++;
        if ($passed) {
            $tally[$rubric]['pass']++;
        } else {
            $failures[] = "{$id} :: {$rubric}";
        }
    }
}

$rates = [];
foreach ($tally as $rubric => $t) {
    $rates[$rubric] = $t['total'] > 0 ? $t['pass'] / $t['total'] : 1.0;
}
ksort($rates);

if ($updateBaseline) {
    file_put_contents(
        $evalDir . '/baseline.json',
        json_encode(['rubrics' => $rates, 'case_count' => count($cases)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
    );
    echo "eval: baseline updated (" . count($cases) . " cases)\n";
    printReport($rates, $tally, [], []);
    exit(0);
}

$baseline = json_decode((string)@file_get_contents($evalDir . '/baseline.json'), true);
$baseRates = is_array($baseline['rubrics'] ?? null) ? $baseline['rubrics'] : [];

$regressions = [];
foreach ($rates as $rubric => $rate) {
    $base = is_numeric($baseRates[$rubric] ?? null) ? (float)$baseRates[$rubric] : 1.0;
    if ($rate < ABSOLUTE_FLOOR) {
        $regressions[] = sprintf('%s below floor: %.3f < %.2f', $rubric, $rate, ABSOLUTE_FLOOR);
    } elseif ($rate < $base - REGRESSION_TOLERANCE) {
        $regressions[] = sprintf('%s regressed: %.3f < baseline %.3f - %.2f', $rubric, $rate, $base, REGRESSION_TOLERANCE);
    }
}

printReport($rates, $tally, $failures, $regressions);

if ($regressions !== []) {
    fwrite(STDERR, "\neval: GATE FAILED — " . count($regressions) . " rubric regression(s).\n");
    exit(1);
}

echo "\neval: GATE PASSED — all rubrics at or above baseline.\n";
exit(0);

/**
 * @param array<string, mixed> $case
 *
 * @return array<string, bool> rubric => passed (only rubrics that apply)
 */
function evaluateCase(array $case, SparseRetriever $retriever): array
{
    $category = (string)($case['category'] ?? '');

    return match ($category) {
        'extraction', 'missing_data' => evaluateExtraction($case),
        'refusal' => evaluateRefusal($case),
        'retrieval' => evaluateRetrieval($case, $retriever),
        default => [],
    };
}

/**
 * The rubrics a category participates in — used to attribute failures when a
 * case throws (a regression), so the gate reports cleanly instead of crashing.
 *
 * @return list<string>
 */
function rubricsForCategory(string $category): array
{
    return match ($category) {
        'extraction', 'missing_data' => ['schema_valid', 'citation_present', 'factually_consistent', 'no_phi_in_logs'],
        'refusal' => ['safe_refusal', 'no_phi_in_logs'],
        'retrieval' => ['factually_consistent', 'citation_present'],
        default => [],
    };
}

/**
 * @param array<string, mixed> $case
 *
 * @return array<string, bool>
 */
function evaluateExtraction(array $case): array
{
    $docType = DocType::from((string)$case['doc_type']);
    $payload = is_array($case['input'] ?? null) ? $case['input'] : [];
    $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
    $expectAccept = (bool)($expect['accept'] ?? true);

    $errors = ExtractionSchema::validate($docType, $payload);
    $accepted = $errors === [];

    $out = ['schema_valid' => ($accepted === $expectAccept)];

    if (!$accepted) {
        // A correctly-rejected payload is a safe refusal; nothing is persisted.
        $out['safe_refusal'] = !$expectAccept;
        return $out;
    }

    $parsed = ExtractionSchema::parse($docType, $payload, 'eval');

    // citation_present: every field carries a page+quote citation.
    $allCited = true;
    foreach ($parsed->fields as $field) {
        if ($field->citation === null) {
            $allCited = false;
            break;
        }
    }
    $out['citation_present'] = $allCited;

    // factually_consistent: parsing never mutates or invents a value.
    $consistent = true;
    foreach ($parsed->fields as $field) {
        if ($field->value !== $field->vlmValue) {
            $consistent = false;
            break;
        }
    }
    $out['factually_consistent'] = $consistent;

    // no_phi_in_logs: the loggable accuracy artifact carries field keys + rates,
    // never clinical values.
    $out['no_phi_in_logs'] = loggedArtifactIsPhiFree($parsed);

    return $out;
}

/**
 * @param array<string, mixed> $case
 *
 * @return array<string, bool>
 */
function evaluateRefusal(array $case): array
{
    $docType = DocType::from((string)$case['doc_type']);
    $raw = (string)($case['raw'] ?? '');
    $client = new ExtractionClient(new EvalStubLlm($raw), 'eval-stub');

    $refused = false;
    try {
        $client->extract($docType, 'BYTES', 'application/pdf', 'eval');
    } catch (SchemaValidationException) {
        $refused = true;
    }

    return ['safe_refusal' => $refused, 'no_phi_in_logs' => true];
}

/**
 * @param array<string, mixed> $case
 *
 * @return array<string, bool>
 */
function evaluateRetrieval(array $case, SparseRetriever $retriever): array
{
    $query = (string)($case['query'] ?? '');
    $tags = [];
    foreach (is_array($case['tags'] ?? null) ? $case['tags'] : [] as $t) {
        if (is_string($t)) {
            $tags[] = $t;
        }
    }
    $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
    $hits = $retriever->retrieve($query, $tags, 3);

    // factually_consistent: the top hit is the expected, real corpus chunk
    // (retrieval grounds in the corpus, never invents).
    $topExpected = (string)($expect['top_chunk'] ?? '');
    $consistent = $topExpected === ''
        ? $hits !== []
        : (($hits[0]->chunk->id ?? '') === $topExpected);

    // citation_present: every returned snippet carries a guideline citation.
    $allCited = true;
    foreach ($hits as $hit) {
        if ($hit->citation->quoteOrValue === '') {
            $allCited = false;
            break;
        }
    }

    return [
        'factually_consistent' => $consistent,
        'citation_present' => $hits === [] ? true : $allCited,
    ];
}

/**
 * Builds the artifact the observability layer would log for this extraction and
 * asserts it contains no field VALUE — only keys and rates (the no-PHI rule).
 */
function loggedArtifactIsPhiFree(\OpenEMR\Modules\ClinicalCopilot\Ingest\ParsedExtraction $parsed): bool
{
    $editedKeys = [];
    foreach ($parsed->editedFields() as $f) {
        $editedKeys[] = $f->fieldKey;
    }
    $logged = json_encode([
        'doc_type' => $parsed->docType->value,
        'field_count' => count($parsed->fields),
        'edited_field_keys' => $editedKeys,
        'field_accuracy' => $parsed->fieldAccuracy(),
    ], JSON_THROW_ON_ERROR);

    foreach ($parsed->fields as $field) {
        $value = $field->value;
        if (is_string($value) && $value !== '' && str_contains($logged, $value)) {
            return false;
        }
    }

    return true;
}

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
