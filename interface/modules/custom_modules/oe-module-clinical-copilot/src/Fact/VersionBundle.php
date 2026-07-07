<?php

/**
 * VersionBundle — every non-fact input that participates in the digest.
 *
 * The digest addresses a cache slot; changing any of these versions must invalidate
 * exactly the affected docs (config-drift eval E5). Bundling them in one typed object
 * makes "what feeds the digest" auditable and prevents a forgotten input.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

final readonly class VersionBundle
{
    /**
     * @param array<string, string> $capabilityVersions capability value => version string
     */
    public function __construct(
        public array $capabilityVersions,
        public string $cadenceVersion,      // mod_copilot_cadence config version (intervals, units, turnaround, limits)
        public string $codeSetVersion,      // LOINC/analyte code-set version
        public string $docType,             // e.g. endo-previsit-v1
        public string $promptVersion,       // reduce prompt + response schema version (folds in the pinned model id)
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        $caps = $this->capabilityVersions;
        ksort($caps);
        return [
            'capability_versions' => $caps,
            'cadence_version' => $this->cadenceVersion,
            'code_set_version' => $this->codeSetVersion,
            'doc_type' => $this->docType,
            'prompt_version' => $this->promptVersion,
        ];
    }
}
