<?php

/**
 * Flag — canonical flag tokens carried on a fact.
 *
 * Some flags are parameterized (superseded_N, excluded_reason:<enum>), so flags are
 * represented on the Fact as an ordered list of canonical string tokens rather than a
 * bare enum. This helper mints those tokens so every producer emits the same spelling
 * (the tokens participate in the digest — see CanonicalSerializer).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

final class Flag
{
    public const CONFLICT = 'conflict';
    public const CENSORED = 'censored';
    public const OUT_OF_RANGE_BY_VALUE = 'out_of_range_by_value';
    public const OUT_OF_RANGE_BY_LAB_FLAG = 'out_of_range_by_lab_flag';

    private function __construct()
    {
    }

    /**
     * "superseded_3" — this fact superseded 3 prior results (C2).
     */
    public static function superseded(int $count): string
    {
        return 'superseded_' . max(0, $count);
    }

    /**
     * "excluded_reason:no_unit" — the reason a row was excluded (I5).
     */
    public static function excludedReason(ExclusionReason $reason): string
    {
        return 'excluded_reason:' . $reason->value;
    }
}
