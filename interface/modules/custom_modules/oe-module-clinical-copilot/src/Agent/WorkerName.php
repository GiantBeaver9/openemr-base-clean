<?php

/**
 * The closed set of workers the supervisor can route to.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Agent;

/**
 * Exactly the two workers the Week 2 spec names — intake-extractor and
 * evidence-retriever. The backing value is what lands in the `worker` trace
 * span so a routing decision is inspectable from the trace alone.
 */
enum WorkerName: string
{
    case IntakeExtractor = 'intake_extractor';
    case EvidenceRetriever = 'evidence_retriever';
}
