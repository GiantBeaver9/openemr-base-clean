<?php

/**
 * UnitConverter — the C4 unit whitelist (ARCHITECTURE_COMPLETE.md "Units").
 *
 * Per-analyte canonical units + a versioned conversion whitelist, read from the cadence
 * config. The hard rules:
 *   - "No unit, no math": empty OR unrecognized unit → unitCanonical=null → excluded from
 *     thresholds/trends (counted, visible). A unit is NEVER guessed.
 *   - A value already in the canonical unit passes through unchanged (no conversion
 *     version — nothing was converted).
 *   - A value in a whitelisted non-canonical unit is converted via the config's factor
 *     (v × factor) or linear formula (a·x + b), and carries the conversion version
 *     (e.g. IFCC mmol/mol → NGSP %: 0.09148·v + 2.152, conv:a1c@1).
 *   - For an analyte with NO unit config, a present unit is passed through verbatim as
 *     its own canonical (we never invent a unit); only a genuinely empty unit is unitless.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

final class UnitConverter
{
    /** Converted values are rounded to this many decimals (A1c landmine expects 8.46). */
    private const CONVERSION_PRECISION = 2;

    public function __construct(private readonly LabCadenceConfig $config)
    {
    }

    /**
     * Resolve the canonical value/unit for one parsed lab value.
     */
    public function convert(string $loinc, ?float $parsed, string $unitOriginal): UnitConversion
    {
        $unit = trim($unitOriginal);
        $analyte = $this->config->analyteForLoinc($loinc);

        $unitConfig = $analyte !== null ? $this->config->unitConfig($analyte) : null;
        if ($unitConfig === null) {
            // No whitelist for this analyte: pass a present unit through as canonical;
            // an empty unit is unitless. Never guess a unit.
            if ($unit === '') {
                return new UnitConversion(null, null, null);
            }
            return new UnitConversion($parsed, $unit, null);
        }

        // Empty unit under a configured analyte → no unit, no math.
        if ($unit === '') {
            return new UnitConversion(null, null, null);
        }

        $canonical = $unitConfig['canonical'] ?? null;
        if (!is_string($canonical) || $canonical === '') {
            return new UnitConversion(null, null, null);
        }

        // Already canonical: identity pass-through, no conversion version.
        if ($unit === $canonical) {
            return new UnitConversion($parsed, $canonical, null);
        }

        $conversions = $unitConfig['conversions'] ?? [];
        if (is_array($conversions) && isset($conversions[$unit]) && is_array($conversions[$unit])) {
            $converted = $this->applyConversion($conversions[$unit], $parsed);
            if ($converted === null) {
                // Conversion spec present but unusable — refuse to fabricate a number.
                return new UnitConversion(null, null, null);
            }
            $version = $unitConfig['conversion_version'] ?? null;
            return new UnitConversion($converted, $canonical, is_string($version) ? $version : null);
        }

        // Non-empty but not the canonical unit and not on the whitelist → unrecognized.
        return new UnitConversion(null, null, null);
    }

    /**
     * Apply a single conversion spec (factor or linear formula) to a parsed value.
     *
     * @param array<string, mixed> $spec
     */
    private function applyConversion(array $spec, ?float $parsed): ?float
    {
        if ($parsed === null) {
            return null;
        }

        if (isset($spec['factor']) && is_numeric($spec['factor'])) {
            return round($parsed * (float) $spec['factor'], self::CONVERSION_PRECISION);
        }

        if (isset($spec['formula']) && is_string($spec['formula'])) {
            $linear = $this->parseLinearFormula($spec['formula']);
            if ($linear === null) {
                return null;
            }
            [$slope, $intercept] = $linear;
            return round(($slope * $parsed) + $intercept, self::CONVERSION_PRECISION);
        }

        return null;
    }

    /**
     * Parse a linear formula of the form "<name> = a * x + b" (b sign optional) into
     * [slope, intercept]. Anything that is not a plain linear form returns null rather
     * than risk evaluating an unexpected expression.
     *
     * @return array{float, float}|null
     */
    private function parseLinearFormula(string $formula): ?array
    {
        if (preg_match('/([-+]?\d*\.?\d+)\s*\*\s*x\s*([-+])\s*(\d*\.?\d+)/i', $formula, $m) === 1) {
            $slope = (float) $m[1];
            $intercept = (float) $m[3];
            if ($m[2] === '-') {
                $intercept = -$intercept;
            }
            return [$slope, $intercept];
        }
        return null;
    }
}
