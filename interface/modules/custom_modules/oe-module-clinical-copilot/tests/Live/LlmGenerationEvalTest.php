<?php

/**
 * Live LLM generation eval: sends (query + data) to the real configured
 * provider and asserts the answer matches an expected pattern. Groundwork for
 * deployment smoke/eval runs.
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
use PHPUnit\Framework\Attributes\DataProvider;
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
 * Each scenario is a row in {@see self::scenarioProvider()}: a natural-language
 * `query` plus a short `data` series, and an expected-answer matcher
 * (CONTAINS / CONTAINS_ANY / CONTAINS_ALL / NOT_CONTAINS, all case-insensitive).
 * Example:
 *
 *   query: "What is the A1c trend?"   data: "8.0, 8.5, 9.0"
 *   expect: CONTAINS "increasing"
 *
 * Add a scenario by adding one row. Matchers use ANY-of / structural checks
 * where clinical phrasing legitimately varies, so the eval measures whether the
 * model got the answer RIGHT without being brittle about exact words.
 *
 * DOUBLE-GATED so it can never fire by accident in normal CI:
 *   1. `CLINICAL_COPILOT_LIVE_EVAL=1` must be set (explicit opt-in), and
 *   2. a provider must actually be configured (else the factory hands back
 *      {@see UnavailableLlmClient} and every scenario skips).
 * Run it on a deployment (or locally against synthetic-data credentials) with:
 *
 *   CLINICAL_COPILOT_LIVE_EVAL=1 \
 *   CLINICAL_COPILOT_GEMINI_API_KEY=... \
 *   vendor/bin/phpunit -c interface/modules/custom_modules/oe-module-clinical-copilot/phpunit-live.xml
 */
final class LlmGenerationEvalTest extends TestCase
{
    private const CONTAINS = 'contains';
    private const CONTAINS_ANY = 'contains_any';
    private const CONTAINS_ALL = 'contains_all';
    private const NOT_CONTAINS = 'not_contains';

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

    /**
     * @param list<string> $expected
     */
    #[DataProvider('scenarioProvider')]
    public function testScenario(string $query, string $data, string $matcher, array $expected): void
    {
        $answer = $this->ask($query, $data);

        $this->assertAnswerMatches($answer, $matcher, $expected, $query, $data);
    }

    /**
     * Each row: query, data series, matcher, expected token(s). Ordered roughly
     * easy -> hard: unambiguous trends first, then classification against
     * reference ranges, then goal assessment and simple numeric reasoning.
     *
     * @return array<string, array{string, string, string, list<string>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function scenarioProvider(): array
    {
        return [
            'a1c trend increasing' => [
                'What is the A1c trend?', '8.0, 8.5, 9.0',
                self::CONTAINS, ['increasing'],
            ],
            'a1c trend decreasing' => [
                'What is the A1c trend?', '9.2, 7.8, 6.5',
                self::CONTAINS_ANY, ['decreasing', 'declining', 'improving', 'downward'],
            ],
            'a1c trend stable' => [
                'What is the A1c trend?', '7.0, 7.1, 7.0',
                self::CONTAINS_ANY, ['stable', 'unchanged', 'flat', 'no significant'],
            ],
            'fasting glucose elevated' => [
                'Is this fasting glucose within the normal range?', '185 mg/dL',
                self::CONTAINS_ANY, ['no', 'not', 'high', 'elevated', 'above'],
            ],
            'blood pressure hypertension' => [
                'How would you classify this blood pressure?', '152/96 mmHg',
                self::CONTAINS_ANY, ['hypertension', 'elevated', 'high', 'stage'],
            ],
            'weight loss trend' => [
                'Describe the weight trend.', '212, 204, 197 lb',
                self::CONTAINS_ANY, ['decreasing', 'declining', 'loss', 'losing', 'downward', 'down'],
            ],
            'a1c above goal' => [
                'Is this A1c at the usual adult diabetes goal of under 7%?', '9.0%',
                self::CONTAINS_ANY, ['no', 'not', 'above', 'poor', 'uncontrolled'],
            ],
            'ldl high' => [
                'Is this LDL cholesterol high?', '190 mg/dL',
                self::CONTAINS_ANY, ['high', 'elevated', 'above'],
            ],
            'tsh hypothyroid' => [
                'Does this TSH suggest an underactive or overactive thyroid?', '8.5 mIU/L (reference 0.4-4.0)',
                self::CONTAINS, ['hypo'],
            ],
            'highest value in series' => [
                'Which single value in this series is the highest?', '8.0, 8.5, 9.0',
                self::CONTAINS, ['9'],
            ],
        ];
    }

    /**
     * Sends one (query + data) turn and returns the model's answer text.
     *
     * Uses a `{ "answer": string }` structured-output contract so the reply is
     * a clean, parseable string, and asserts the response is a genuine metered
     * result (non-empty, model version set, token counts > 0) so a silently
     * degraded/empty reply fails loudly rather than passing a vacuous match.
     */
    private function ask(string $query, string $data): string
    {
        $request = new PromptRequest(
            systemInstructions:
                'You are a clinical data assistant for an endocrinology clinic. You are given a short '
                . 'patient data series and a question about it. Answer concisely in plain clinical language. '
                . 'When describing a trend, state whether it is increasing, decreasing, or stable. '
                . 'Respond only with JSON matching the schema.',
            userContent: $query . "\n\nData: " . $data,
            responseSchema: [
                'type' => 'object',
                'properties' => ['answer' => ['type' => 'string']],
                'required' => ['answer'],
            ],
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
            $decoded = json_decode($response->rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail('model output was not valid JSON: ' . $response->rawJson);
        }

        self::assertIsArray($decoded);
        $answer = $decoded['answer'] ?? null;
        self::assertIsString($answer, 'response JSON had no string "answer" field: ' . $response->rawJson);

        return $answer;
    }

    /**
     * @param list<string> $expected
     */
    private function assertAnswerMatches(
        string $answer,
        string $matcher,
        array $expected,
        string $query,
        string $data,
    ): void {
        $context = sprintf(
            "query=%s | data=%s | expected %s [%s] | model answered: %s",
            $query,
            $data,
            $matcher,
            implode(', ', $expected),
            $answer,
        );
        $haystack = strtolower($answer);

        switch ($matcher) {
            case self::CONTAINS:
                self::assertStringContainsStringIgnoringCase($expected[0], $answer, $context);
                return;

            case self::CONTAINS_ALL:
                foreach ($expected as $needle) {
                    self::assertStringContainsStringIgnoringCase($needle, $answer, $context);
                }
                return;

            case self::CONTAINS_ANY:
                foreach ($expected as $needle) {
                    if (str_contains($haystack, strtolower($needle))) {
                        // Surface the passing assertion count so the test is not "risky".
                        self::assertTrue(true, $context);
                        return;
                    }
                }
                self::fail($context);

            case self::NOT_CONTAINS:
                foreach ($expected as $needle) {
                    self::assertStringNotContainsStringIgnoringCase($needle, $answer, $context);
                }
                return;

            default:
                self::fail('unknown matcher: ' . $matcher);
        }
    }

    private static function model(): string
    {
        $override = trim((string)getenv('CLINICAL_COPILOT_EVAL_MODEL'));

        return $override !== '' ? $override : self::DEFAULT_MODEL;
    }
}
