<?php

/**
 * GeminiChatContentContract: response-parsing coverage for the chat-surface
 * Gemini `generateContent` function-calling mapping.
 *
 * The chat path decodes a raw Gemini response into three things a caller acts
 * on: the ordered tool-call requests the model emitted, the visible text (when
 * the model answers instead of calling a tool), and the in/out token counts for
 * cost accounting. Every method under test is a pure, static function of the
 * decoded JSON array -- no network, no DB, no clock -- so each case pins an
 * exact expected output for a specific shape of Gemini response.
 *
 * Cases are grouped by difficulty:
 *   - "happy path"  : well-formed responses that must parse every time
 *   - "edge"        : malformed / partial responses the provider can legally
 *                     return (empty parts, missing keys, wrong types)
 *   - "adversarial" : shapes that would trip a naive parser (leading reasoning
 *                     parts, non-int token counts, interleaved text + calls)
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Chat\Llm;

use OpenEMR\Modules\ClinicalCopilot\Chat\Llm\GeminiApiChatLlmClient;
use OpenEMR\Modules\ClinicalCopilot\Chat\Tool\ToolCallRequest;
use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class GeminiChatContentContractTest extends TestCase
{
    /**
     * The contract methods are private statics on a trait; exercise them via
     * the concrete client that uses the trait, exactly as the existing
     * reduce-path {@see \OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Reduce\GeminiGenerateContentContractTest}
     * does. Reflection keeps the trait's visibility intact for production while
     * letting the parser be tested in isolation from Guzzle transport.
     *
     * @param array<mixed> $args
     */
    private function invoke(string $method, array $args): mixed
    {
        $reflected = new ReflectionMethod(GeminiApiChatLlmClient::class, $method);
        $reflected->setAccessible(true);

        return $reflected->invokeArgs(null, $args);
    }

    // ---------------------------------------------------------------------
    // extractParts() -- pull the first candidate's content parts, or fail
    // ---------------------------------------------------------------------

    /** happy path: a normal candidate yields its parts verbatim. */
    public function testExtractPartsReturnsFirstCandidateParts(): void
    {
        $parts = [['text' => 'hello'], ['functionCall' => ['name' => 'lookup_labs', 'args' => []]]];
        $decoded = ['candidates' => [['content' => ['parts' => $parts]]]];

        self::assertSame($parts, $this->invoke('extractParts', [$decoded]));
    }

    /** happy path: only the FIRST candidate is read even when several exist. */
    public function testExtractPartsReadsOnlyFirstCandidate(): void
    {
        $decoded = [
            'candidates' => [
                ['content' => ['parts' => [['text' => 'first']]]],
                ['content' => ['parts' => [['text' => 'second']]]],
            ],
        ];

        self::assertSame([['text' => 'first']], $this->invoke('extractParts', [$decoded]));
    }

    /**
     * edge: every response shape that must be rejected as "no usable parts".
     *
     * @param array<string, mixed> $decoded
     */
    #[DataProvider('unusablePartsProvider')]
    public function testExtractPartsThrowsOnUnusableShapes(array $decoded): void
    {
        $this->expectException(LlmUnavailableException::class);
        $this->invoke('extractParts', [$decoded]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unusablePartsProvider(): array
    {
        return [
            'candidates key missing' => [[]],
            'candidates empty' => [['candidates' => []]],
            'candidates not a list' => [['candidates' => 'nope']],
            'first candidate not array' => [['candidates' => ['scalar']]],
            'content missing' => [['candidates' => [['finishReason' => 'STOP']]]],
            'parts missing' => [['candidates' => [['content' => ['role' => 'model']]]]],
            'parts empty' => [['candidates' => [['content' => ['parts' => []]]]]],
            'parts not a list' => [['candidates' => [['content' => ['parts' => 'nope']]]]],
        ];
    }

    // ---------------------------------------------------------------------
    // extractToolCalls() -- map functionCall parts to ToolCallRequest objects
    // ---------------------------------------------------------------------

    /** happy path: a single well-formed functionCall becomes one request. */
    public function testExtractToolCallsMapsSingleCall(): void
    {
        $parts = [['functionCall' => ['name' => 'lookup_labs', 'args' => ['loinc' => '4548-4']]]];

        /** @var list<ToolCallRequest> $calls */
        $calls = $this->invoke('extractToolCalls', [$parts]);

        self::assertCount(1, $calls);
        self::assertSame('lookup_labs', $calls[0]->name);
        self::assertSame(['loinc' => '4548-4'], $calls[0]->arguments);
    }

    /** happy path: order of multiple calls is preserved. */
    public function testExtractToolCallsPreservesOrder(): void
    {
        $parts = [
            ['functionCall' => ['name' => 'first_tool', 'args' => []]],
            ['functionCall' => ['name' => 'second_tool', 'args' => ['k' => 1]]],
        ];

        /** @var list<ToolCallRequest> $calls */
        $calls = $this->invoke('extractToolCalls', [$parts]);

        self::assertSame(['first_tool', 'second_tool'], array_map(static fn (ToolCallRequest $c): string => $c->name, $calls));
    }

    /** edge: a call with no args key defaults to an empty argument array. */
    public function testExtractToolCallsDefaultsMissingArgsToEmptyArray(): void
    {
        $parts = [['functionCall' => ['name' => 'no_args_tool']]];

        /** @var list<ToolCallRequest> $calls */
        $calls = $this->invoke('extractToolCalls', [$parts]);

        self::assertCount(1, $calls);
        self::assertSame([], $calls[0]->arguments);
    }

    /** edge: non-array args (a malformed provider response) coerces to []. */
    public function testExtractToolCallsCoercesNonArrayArgsToEmptyArray(): void
    {
        $parts = [['functionCall' => ['name' => 'weird_tool', 'args' => 'not-an-object']]];

        /** @var list<ToolCallRequest> $calls */
        $calls = $this->invoke('extractToolCalls', [$parts]);

        self::assertSame([], $calls[0]->arguments);
    }

    /** adversarial: text-only parts contribute no tool calls. */
    public function testExtractToolCallsIgnoresTextParts(): void
    {
        $parts = [['text' => 'just prose, no call']];

        self::assertSame([], $this->invoke('extractToolCalls', [$parts]));
    }

    /** adversarial: interleaved text + calls yields only the calls, in order. */
    public function testExtractToolCallsSkipsInterleavedTextAndNamelessCalls(): void
    {
        $parts = [
            ['text' => 'let me check'],
            ['functionCall' => ['name' => '', 'args' => ['ignored' => true]]], // empty name -> skipped
            ['functionCall' => ['name' => 'real_tool', 'args' => ['ok' => 1]]],
            ['functionCall' => 'not-an-array'],                                  // malformed -> skipped
        ];

        /** @var list<ToolCallRequest> $calls */
        $calls = $this->invoke('extractToolCalls', [$parts]);

        self::assertCount(1, $calls);
        self::assertSame('real_tool', $calls[0]->name);
    }

    // ---------------------------------------------------------------------
    // extractText() -- first non-empty text part, else fail
    // ---------------------------------------------------------------------

    /** happy path: the first non-empty text is returned. */
    public function testExtractTextReturnsFirstNonEmptyText(): void
    {
        $parts = [['text' => '{"claims":[]}']];

        self::assertSame('{"claims":[]}', $this->invoke('extractText', [$parts]));
    }

    /** adversarial: a leading empty/reasoning part is skipped for the real text. */
    public function testExtractTextSkipsEmptyAndFunctionCallParts(): void
    {
        $parts = [
            ['text' => ''],
            ['functionCall' => ['name' => 'lookup_labs', 'args' => []]],
            ['text' => 'the answer'],
        ];

        self::assertSame('the answer', $this->invoke('extractText', [$parts]));
    }

    /** edge: no text part at all (a pure tool-call turn) is an error for extractText. */
    public function testExtractTextThrowsWhenNoTextPresent(): void
    {
        $parts = [['functionCall' => ['name' => 'lookup_labs', 'args' => []]]];

        $this->expectException(LlmUnavailableException::class);
        $this->invoke('extractText', [$parts]);
    }

    // ---------------------------------------------------------------------
    // extractTokenCount() -- defensive int extraction for cost accounting
    // ---------------------------------------------------------------------

    /** happy path: integer counts for either field are returned as-is. */
    public function testExtractTokenCountReadsIntegerFields(): void
    {
        $decoded = ['usageMetadata' => ['promptTokenCount' => 128, 'candidatesTokenCount' => 64]];

        self::assertSame(128, $this->invoke('extractTokenCount', [$decoded, 'promptTokenCount']));
        self::assertSame(64, $this->invoke('extractTokenCount', [$decoded, 'candidatesTokenCount']));
    }

    /**
     * edge/adversarial: anything that is not an integer count degrades to 0
     * rather than throwing -- token accounting must never break response parsing.
     *
     * @param array<string, mixed> $decoded
     */
    #[DataProvider('nonIntegerTokenProvider')]
    public function testExtractTokenCountDefaultsToZero(array $decoded): void
    {
        self::assertSame(0, $this->invoke('extractTokenCount', [$decoded, 'promptTokenCount']));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function nonIntegerTokenProvider(): array
    {
        return [
            'usageMetadata missing' => [[]],
            'usageMetadata not array' => [['usageMetadata' => 'nope']],
            'field missing' => [['usageMetadata' => ['candidatesTokenCount' => 5]]],
            'field is numeric string' => [['usageMetadata' => ['promptTokenCount' => '128']]],
            'field is float' => [['usageMetadata' => ['promptTokenCount' => 1.5]]],
            'field is null' => [['usageMetadata' => ['promptTokenCount' => null]]],
        ];
    }
}
