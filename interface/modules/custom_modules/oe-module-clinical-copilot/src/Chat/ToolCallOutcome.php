<?php

/**
 * ToolCallOutcome — the result of the executor disposing one model-proposed tool call (§1.2).
 *
 * Three shapes:
 *  - ok:         the capability ran and returned pinned facts (added to the session fact set);
 *  - failure:    schema-invalid args or a capability crash — surfaced to the model AND the user,
 *                never silently absorbed (§6.2), so the answer can say "vitals lookup failed…";
 *  - pinViolation: a returned fact carried a foreign pid — a SEV-1 patient-guard trip; the caller
 *                freezes the session and does not continue.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

final readonly class ToolCallOutcome
{
    /**
     * @param list<Fact> $facts
     */
    private function __construct(
        public ToolName $tool,
        public bool $ok,
        public array $facts,
        public ?string $error,
        public bool $pinViolation,
    ) {
    }

    /**
     * @param list<Fact> $facts
     */
    public static function ok(ToolName $tool, array $facts): self
    {
        return new self($tool, true, $facts, null, false);
    }

    public static function failure(ToolName $tool, string $error): self
    {
        return new self($tool, false, [], $error, false);
    }

    public static function pinViolation(ToolName $tool, string $error): self
    {
        return new self($tool, false, [], $error, true);
    }
}
