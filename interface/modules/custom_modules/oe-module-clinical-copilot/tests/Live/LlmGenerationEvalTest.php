<?php

/**
 * Live LLM generation eval: calls the real configured provider and asserts on
 * real outputs. Groundwork for deployment smoke/eval runs.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Live;

use OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory;
use OpenEMR\Modules\ClinicalCopilot\ReadPath\UnavailableLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\PromptRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unlike everything under `tests/Isolated` (which binds stub clients and never
 * touches the network -- build-notes.md: "No live LLM calls anywhere in
 * tests"), this suite exercises the ACTUAL deployment path end to end:
 * {@see LlmClientFactory::create()} selects whichever provider the environment
 * configures (Vertex, then the Gemini API-key fast-path, then unavailable),
 * and we send real prompts through {@see LlmClientInterface::generateStructured()}
 * and assert on what comes back.
 *
 * It is DOUBLE-GATED so it can never fire by accident in normal CI:
 *   1. `CLINICAL_COPILOT_LIVE_EVAL=1` must be set (explicit opt-in), and
 *   2. a provider must actually be configured (otherwise the factory hands
 *      back {@see UnavailableLlmClient} and we skip).
 * With neither condition met the whole suite is skipped -- green, no calls,
 * no cost. On a deployment where credentials are present you run it with the
 * opt-in flag to confirm the model is reachable and behaving:
 *
 *   CLINICAL_COPILOT_LIVE_EVAL=1 \
 *   CLINICAL_COPILOT_GEMINI_API_KEY=... \
 *   vendor/bin/phpunit -c interface/modules/custom_modules/oe-module-clinical-copilot/phpunit-live.xml
 *
 * Cases span difficulty on purpose: the arithmetic and classification cases
 * are deterministic at temperature 0 and should pass every run; the extraction
 * and free-list cases assert structural/semantic properties that tolerate the
 * model's wording latitude. Add a case by writing one more `test*` method that
 * calls {@see self::generate()} and asserts on the decoded output.
 */
final class LlmGenerationEvalTest extends TestCase
{
    /** Cheap, fast default; override per deployment with CLINICAL_COPILOT_EVAL_MODEL. */
    private const DEFAULT_MODEL = 'gemini-2.5-flash';

    /** Generous enough that a "thinking" model does not exhaust the budget before emitting JSON. */
    private const MAX_OUTPUT_TOKENS = 8192;

    private LlmClientInterface $client;

    protected function setUp(): void
    {
        if (trim((string)getenv('CLINICAL_COPILOT_LIVE_EVAL')) !== '1') {
            self::markTestSkipped(
                'Live LLM eval is opt-in: set CLINICAL_COPILOT_LIVE_EVAL=1 (plus provider credentials) to run it.'
            );
        }

        $client = LlmClientFactory::create();
        if ($client instanceof UnavailableLlmClient) {
            self::markTestSkipped(
                'No LLM provider configured: set CLINICAL_COPILOT_GCP_PROJECT_ID (Vertex) '
                . 'or CLINICAL_COPILOT_GEMINI_API_KEY (dev/test fast-path).'
            );
        }

        $this->client = $client;
    }

    /** Deterministic at temperature 0 -- must be exact every run. */
    public function testArithmeticIsExact(): void
    {
        $out = $this->generate(
            'You are a precise calculator. Respond only with JSON matching the schema.',
            'Compute 17 + 25.',
            ['type' => 'object', 'properties' => ['answer' => ['type' => 'integer']], 'required' => ['answer']],
        );

        self::assertIsArray($out);
        self::assertSame(42, $out['answer'] ?? null);
    }

    /** Deterministic factual recall -- tolerant of surrounding wording. */
    public function testFactualRecall(): void
    {
        $out = $this->generate(
            'Answer factually. Respond only with JSON matching the schema.',
            'In which city is the Eiffel Tower located?',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        );

        self::assertIsArray($out);
        self::assertIsString($out['city'] ?? null);
        self::assertStringContainsStringIgnoringCase('paris', (string)$out['city']);
    }

    /** Structured extraction -- the numeric value is pinned, wording is not. */
    public function testStructuredExtractionFromClinicalSentence(): void
    {
        $out = $this->generate(
            'Extract the single lab result described by the user as JSON matching the schema.',
            'Most recent HbA1c: 6.4 percent, drawn 2026-01-05.',
            [
                'type' => 'object',
                'properties' => [
                    'analyte' => ['type' => 'string'],
                    'value' => ['type' => 'number'],
                    'unit' => ['type' => 'string'],
                ],
                'required' => ['analyte', 'value', 'unit'],
            ],
        );

        self::assertIsArray($out);
        self::assertEqualsWithDelta(6.4, (float)($out['value'] ?? 0), 0.001);
        self::assertStringContainsStringIgnoringCase('a1c', (string)($out['analyte'] ?? ''));
        self::assertNotSame('', trim((string)($out['unit'] ?? '')));
    }

    /** Constrained (enum) classification -- one of exactly two labels. */
    public function testBinaryClassification(): void
    {
        $out = $this->generate(
            'Classify the reading. Respond only with JSON matching the schema.',
            'For an adult at rest, a blood pressure reading of 152/96 mmHg is:',
            [
                'type' => 'object',
                'properties' => ['category' => ['type' => 'string', 'enum' => ['normal', 'elevated']]],
                'required' => ['category'],
            ],
        );

        self::assertIsArray($out);
        self::assertSame('elevated', $out['category'] ?? null);
    }

    /** Hardest / least deterministic -- assert SHAPE, not exact content. */
    public function testFreeListIsWellFormed(): void
    {
        $out = $this->generate(
            'Respond only with a JSON array of 2 to 3 short strings matching the schema.',
            'List up to three common differential considerations for acute chest pain in an adult.',
            ['type' => 'array', 'items' => ['type' => 'string']],
        );

        self::assertIsArray($out);
        self::assertGreaterThanOrEqual(1, count($out));
        self::assertLessThanOrEqual(5, count($out));
        foreach ($out as $item) {
            self::assertIsString($item);
            self::assertNotSame('', trim($item));
        }
    }

    /**
     * Issues one real structured-generation call and returns the decoded JSON.
     *
     * Also asserts the response is a genuine metered result (non-empty text,
     * a model version, and both token counts > 0) so a silently degraded or
     * empty reply fails loudly rather than passing a vacuous decode. Any
     * provider/transport failure surfaces as a thrown LlmUnavailableException
     * -- exactly the signal a deployment smoke run wants.
     *
     * @param array<string, mixed> $responseSchema
     *
     * @return mixed decoded JSON (associative array for object schemas, list for array schemas)
     */
    private function generate(string $systemInstructions, string $userContent, array $responseSchema): mixed
    {
        $request = new PromptRequest(
            systemInstructions: $systemInstructions,
            userContent: $userContent,
            responseSchema: $responseSchema,
            model: self::model(),
            promptVersion: 'live-eval-v1',
            temperature: 0.0,
            maxOutputTokens: self::MAX_OUTPUT_TOKENS,
        );

        $response = $this->client->generateStructured($request);

        self::assertNotSame('', $response->rawJson, 'model returned an empty response');
        self::assertNotSame('', $response->modelVersion);
        self::assertGreaterThan(0, $response->tokensIn, 'expected prompt tokens to be metered');
        self::assertGreaterThan(0, $response->tokensOut, 'expected output tokens to be metered');
        self::assertGreaterThanOrEqual(0, $response->latencyMs);

        try {
            return json_decode($response->rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail('model output was not valid JSON: ' . $response->rawJson);
        }
    }

    private static function model(): string
    {
        $override = trim((string)getenv('CLINICAL_COPILOT_EVAL_MODEL'));

        return $override !== '' ? $override : self::DEFAULT_MODEL;
    }
}
