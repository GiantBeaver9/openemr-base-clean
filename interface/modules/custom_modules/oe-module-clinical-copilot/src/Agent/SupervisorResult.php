<?php

/**
 * What the supervisor assembled: the routing decision, extracted facts, and evidence.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

use OpenEMR\Modules\ClinicalCopilot\Ingest\ParsedExtraction;
use OpenEMR\Modules\ClinicalCopilot\Rag\EvidenceSnippet;

/**
 * `routed` is the inspectable routing decision — which workers ran, in order —
 * mirroring the `worker` trace spans. `extraction` (document/patient facts) and
 * `evidence` (guideline snippets) are deliberately separate fields: the two
 * evidence classes never merge into one bag, so a downstream renderer keeps
 * patient-record facts and guideline evidence in distinct sections by
 * construction (the doc's separation rule).
 */
final readonly class SupervisorResult
{
    /**
     * @param list<WorkerName> $routed
     * @param list<EvidenceSnippet> $evidence
     */
    public function __construct(
        public array $routed,
        public ?ParsedExtraction $extraction,
        public array $evidence,
    ) {
    }

    public function routedTo(WorkerName $worker): bool
    {
        return in_array($worker, $this->routed, true);
    }
}
