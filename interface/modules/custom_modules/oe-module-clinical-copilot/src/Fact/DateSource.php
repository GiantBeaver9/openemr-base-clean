<?php

/**
 * DateSource enum — provenance of a fact's clinical_date under the C1 precedence.
 *
 * `collected` = an authoritative collection date (report/order date_collected);
 * `fallback`  = a result.date or report.date_report fallback, flagged in the citation.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

enum DateSource: string
{
    case Collected = 'collected';
    case Fallback = 'fallback';
}
