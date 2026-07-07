<?php

/**
 * DateResolution — the outcome of the C1 clinical-date precedence walk.
 *
 * Carries the resolved ISO date (Y-m-d, or null if every candidate was empty) and the
 * DateSource marking whether it came from an authoritative collection date or a
 * fallback (result.date / report.date_report). A fallback-dated fact flags its citation.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\DateSource;

final readonly class DateResolution
{
    public function __construct(
        public ?string $clinicalDate,
        public DateSource $source,
    ) {
    }
}
