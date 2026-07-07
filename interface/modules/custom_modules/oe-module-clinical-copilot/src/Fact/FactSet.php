<?php

/**
 * FactSet — an ordered, immutable collection of facts for one patient.
 *
 * This is the unit the synthesis reduces over and the chat session seeds with. It
 * enforces the pinning invariant at the boundary: every fact carries the same pid,
 * asserted here (defense in depth with the tool executor and verifier V3).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

final readonly class FactSet
{
    /** @var list<Fact> */
    public array $facts;

    /**
     * @param list<Fact> $facts
     */
    public function __construct(public int $pid, array $facts)
    {
        foreach ($facts as $fact) {
            if ($fact->pid !== $pid) {
                throw new \DomainException('FactSet pin violation: a fact does not belong to the pinned patient.');
            }
        }
        $this->facts = array_values($facts);
    }

    /**
     * Merge additional facts (e.g. chat tool results) into a new, larger set — the
     * original is never mutated (facts are never cached, only accumulated per session).
     *
     * @param list<Fact> $more
     */
    public function withFacts(array $more): self
    {
        return new self($this->pid, [...$this->facts, ...$more]);
    }

    public function findById(string $factId): ?Fact
    {
        foreach ($this->facts as $fact) {
            if ($fact->factId === $factId) {
                return $fact;
            }
        }
        return null;
    }

    /**
     * @return list<Fact>
     */
    public function conflicts(): array
    {
        return array_values(array_filter($this->facts, static fn(Fact $f): bool => $f->isConflict()));
    }

    public function isEmpty(): bool
    {
        return $this->facts === [];
    }

    public function count(): int
    {
        return count($this->facts);
    }
}
