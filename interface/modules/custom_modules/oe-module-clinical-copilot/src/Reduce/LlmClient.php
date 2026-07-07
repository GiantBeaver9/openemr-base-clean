<?php

/**
 * LlmClient — the one seam between the module and the language model (ARCHITECTURE.md
 * LLM platform, T18).
 *
 * Two operations: `generate` runs a constrained-decoding pass (Vertex responseSchema),
 * `countTokens` sizes a request against the model's tokenizer without generating (used by
 * U12's ready.php probe — the method name is load-bearing, do not rename). Implementations:
 * VertexClient (Gemini on Vertex AI REST) for runtime; StubLlmClient for isolated tests.
 * Failures surface as thrown \Throwable — the Reducer's degradation rule (I6) catches them.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

interface LlmClient
{
    /**
     * Run one generation pass. Throws on provider outage/timeout/quota so the caller can
     * degrade; never returns a partial or unparsed result.
     *
     * @throws \Throwable on any provider or transport failure
     */
    public function generate(LlmRequest $request): LlmResponse;

    /**
     * Count the tokens a request would consume, without generating. Used by the readiness
     * probe to confirm the provider path is reachable and by the cost model.
     *
     * @throws \Throwable on any provider or transport failure
     */
    public function countTokens(LlmRequest $request): int;
}
