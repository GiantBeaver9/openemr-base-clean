<?php

/**
 * SynthesisResult — the immutable, template-ready outcome of one synthesis read.
 *
 * The doc page renders THIS, not raw services. It is deliberately facts-first: the FactSet is
 * always present and always the freshly-extracted one (facts are never cached, I2), while the
 * narrative is optional (present only on CacheHit/Generated, absent on every degraded path).
 * The presentation helpers split the facts into the three sections the page needs — the always-on
 * fact table, the in-flight (pending/preliminary) section, and the exclusion notes — and expose
 * the verification badge summaries, so the Twig template stays a thin escaping layer over scalar
 * arrays.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Read;

use OpenEMR\Modules\ClinicalCopilot\Doc\CopilotDoc;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationVerdict;

final readonly class SynthesisResult
{
    private const EXCLUDED_REASON_PREFIX = 'excluded_reason:';

    /**
     * @param list<CheckSummary> $checksRun the V1..V6 checks that ran (empty on paused/degraded)
     */
    public function __construct(
        public int $pid,
        public ReadOutcome $outcome,
        public string $correlationId,
        public FactSet $facts,
        public ?string $narrative,
        public ?string $narrativeUnavailableReason,
        public ?string $banner,
        public array $checksRun,
        public bool $retried,
        public bool $degraded,
        public bool $sev1Signal,
        public ?string $computedAt,
    ) {
    }

    /**
     * A cache hit: fresh facts, the stored (already re-hydrated) narrative, the stored verdict.
     *
     * @param list<CheckSummary> $checksRun
     */
    public static function cacheHit(
        FactSet $facts,
        string $correlationId,
        string $narrative,
        array $checksRun,
        string $computedAt,
    ): self {
        return new self(
            $facts->pid,
            ReadOutcome::CacheHit,
            $correlationId,
            $facts,
            $narrative,
            null,
            null,
            $checksRun,
            false,
            false,
            false,
            $computedAt,
        );
    }

    /**
     * A freshly generated, verified narrative.
     */
    public static function generated(
        FactSet $facts,
        string $correlationId,
        string $narrative,
        VerificationVerdict $verdict,
        CopilotDoc $doc,
        bool $retried,
    ): self {
        return new self(
            $facts->pid,
            ReadOutcome::Generated,
            $correlationId,
            $facts,
            $narrative,
            null,
            $retried ? 'This summary was regenerated once before it passed verification.' : null,
            CheckSummary::listFromVerdict($verdict),
            $retried,
            false,
            false,
            $doc->computedAt,
        );
    }

    /**
     * Facts-only: LLM unavailable after retries (I6) or verification discarded the narrative (I11).
     */
    public static function factsOnly(
        FactSet $facts,
        string $correlationId,
        string $reason,
        bool $retried,
        bool $degraded,
        ?VerificationVerdict $verdict,
    ): self {
        return new self(
            $facts->pid,
            ReadOutcome::FactsOnly,
            $correlationId,
            $facts,
            null,
            $reason,
            $degraded
                ? 'The narrative service is unavailable — the facts below are current.'
                : 'The generated narrative did not pass verification — the facts below are current.',
            $verdict !== null ? CheckSummary::listFromVerdict($verdict) : [],
            $retried,
            $degraded,
            false,
            null,
        );
    }

    /**
     * A capability crashed during extraction (§6.1): no digest, no ledger write, surviving facts
     * only, under a named banner.
     */
    public static function paused(
        FactSet $survivingFacts,
        string $correlationId,
        string $capabilityName,
    ): self {
        return new self(
            $survivingFacts->pid,
            ReadOutcome::Paused,
            $correlationId,
            $survivingFacts,
            null,
            'narrative unavailable',
            $capabilityName . ' unavailable — synthesis paused',
            [],
            false,
            false,
            false,
            null,
        );
    }

    /**
     * The SEV-1 patient-identity guard (V3) tripped: facts-only plus a sev-1 signal.
     */
    public static function frozen(
        FactSet $facts,
        string $correlationId,
        VerificationVerdict $verdict,
        bool $retried,
    ): self {
        return new self(
            $facts->pid,
            ReadOutcome::Frozen,
            $correlationId,
            $facts,
            null,
            'narrative unavailable',
            'Patient-identity check failed — synthesis frozen and reported (SEV-1).',
            CheckSummary::listFromVerdict($verdict),
            $retried,
            false,
            true,
            null,
        );
    }

    public function hasNarrative(): bool
    {
        return $this->narrative !== null && $this->narrative !== '';
    }

    /**
     * The always-present fact table: every fact that is neither in-flight nor excluded.
     *
     * @return list<array<string, mixed>>
     */
    public function factRows(): array
    {
        $rows = [];
        foreach ($this->facts->facts as $fact) {
            if (self::isInFlight($fact) || $fact->isExclusion()) {
                continue;
            }
            $rows[] = self::factRow($fact);
        }
        return $rows;
    }

    /**
     * The in-flight section: pending orders and preliminary results (§ in-flight rendering).
     *
     * @return list<array<string, mixed>>
     */
    public function inFlightRows(): array
    {
        $rows = [];
        foreach ($this->facts->facts as $fact) {
            if (self::isInFlight($fact)) {
                $rows[] = self::factRow($fact);
            }
        }
        return $rows;
    }

    /**
     * "N excluded (reason)" — excluded facts grouped by their reason flag, count descending is not
     * required (deterministic insertion order is enough for the note list).
     *
     * @return list<array{reason: string, count: int}>
     */
    public function exclusionNotes(): array
    {
        $counts = [];
        foreach ($this->facts->facts as $fact) {
            if (!$fact->isExclusion()) {
                continue;
            }
            $reason = 'unspecified';
            foreach ($fact->flags as $flag) {
                if (str_starts_with($flag, self::EXCLUDED_REASON_PREFIX)) {
                    $reason = substr($flag, strlen(self::EXCLUDED_REASON_PREFIX));
                    break;
                }
            }
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        $out = [];
        foreach ($counts as $reason => $count) {
            $out[] = ['reason' => (string) $reason, 'count' => $count];
        }
        return $out;
    }

    private static function isInFlight(Fact $fact): bool
    {
        return $fact->kind === FactKind::PendingOrder
            || $fact->kind === FactKind::PreliminaryResult
            || $fact->status === FactStatus::Preliminary;
    }

    /**
     * @return array<string, mixed>
     */
    private static function factRow(Fact $fact): array
    {
        $value = $fact->value;

        return [
            'fact_id' => $fact->factId,
            'fact_id_short' => substr($fact->factId, 0, 12),
            'capability' => $fact->capability->value,
            'kind' => $fact->kind->value,
            'clinical_date' => $fact->clinicalDate,
            'date_source' => $fact->dateSource->value,
            'status' => $fact->status->value,
            'value_raw' => $value?->raw,
            'value_parsed' => $value?->parsed,
            'unit' => $value?->unitCanonical ?? $value?->unitOriginal,
            'comparator' => $value?->comparator->value,
            'flags' => $fact->flags,
            'is_conflict' => $fact->isConflict(),
            'citations' => array_map(
                static fn(Citation $c): array => $c->toCanonical(),
                $fact->citations,
            ),
        ];
    }
}
