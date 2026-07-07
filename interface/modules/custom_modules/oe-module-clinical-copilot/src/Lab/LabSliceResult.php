<?php

/**
 * The output of processing a raw lab slice: presented facts + exclusion facts.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

/**
 * I5: every filtered row is accounted for in `exclusions` -- nothing is
 * silently dropped between the raw join and this result.
 */
final readonly class LabSliceResult
{
    /**
     * @param list<PresentedLabFact> $presented
     * @param list<Fact> $exclusions
     */
    public function __construct(
        public array $presented,
        public array $exclusions,
    ) {
    }
}
