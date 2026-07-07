<?php

/**
 * CanonicalSerializer — the one pure function that turns facts into deterministic bytes.
 *
 * The SAME serialization feeds both the digest and the LLM prompt (compute model,
 * ARCHITECTURE_COMPLETE.md): stable sort keys, ISO-8601 dates, normalized decimals,
 * no map-order leakage. Two extractions over identical DB state must serialize
 * byte-for-byte identically (determinism eval E6).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

final class CanonicalSerializer
{
    /**
     * Deterministically order facts. Ordering is by (clinical_date, capability, kind,
     * fact_id) — a total order independent of production/map order. fact_id breaks any
     * remaining tie, so the sort is stable regardless of input order.
     *
     * @param list<Fact> $facts
     * @return list<array<string, mixed>>
     */
    public function canonicalize(array $facts): array
    {
        $forms = array_map(static fn(Fact $f): array => $f->toCanonical(), $facts);

        usort($forms, static function (array $a, array $b): int {
            // null clinical_date sorts last (undated facts after dated ones), stably.
            $da = $a['clinical_date'] ?? '~';
            $db = $b['clinical_date'] ?? '~';
            return [$da, $a['capability'], $a['kind'], $a['fact_id']]
                <=> [$db, $b['capability'], $b['kind'], $b['fact_id']];
        });

        return $forms;
    }

    /**
     * Serialize an ordered fact list to canonical JSON bytes. JSON_UNESCAPED_* keeps
     * the bytes stable across environments; no pretty-printing (whitespace would leak).
     *
     * @param list<Fact> $facts
     */
    public function serialize(array $facts): string
    {
        $canonical = $this->canonicalize($facts);
        return (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
