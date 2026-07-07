<?php

/**
 * Digest — content-addresses a narrative by the facts + versions it was written over.
 *
 * This is the load-bearing decision (T5): freshness is cache addressing, not a check.
 * digest = sha3-256( canonical(facts) ‖ capability versions ‖ cadence/config version ‖
 *                    code-set version ‖ doc_type ‖ reduce prompt+schema version ).
 * No timestamps participate (invariant I1/I4). Serving prose over facts that no longer
 * hold is structurally unreachable: different facts ⇒ different digest ⇒ cache miss.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Fact;

final class Digest
{
    public function __construct(private readonly CanonicalSerializer $serializer = new CanonicalSerializer())
    {
    }

    /**
     * @param list<Fact> $facts
     */
    public function compute(array $facts, VersionBundle $versions): string
    {
        $material = [
            'facts' => $this->serializer->canonicalize($facts),
            'versions' => $versions->toCanonical(),
        ];
        return hash('sha3-256', (string) json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function computeForSet(FactSet $set, VersionBundle $versions): string
    {
        return $this->compute($set->facts, $versions);
    }
}
