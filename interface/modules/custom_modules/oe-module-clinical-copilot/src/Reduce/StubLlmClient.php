<?php

/**
 * StubLlmClient — the deterministic LlmClient for isolated tests (no network, no creds).
 *
 * Three modes, all configured at construction:
 *  - canned structured response: returns a fixed LlmResponse (the reduce/verify happy path);
 *  - tool-call response: returns proposed function calls (exercises the U11 agent loop);
 *  - "down": every call throws LlmUnavailableException (the I6 degradation path).
 * It also records the last request it received so redaction tests can assert that no direct
 * identifier ever reached the outbound payload.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

final class StubLlmClient implements LlmClient
{
    private ?LlmRequest $lastRequest = null;
    private int $generateCalls = 0;
    private int $countTokensCalls = 0;

    private function __construct(
        private readonly ?LlmResponse $response,
        private readonly bool $down,
        private readonly int $tokenCount,
    ) {
    }

    /**
     * A client that returns a fixed structured payload on every generate().
     *
     * @param array<string, mixed> $json
     */
    public static function withCannedJson(
        array $json,
        string $model = 'stub-model@1',
        int $tokensIn = 100,
        int $tokensOut = 50,
        int $latencyMs = 5,
    ): self {
        return new self(new LlmResponse($json, $tokensIn, $tokensOut, $model, $latencyMs), false, $tokensIn + $tokensOut);
    }

    /**
     * A client that returns a ready-made LlmResponse (allows tool-call responses).
     */
    public static function withResponse(LlmResponse $response): self
    {
        return new self($response, false, $response->tokensIn + $response->tokensOut);
    }

    /**
     * A client whose generate() proposes tool calls instead of a final answer (U11).
     *
     * @param list<array{name: string, args: array<string, mixed>}> $toolCalls
     */
    public static function withToolCalls(array $toolCalls, string $model = 'stub-model@1'): self
    {
        return new self(new LlmResponse([], 80, 0, $model, 3, $toolCalls), false, 80);
    }

    /**
     * A client that is unavailable — every call throws (degradation tests, I6).
     */
    public static function down(): self
    {
        return new self(null, true, 0);
    }

    public function generate(LlmRequest $request): LlmResponse
    {
        $this->lastRequest = $request;
        $this->generateCalls++;
        if ($this->down || $this->response === null) {
            throw new LlmUnavailableException('stub LLM is in down mode');
        }
        return $this->response;
    }

    public function countTokens(LlmRequest $request): int
    {
        $this->lastRequest = $request;
        $this->countTokensCalls++;
        if ($this->down) {
            throw new LlmUnavailableException('stub LLM is in down mode');
        }
        return $this->tokenCount;
    }

    public function lastRequest(): ?LlmRequest
    {
        return $this->lastRequest;
    }

    public function generateCalls(): int
    {
        return $this->generateCalls;
    }

    public function countTokensCalls(): int
    {
        return $this->countTokensCalls;
    }
}
