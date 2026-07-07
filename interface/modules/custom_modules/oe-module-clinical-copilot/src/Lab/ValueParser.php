<?php

/**
 * ValueParser — the C3 value grammar (ARCHITECTURE_COMPLETE.md "Value parsing").
 *
 * `result` is a varchar(255) of chart text, never trusted as a number. This extracts a
 * numeric ONLY when it is safe to:
 *   - result_data_type must be N or S; F/E/L are never numeric (returns parsed=null).
 *   - grammar = optional comparator (< <= > >=) + decimal + optional trailing unit token,
 *     whitespace tolerant. The trailing unit token is tolerated but ignored (the `units`
 *     column is authoritative — see UnitConverter).
 *   - a comparator marks the value CENSORED ("<7.0"): the number is kept for the
 *     direction it proves, never coerced into an exact claim.
 *   - anything that does not match the grammar → parsed=null (no numeric claim). "" is
 *     never coerced to 0, "<7.0" is never coerced to a bare 7.0-as-exact.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;

final class ValueParser
{
    /** result_data_type values that carry a numeric contract (C3). */
    private const NUMERIC_TYPES = ['N', 'S'];

    /**
     * comparator (opt) + decimal + trailing unit token (opt), whitespace tolerant.
     */
    private const GRAMMAR = '/^\s*(<=|>=|=<|=>|<|>)?\s*([+-]?\d+(?:\.\d+)?)\s*([A-Za-z%\/µ]+)?\s*$/u';

    /**
     * @param string $dataType the raw result_data_type (case-sensitive host code)
     */
    public function parse(string $rawValue, string $dataType): ParsedValue
    {
        // Numeric parse is only defined for N/S; F/E/L carry no numeric contract.
        if (!in_array($dataType, self::NUMERIC_TYPES, true)) {
            return new ParsedValue(null, Comparator::None);
        }

        if (preg_match(self::GRAMMAR, $rawValue, $matches) !== 1) {
            // Does not match the grammar → no numeric claim (C3).
            return new ParsedValue(null, Comparator::None);
        }

        $comparator = Comparator::fromToken($matches[1] ?? '');
        $parsed = (float) $matches[2];

        return new ParsedValue($parsed, $comparator);
    }
}
