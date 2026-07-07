<?php

/**
 * LlmRequest — the immutable request a reduce/chat pass posts to an LlmClient.
 *
 * Carries the system prompt (role + hard refusals), the user content (patient context +
 * canonical fact bytes), and a provider-enforced JSON `responseSchema` (Vertex
 * `responseMimeType: application/json` + `responseSchema`, ARCHITECTURE.md LLM platform).
 * `tools` holds native function declarations for the U11 chat agent; empty for a plain
 * reduce. Withers return new instances so the EgressRedactor can rewrite the outbound
 * strings without ever mutating the assembled prompt in place.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

final readonly class LlmRequest
{
    /**
     * @param array<string, mixed> $responseSchema provider-enforced constrained-decoding schema
     * @param list<array<string, mixed>> $tools native function declarations (U11 chat), [] for reduce
     */
    public function __construct(
        public string $systemPrompt,
        public string $userContent,
        public array $responseSchema,
        public string $model,
        public ?int $maxOutputTokens = null,
        public array $tools = [],
    ) {
    }

    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self(
            $systemPrompt,
            $this->userContent,
            $this->responseSchema,
            $this->model,
            $this->maxOutputTokens,
            $this->tools,
        );
    }

    public function withUserContent(string $userContent): self
    {
        return new self(
            $this->systemPrompt,
            $userContent,
            $this->responseSchema,
            $this->model,
            $this->maxOutputTokens,
            $this->tools,
        );
    }
}
