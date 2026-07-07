<?php

/**
 * LlmResponse — the immutable structured result of a single LlmClient generate() call.
 *
 * `json` is the parsed structured payload (the §2.1 claim list for a reduce, or an empty
 * shape when the model instead emits tool-call requests). `toolCalls` carries native
 * function-call requests the model proposed (U11's agent loop disposes them; the model
 * executes nothing — I13). Token counts, the pinned model version string, and measured
 * latency feed the llm_reduce span and the cost model. Nothing here is gated — the
 * verifier (U10) decides whether this output may be rendered.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

final readonly class LlmResponse
{
    /**
     * @param array<string, mixed> $json parsed structured JSON payload (provider-enforced schema)
     * @param list<array{name: string, args: array<string, mixed>}> $toolCalls proposed function calls (U11)
     */
    public function __construct(
        public array $json,
        public int $tokensIn,
        public int $tokensOut,
        public string $model,
        public int $latencyMs,
        public array $toolCalls = [],
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
