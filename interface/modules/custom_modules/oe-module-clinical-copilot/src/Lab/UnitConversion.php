<?php

/**
 * UnitConversion — the outcome of applying the C4 unit whitelist to one value.
 *
 * `unitCanonical === null` is the "no unit, no math" signal: the value has an empty or
 * unrecognized unit, so it is excluded from thresholds/trends (but still shown, I5).
 * When a real conversion (not an identity pass-through) was applied, `conversionVersion`
 * carries the whitelist version — a digest input.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

final readonly class UnitConversion
{
    public function __construct(
        public ?float $canonicalValue,
        public ?string $unitCanonical,
        public ?string $conversionVersion,
    ) {
    }

    /**
     * True when the unit was empty or unrecognized — parsed math is banned (C4).
     */
    public function isUnitless(): bool
    {
        return $this->unitCanonical === null;
    }
}
