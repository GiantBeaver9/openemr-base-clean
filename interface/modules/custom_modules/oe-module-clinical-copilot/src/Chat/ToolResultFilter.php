<?php

/**
 * ToolResultFilter — narrows a capability's full fact list to the drill-down the model asked for.
 *
 * The five capabilities each produce their whole fact set for the pinned patient (they take only
 * a pid); a tool call is a *parameterized* view of that set — one analyte, one metric, a window,
 * an optional drug filter. Filtering is deliberately CONSERVATIVE and lossless-by-default: a fact
 * is dropped only when it positively identifies itself as out-of-scope (a discriminator flag that
 * does not match, or a dated fact older than the window). Undated/derived facts and facts without
 * a discriminator are kept — the module never silently hides data it cannot prove is irrelevant
 * (I5: an unexplained omission is worse than an over-inclusive answer).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

final class ToolResultFilter
{
    /**
     * @param array<string, mixed> $args sanitized args from ToolRegistry::validate
     * @param list<Fact>           $facts
     *
     * @return list<Fact>
     */
    public static function apply(ToolName $tool, array $args, array $facts, \DateTimeImmutable $now): array
    {
        $out = $facts;

        if (isset($args['window_months']) && is_int($args['window_months'])) {
            $cutoff = $now->modify('-' . $args['window_months'] . ' months')->format('Y-m-d');
            $out = self::withinWindow($out, $cutoff);
        }

        $out = match ($tool) {
            ToolName::GetControlTrend => self::byDiscriminator($out, 'analyte:', 'analyte:' . (string) ($args['analyte'] ?? '')),
            ToolName::GetVitalsTrend => self::byDiscriminator($out, 'measure:', 'measure:' . (string) ($args['metric'] ?? '')),
            ToolName::GetMedHistory => isset($args['drug_filter']) && is_string($args['drug_filter'])
                ? self::byDiscriminator($out, 'drug:', 'drug:' . strtolower($args['drug_filter']))
                : $out,
            ToolName::GetOverdue, ToolName::GetPending => $out,
        };

        return array_values($out);
    }

    /**
     * Keep dated facts on/after the cutoff; keep every undated fact (derived deltas, counts,
     * expected-return dates carry no clinical_date and are always in scope for their series).
     *
     * @param list<Fact> $facts
     *
     * @return list<Fact>
     */
    private static function withinWindow(array $facts, string $cutoff): array
    {
        return array_values(array_filter($facts, static function (Fact $f) use ($cutoff): bool {
            if ($f->clinicalDate === null) {
                return true;
            }
            return substr($f->clinicalDate, 0, 10) >= $cutoff;
        }));
    }

    /**
     * Drop a fact only when it carries a flag in $family that does NOT equal $wanted. A fact with
     * no $family flag at all is kept (we cannot prove it is the wrong analyte/metric/drug).
     *
     * @param list<Fact> $facts
     *
     * @return list<Fact>
     */
    private static function byDiscriminator(array $facts, string $family, string $wanted): array
    {
        return array_values(array_filter($facts, static function (Fact $f) use ($family, $wanted): bool {
            $familyFlags = array_filter($f->flags, static fn(string $flag): bool => str_starts_with($flag, $family));
            if ($familyFlags === []) {
                return true;
            }
            return in_array($wanted, $familyFlags, true);
        }));
    }
}
