<?php

/**
 * DocContent — the JSON body persisted in mod_copilot_doc.doc and re-read on a cache hit.
 *
 * The doc column is "facts + citations + narrative" (ARCHITECTURE_COMPLETE.md). This value object
 * is the one place that shape is written and parsed, so the write path (generate) and the read
 * path (cache hit) can never disagree about the schema. It carries the served narrative, the raw
 * claim list the narrative was composed from, the canonical facts the doc was written over, and
 * the verification verdict that gated it — the last so the "citations checked" badge survives a
 * cache hit without re-verifying.
 *
 * The narrative stored here is ALREADY re-hydrated (identifiers restored, §4): a stored doc is a
 * faithful record of exactly what the physician saw.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Read;

final readonly class DocContent
{
    /**
     * @param list<array<string, mixed>> $claims  raw claim objects the narrative was composed from
     * @param list<array<string, mixed>> $facts   canonical facts the doc was written over
     * @param array<string, mixed>       $verdict VerificationVerdict::toArray() that gated the doc
     */
    public function __construct(
        public string $narrative,
        public array $claims,
        public array $facts,
        public array $verdict,
    ) {
    }

    public function toJson(): string
    {
        return (string) json_encode(
            [
                'narrative' => $this->narrative,
                'claims' => $this->claims,
                'facts' => $this->facts,
                'verdict' => $this->verdict,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Parse a stored doc body. Tolerant of a malformed row — every field defaults to an empty
     * shape rather than throwing, so a corrupt legacy doc degrades to facts-only, never a 500.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = [];
        }

        return new self(
            is_string($data['narrative'] ?? null) ? $data['narrative'] : '',
            is_array($data['claims'] ?? null) ? array_values($data['claims']) : [],
            is_array($data['facts'] ?? null) ? array_values($data['facts']) : [],
            is_array($data['verdict'] ?? null) ? $data['verdict'] : [],
        );
    }
}
