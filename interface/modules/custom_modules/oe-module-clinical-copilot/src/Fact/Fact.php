<?php

/**
 * Fact — the one canonical shape every capability output, tool result, digest input,
 * LLM prompt fact, and verifier check operates on (ARCHITECTURE_COMPLETE.md "Fact object").
 *
 * The schema (schema/fact.schema.json), not this implementation, is the contract; this
 * class is the typed PHP realization of it. `fact_id` embeds the canonical value so a
 * preloaded fact and a later re-fetch of the same datum with a corrected value never
 * collide — keeping citation resolution (V2) unambiguous across a session (T19).
 *
 * Facts are immutable value objects. Producers build them; nothing mutates them.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

final readonly class Fact
{
    /** @var string content-addressed identity: hash(capability, kind, citations, canonical value) */
    public string $factId;

    /**
     * @param list<string>   $flags     canonical flag tokens (see Flag)
     * @param list<Citation> $citations >=1 citation for every fact (verifier V2 resolves these)
     */
    public function __construct(
        public Capability $capability,
        public string $capabilityVersion,
        public FactKind $kind,
        public int $pid,
        public ?string $clinicalDate,
        public DateSource $dateSource,
        public ?FactValue $value,
        public FactStatus $status,
        public array $flags,
        public array $citations,
    ) {
        if ($citations === []) {
            throw new \DomainException('Every fact must carry at least one citation (V2 invariant).');
        }
        $this->factId = self::computeFactId($capability, $kind, $value, $citations);
    }

    /**
     * fact_id = sha3-256 over (capability, kind, canonical value, sorted canonical citations).
     * Value is included deliberately (T19). Citations are sorted so map order never leaks.
     *
     * @param list<Citation> $citations
     */
    private static function computeFactId(
        Capability $capability,
        FactKind $kind,
        ?FactValue $value,
        array $citations,
    ): string {
        $citationForms = array_map(static fn(Citation $c): array => $c->toCanonical(), $citations);
        usort($citationForms, static function (array $a, array $b): int {
            return [$a['table'], $a['pk'], $a['field'] ?? '', $a['date_source']]
                <=> [$b['table'], $b['pk'], $b['field'] ?? '', $b['date_source']];
        });

        $material = [
            'capability' => $capability->value,
            'kind' => $kind->value,
            'value' => $value?->toCanonical(),
            'citations' => $citationForms,
        ];

        return hash('sha3-256', (string) json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function hasFlag(string $token): bool
    {
        return in_array($token, $this->flags, true);
    }

    public function isConflict(): bool
    {
        return $this->hasFlag(Flag::CONFLICT) || $this->kind === FactKind::Conflict;
    }

    public function isExclusion(): bool
    {
        return $this->kind === FactKind::Exclusion || $this->status === FactStatus::Excluded;
    }

    /**
     * Canonical associative form — the single source of truth for both digest input
     * and the LLM prompt fact bytes (they must be byte-identical; CanonicalSerializer
     * consumes this). Key order is fixed and stable.
     *
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        $flags = $this->flags;
        sort($flags); // flag set order must not affect the digest

        $citations = array_map(static fn(Citation $c): array => $c->toCanonical(), $this->citations);
        usort($citations, static function (array $a, array $b): int {
            return [$a['table'], $a['pk'], $a['field'] ?? '', $a['date_source']]
                <=> [$b['table'], $b['pk'], $b['field'] ?? '', $b['date_source']];
        });

        return [
            'fact_id' => $this->factId,
            'capability' => $this->capability->value,
            'capability_version' => $this->capabilityVersion,
            'kind' => $this->kind->value,
            'pid' => $this->pid,
            'clinical_date' => $this->clinicalDate,
            'date_source' => $this->dateSource->value,
            'value' => $this->value?->toCanonical(),
            'status' => $this->status->value,
            'flags' => array_values($flags),
            'citations' => $citations,
        ];
    }
}
