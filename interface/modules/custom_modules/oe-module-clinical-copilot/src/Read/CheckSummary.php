<?php

/**
 * CheckSummary — a display-only, immutable row for the "citations checked" badge.
 *
 * The doc page shows a badge whose hover lists which of V1–V6 ran and whether each passed. That
 * information lives in two shapes: a live VerificationVerdict (freshly generated doc) or the
 * verdict array persisted inside a stored doc (cache hit). This value object is the single
 * template-facing shape both collapse to, so the badge renders identically on either path.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Read;

use OpenEMR\Modules\ClinicalCopilot\Verify\VerificationVerdict;

final readonly class CheckSummary
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $passed,
    ) {
    }

    /**
     * Collapse a live verdict into one summary per check (V1..V6 order preserved by toArray()).
     *
     * @return list<self>
     */
    public static function listFromVerdict(VerificationVerdict $verdict): array
    {
        return self::listFromArray($verdict->toArray());
    }

    /**
     * Collapse a persisted verdict array (as stored in a doc) into check summaries. Tolerant of a
     * missing/malformed 'checks' key — an empty list is a valid "no checks recorded" badge state.
     *
     * @param array<string, mixed> $verdictArray
     *
     * @return list<self>
     */
    public static function listFromArray(array $verdictArray): array
    {
        $checks = $verdictArray['checks'] ?? null;
        if (!is_array($checks)) {
            return [];
        }

        $out = [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $out[] = new self(
                is_string($check['check'] ?? null) ? $check['check'] : '',
                is_string($check['label'] ?? null) ? $check['label'] : '',
                (bool) ($check['passed'] ?? false),
            );
        }
        return $out;
    }
}
