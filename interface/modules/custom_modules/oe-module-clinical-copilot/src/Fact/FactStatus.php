<?php

/**
 * FactStatus enum — lab/result status semantics (lab contract C2).
 *
 * `result_status` in the host schema is free-text varchar default '' — this enum
 * is the module's canonical, closed interpretation of it (see StatusResolver).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

enum FactStatus: string
{
    case Final = 'final';
    case Corrected = 'corrected';
    case Unstated = 'unstated';
    case Preliminary = 'preliminary';
    case Excluded = 'excluded';

    /**
     * Supersession precedence within (patient, result_code, clinical_date):
     * corrected > final > unstated('') > preliminary. Higher wins. (C2)
     */
    public function supersessionRank(): int
    {
        return match ($this) {
            self::Corrected => 4,
            self::Final => 3,
            self::Unstated => 2,
            self::Preliminary => 1,
            self::Excluded => 0,
        };
    }

    /**
     * Whether a result with this status resets the OverdueTests clock (C2).
     * Only performed, reportable results (final/corrected/unstated-manual) count.
     */
    public function resetsOverdueClock(): bool
    {
        return match ($this) {
            self::Final, self::Corrected, self::Unstated => true,
            self::Preliminary, self::Excluded => false,
        };
    }
}
