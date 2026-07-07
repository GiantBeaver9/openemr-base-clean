<?php

/**
 * SeedBuilder — pre-pulls the pinned patient's full fact set and content-addresses it (§1.1).
 *
 * This is the chat seed's deterministic half: run all five capabilities via the SHARED
 * CapabilityFactory (identical wiring to the read path, so the chat seed and the synthesis doc
 * agree byte-for-byte), assemble one pinned FactSet, and compute the digest with the SAME
 * VersionBundle the synthesis uses (SynthesisVersions) — so a chat session can seed from its doc
 * and a per-turn drift check is a cheap digest comparison, no LLM involved (T5/T19).
 *
 * Pure over its injected CapabilityFactory: fixture-wired in tests, db-wired at runtime.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Doc\CopilotDoc;
use OpenEMR\Modules\ClinicalCopilot\Fact\Digest;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\SynthesisVersions;

final class SeedBuilder
{
    public function __construct(private readonly Digest $digest = new Digest())
    {
    }

    /**
     * Pull every capability's facts for one patient into a single pinned FactSet (I2 — fresh,
     * never cached). FactSet's constructor asserts the pin (I10) as a first line of defense.
     */
    public function buildFactSet(CapabilityFactory $factory, int $pid): FactSet
    {
        $facts = [];
        foreach ($factory->all() as $capability) {
            foreach ($capability->forPatient($pid) as $fact) {
                $facts[] = $fact;
            }
        }
        /** @var list<Fact> $facts */
        return new FactSet($pid, $facts);
    }

    /**
     * The digest of a fact set under the shared synthesis VersionBundle — the content address the
     * session pins and every turn re-checks for drift.
     */
    public function digestFor(CapabilityFactory $factory, FactSet $facts): string
    {
        return $this->digest->computeForSet($facts, SynthesisVersions::bundle($factory));
    }

    /**
     * Build a complete seed: the pre-pulled FactSet, the narrative extracted from the doc (empty
     * when no doc has been warmed yet — turn 1 still works from facts alone, I6), and the digest.
     */
    public function build(CapabilityFactory $factory, int $pid, ?CopilotDoc $doc): SessionSeed
    {
        $facts = $this->buildFactSet($factory, $pid);
        $digest = $this->digestFor($factory, $facts);
        $narrative = $this->narrativeFromDoc($doc);
        return new SessionSeed($facts, $narrative, $digest);
    }

    /**
     * Best-effort narrative extraction from the served doc JSON. The doc format is owned by the
     * read path (U8); we read a top-level `narrative` string if present, else join claim texts,
     * else return empty — never throwing on a shape we did not write.
     */
    public function narrativeFromDoc(?CopilotDoc $doc): string
    {
        if ($doc === null) {
            return '';
        }
        $decoded = json_decode($doc->doc, true);
        if (!is_array($decoded)) {
            return '';
        }
        if (isset($decoded['narrative']) && is_string($decoded['narrative'])) {
            return $decoded['narrative'];
        }
        if (isset($decoded['claims']) && is_array($decoded['claims'])) {
            $texts = [];
            foreach ($decoded['claims'] as $claim) {
                if (is_array($claim) && isset($claim['text']) && is_string($claim['text'])) {
                    $texts[] = $claim['text'];
                }
            }
            return implode("\n", $texts);
        }
        return '';
    }
}
