<?php

/**
 * Wires the LlmClientInterface implementation for the read path's composition root.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\ReadPath;

use OpenEMR\Modules\ClinicalCopilot\Reduce\LlmClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Reduce\VertexLlmClient;

/**
 * T18 (build-notes.md "LLM platform"): project/location are deployment
 * config, never hardcoded and never an API key -- read from the two
 * environment variables below (set in the site's Apache/PHP-FPM
 * environment, never committed). Empty/unset `CLINICAL_COPILOT_GCP_PROJECT_ID`
 * is the honest dev/test default (no GCP project here) and MUST degrade
 * through {@see UnavailableLlmClient}, never through
 * {@see VertexLlmClient}'s constructor guard (a plain
 * {@see \DomainException} on an empty project id -- the wrong shape of
 * failure for "unconfigured," see that class's docblock).
 */
final class LlmClientFactory
{
    private const ENV_PROJECT_ID = 'CLINICAL_COPILOT_GCP_PROJECT_ID';
    private const ENV_LOCATION = 'CLINICAL_COPILOT_GCP_LOCATION';
    private const DEFAULT_LOCATION = 'us-central1';

    private function __construct()
    {
        // static-only
    }

    public static function create(): LlmClientInterface
    {
        $projectId = trim((string)getenv(self::ENV_PROJECT_ID));
        if ($projectId === '') {
            return new UnavailableLlmClient();
        }

        $location = trim((string)getenv(self::ENV_LOCATION));
        if ($location === '') {
            $location = self::DEFAULT_LOCATION;
        }

        return new VertexLlmClient($projectId, $location);
    }
}
