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
 * still resolves -- the window is a subset). Two entry points, because the two
 * surfaces have different budgets:
 *
 * - {@see self::forChat()} -- LEANER. The whole block is re-sent on every round
 *   of a multi-round chat turn, so dense trend history is bounded to the last
 *   {@see self::MAX_AGE_MONTHS} months, with a {@see self::DEFAULT_PER_SERIES}
 *   per-(capability, unit)-series cap within it.
 * - {@see self::forNarrative()} -- RICHER. The synthesis is a one-shot call, so
 *   it gets the last {@see self::MAX_NARRATIVE_VISITS} visits' worth of trend
 *   history instead of the tighter 2-year window.
 *
 * Both keep every sparse, decision-bearing fact (results, medications, overdue
 * and pending items, preliminary results, exclusions, conflicts, and the
 * derived_count / derived_span summaries that describe the WHOLE series in one
 * fact) regardless of age -- a five-year-old medication may still be active.
 *
 * Pure and deterministic: same facts in, same subset out.
 */
final class PromptFactWindow
{
    /** Chat window: dense trend history older than this never travels to a chat round. */
    public const MAX_AGE_MONTHS = 24;

    /** Chat secondary cap: most recent facts kept per dense (capability, unit) series within the window. */
    public const DEFAULT_PER_SERIES = 15;

    /** Narrative window: the synthesis is a one-shot call, so it gets a richer slice -- the last N visits. */
    public const MAX_NARRATIVE_VISITS = 20;

    private function __construct()
    {
        // static-only
    }

    /**
     * The CHAT window: leaner, because the whole fact block is re-sent on every
     * round of a multi-round turn. Dense trend history is bounded to the last
     * {@see self::MAX_AGE_MONTHS} months, with a per-series count cap within it.
     *
     * @param list<Fact> $facts
     * @return list<Fact>
     */
    public static function forChat(array $facts, int $perSeries = self::DEFAULT_PER_SERIES, int $maxAgeMonths = self::MAX_AGE_MONTHS): array
    {
        // The recency floor is anchored to the patient's most recent datum
        // (~the visit), not the wall clock, so this stays a pure function --
        // it is "the last N months of available data".
        $cutoff = self::cutoffDate($facts, $maxAgeMonths);

        $kept = [];
        /** @var array<string, list<Fact>> $denseSeries */
        $denseSeries = [];

        foreach ($facts as $fact) {
            if (!self::isDense($fact->kind)) {
                // Sparse, decision-bearing facts are kept regardless of age: a
                // medication started five years ago may still be active, and an
                // overdue/pending item is about now, not its origin date.
                $kept[] = $fact;
                continue;
            }
            $date = $fact->clinicalDate?->format('Y-m-d');
            if ($cutoff !== null && $date !== null && $date < $cutoff) {
                // Dense trend history beyond the window -- do not send it.
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
     * The NARRATIVE window: the synthesis is generated once (not re-sent per
     * round), so it gets the last {@see self::MAX_NARRATIVE_VISITS} visits'
     * worth of dense trend history -- a "visit" being a distinct clinical date
     * on which a trend/reading/vital was recorded -- rather than the tighter
     * 2-year chat window. Sparse decision-bearing facts are kept regardless,
     * exactly as {@see self::forChat()} keeps them.
     *
     * @param list<Fact> $facts
     * @return list<Fact>
     */
    public static function forNarrative(array $facts, int $maxVisits = self::MAX_NARRATIVE_VISITS): array
    {
        $cutoff = self::nthMostRecentVisitDate($facts, $maxVisits);

        $kept = [];
        foreach ($facts as $fact) {
            if (!self::isDense($fact->kind)) {
                $kept[] = $fact;
                continue;
            }
            $date = $fact->clinicalDate?->format('Y-m-d');
            if ($cutoff !== null && $date !== null && $date < $cutoff) {
                continue;
            }
            $kept[] = $fact;
        }

        return array_values($kept);
    }

    /**
     * The `Y-m-d` floor for the last `$maxVisits` visit dates (distinct dense
     * clinical dates), or null when there are no more than that many -- then
     * nothing is dropped.
     *
     * @param list<Fact> $facts
     */
    private static function nthMostRecentVisitDate(array $facts, int $maxVisits): ?string
    {
        $visitDates = [];
        foreach ($facts as $fact) {
            if (!self::isDense($fact->kind)) {
                continue;
            }
            $date = $fact->clinicalDate?->format('Y-m-d');
            if ($date !== null) {
                $visitDates[$date] = true;
            }
        }

        if (count($visitDates) <= $maxVisits) {
            return null;
        }

        $dates = array_keys($visitDates);
        rsort($dates); // most recent first

        return $dates[$maxVisits - 1];
    }

    /**
     * The `Y-m-d` floor: `$maxAgeMonths` before the most recent dated fact, or
     * null when no fact carries a date (then nothing is age-filtered).
     *
     * @param list<Fact> $facts
     */
    private static function cutoffDate(array $facts, int $maxAgeMonths): ?string
    {
        $newest = null;
        foreach ($facts as $fact) {
            $date = $fact->clinicalDate?->format('Y-m-d');
            if ($date !== null && ($newest === null || $date > $newest)) {
                $newest = $date;
            }
        }

        if ($newest === null) {
            return null;
        }

        return (new \DateTimeImmutable($newest))->modify("-{$maxAgeMonths} months")->format('Y-m-d');
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
