<?php

/**
 * ChatLlmClientInterface implementation for "no credentials configured" (I6 default).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmUnavailableException;

/**
 * Mirrors {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\UnavailableLlmClient}
 * exactly, one layer up: {@see ChatLlmClientFactory} hands this back whenever
 * no Vertex project is configured, so chat degrades to the facts browser
 * (I6/I11) the same way the synthesis path degrades to facts-only -- from
 * the agent loop's perspective these are indistinguishable failures, both
 * surfaced via {@see LlmUnavailableException}.
 */
final class UnavailableChatLlmClient implements ChatLlmClientInterface
{
    public function converse(ChatLlmRequest $req): ChatLlmResponse
    {
        throw LlmUnavailableException::noCredentials(new \RuntimeException(
            'Clinical Co-Pilot: no Vertex AI project configured in this environment (CLINICAL_COPILOT_GCP_PROJECT_ID unset)'
        ));
    }
}
