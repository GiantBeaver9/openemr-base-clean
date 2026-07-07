<?php

/**
 * FactValue — the value block on result/trend_point/vital facts (lab contract C3/C4).
 *
 * Encodes the hard-won rules from the data-quality audit:
 *  - `raw` is verbatim, length-bounded chart text (never trusted as a number).
 *  - `parsed` is null unless a numeric was safely extracted; null ⇒ no numeric claim (C3).
 *  - `comparator` marks censored values ("<7.0") so downstream code never treats them as exact.
 *  - unit fields enforce "no unit, no math" (C4): parsed math is banned when unit is unknown.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

final readonly class FactValue
{
    public function __construct(
        public string $raw,
        public ?float $parsed,
        public Comparator $comparator,
        public string $unitOriginal,
        public ?string $unitCanonical,
        public ?string $conversionVersion,
    ) {
    }

    /**
     * True only when a numeric comparison/trend is legitimately permitted:
     * a parsed number AND a known canonical unit (C3 + C4). Censored values are
     * still "numeric" for direction-only claims — the comparator carries that.
     */
    public function isQuantitative(): bool
    {
        return $this->parsed !== null && $this->unitCanonical !== null;
    }

    /**
     * @return array{raw: string, parsed: float|null, comparator: string, unit_original: string, unit_canonical: string|null, conversion_version: string|null}
     */
    public function toCanonical(): array
    {
        return [
            'raw' => $this->raw,
            'parsed' => $this->parsed,
            'comparator' => $this->comparator->value,
            'unit_original' => $this->unitOriginal,
            'unit_canonical' => $this->unitCanonical,
            'conversion_version' => $this->conversionVersion,
        ];
    }
}
