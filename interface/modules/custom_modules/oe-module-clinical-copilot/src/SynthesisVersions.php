<?php

/**
 * SynthesisVersions — the single place the digest's VersionBundle is assembled.
 *
 * The read path (U8) and the chat session (U11) must produce byte-identical digests
 * for the same facts, or a chat session could never seed from its doc. This helper
 * guarantees they build the VersionBundle the same way. doc_type / prompt_version /
 * code_set_version are pinned here; capability + cadence versions come from the wired
 * CapabilityFactory. Bumping any of these is a deliberate, digest-invalidating event
 * (E5 discipline).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Fact\VersionBundle;

final class SynthesisVersions
{
    /** Default synthesis document type (the endo pre-visit summary). */
    public const DOC_TYPE = 'endo-previsit-v1';

    /** Reduce prompt + response-schema version; folds in the pinned model id (T18/E5). */
    public const PROMPT_VERSION = 'endo-reduce@1';

    /** LOINC/analyte code-set version. */
    public const CODE_SET_VERSION = 'loinc-endo@1';

    public static function bundle(CapabilityFactory $factory, string $docType = self::DOC_TYPE): VersionBundle
    {
        return new VersionBundle(
            $factory->capabilityVersions(),
            $factory->cadenceVersion(),
            self::CODE_SET_VERSION,
            $docType,
            self::PROMPT_VERSION,
        );
    }
}
