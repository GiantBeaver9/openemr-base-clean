<?php

/**
 * ExclusionReason enum — closed reasons a row is excluded, always surfaced (I5).
 *
 * "No silent exclusion": every filtered row appears as a visible exclusion fact
 * carrying one of these reasons, with citations to the row it excluded.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

enum ExclusionReason: string
{
    case NoUnit = 'no_unit';                       // C4: empty/unrecognized unit → no math
    case Unparseable = 'unparseable_value';        // C3: value could not be parsed
    case UnperformedStatus = 'unperformed_status'; // C2: cannot be done/incomplete/error/canceled
    case UnrecognizedStatus = 'unrecognized_status'; // C2: status not in the known vocabulary
    case NonNumericType = 'non_numeric_type';      // C3: result_data_type F/E/L, no numeric contract
    case SoftDeleted = 'soft_deleted';             // D7: activity/active flag not live
    case Superseded = 'superseded';                // C2: a later corrected/final result won
}
