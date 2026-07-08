<?php

/**
 * Bounds a fact set to a recent, prompt-sized window before it is sent to the LLM.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

use OpenEMR\Modules\ClinicalCopilot\Fact\Enum\FactKind;

/**
 * A long chart is dominated by trend points: a 20-year patient carries ~440
 * facts (~240 KB, ~60K tokens) and the whole block is re-serialized into EVERY
 * LLM round -- synthesis and each chat round alike -- which makes the model
 * call slow enough to hit the Vertex request timeout (the narrative never
 * finishes) and stacks up across a multi-round chat turn (2nd/3rd message dies
 * with a client network error). The panel already decided the last ~20 draws
 * per lab are what a physician reads; the LLM needs no more than that either.
 *
 * This trims ONLY what the model is shown, not the fact set the digest and the
 * verifier operate on (those stay full, so a claim citing a windowed fact
 * still resolves -- the window is a subset). The dense, per-visit kinds
 * (trend points, vitals, and the between-visit deltas) are capped to the most
 * recent {@see self::DEFAULT_PER_SERIES} per (capability, unit) series -- so
 * the A1c (%) trend keeps its recent history independent of the mg/dL labs --
 * while every sparse, decision-bearing fact (results, medications, overdue and
 * pending items, preliminary results, exclusions, conflicts, and the
 * derived_count / derived_span summaries that describe the WHOLE series in one
 * fact) is always kept.
 *
 * Pure and deterministic: same facts in, same subset out.
 */
final class PromptFactWindow
{
    /** Most recent facts kept per dense (capability, unit) series. */
    public const DEFAULT_PER_SERIES = 15;

    private function __construct()
    {
        // static-only
    }

    /**
     * @param list<Fact> $facts
     * @return list<Fact>
     */
    public static function forPrompt(array $facts, int $perSeries = self::DEFAULT_PER_SERIES): array
    {
        $kept = [];
        /** @var array<string, list<Fact>> $denseSeries */
        $denseSeries = [];

        foreach ($facts as $fact) {
            if (!self::isDense($fact->kind)) {
                $kept[] = $fact;
                continue;
            }
            $unit = $fact->value?->unitCanonical ?? $fact->value?->unitOriginal ?? '';
            $denseSeries[$fact->capability->value . '|' . $unit][] = $fact;
        }

        foreach ($denseSeries as $series) {
            usort($series, static function (Fact $a, Fact $b): int {
                // Most recent first; undated facts trail (ISO Y-m-d sorts as a string).
                $ad = $a->clinicalDate?->format('Y-m-d') ?? '';
                $bd = $b->clinicalDate?->format('Y-m-d') ?? '';

                return $bd <=> $ad;
            });

            foreach (array_slice($series, 0, max(0, $perSeries)) as $fact) {
                $kept[] = $fact;
            }
        }

        return array_values($kept);
    }

    /**
     * The per-visit kinds that explode over a long history. `derived_count`
     * and `derived_span` are deliberately NOT dense: there is one of each per
     * series and each summarizes the full series, so they are always kept.
     */
    private static function isDense(FactKind $kind): bool
    {
        return match ($kind) {
            FactKind::TrendPoint, FactKind::Vital, FactKind::DerivedDelta => true,
            default => false,
        };
    }
}
