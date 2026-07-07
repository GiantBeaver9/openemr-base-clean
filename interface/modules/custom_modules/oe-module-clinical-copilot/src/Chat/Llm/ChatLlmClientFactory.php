<?php

/**
 * Wires the ChatLlmClientInterface implementation for the chat composition root.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat\Llm;

/**
 * Mirrors {@see \OpenEMR\Modules\ClinicalCopilot\ReadPath\LlmClientFactory}
 * exactly, reusing the SAME two environment variables (one GCP project, one
 * chat surface -- there is no reason chat and synthesis would ever target
 * different Vertex deployments) rather than minting a parallel config
 * surface. Empty/unset `CLINICAL_COPILOT_GCP_PROJECT_ID` is the honest
 * dev/test default and MUST degrade through {@see UnavailableChatLlmClient}.
 */
final class ChatLlmClientFactory
{
    private const ENV_PROJECT_ID = 'CLINICAL_COPILOT_GCP_PROJECT_ID';
    private const ENV_LOCATION = 'CLINICAL_COPILOT_GCP_LOCATION';
    private const DEFAULT_LOCATION = 'us-central1';

    private function __construct()
    {
        // static-only
    }

    public static function create(): ChatLlmClientInterface
    {
        $projectId = trim((string)getenv(self::ENV_PROJECT_ID));
        if ($projectId === '') {
            return new UnavailableChatLlmClient();
        }

        $location = trim((string)getenv(self::ENV_LOCATION));
        if ($location === '') {
            $location = self::DEFAULT_LOCATION;
        }

        return new VertexChatLlmClient($projectId, $location);
    }
}
