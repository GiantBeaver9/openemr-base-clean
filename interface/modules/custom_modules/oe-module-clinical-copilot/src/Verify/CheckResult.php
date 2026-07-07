<?php

/**
 * CheckResult — the outcome of one deterministic check (V1–V6) plus its findings.
 *
 * Findings are phrased specifically enough to append verbatim to a regeneration prompt
 * (ARCHITECTURE.md §2.3: "claim 3 cites F17 which does not contain 8.4"). They never contain
 * direct patient identifiers — fact ids and claim indices only.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

final readonly class CheckResult
{
    /**
     * @param list<string> $findings
     */
    public function __construct(
        public CheckId $check,
        public bool $passed,
        public array $findings = [],
    ) {
    }

    /**
     * @return array{check: string, label: string, passed: bool, findings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'check' => $this->check->value,
            'label' => $this->check->label(),
            'passed' => $this->passed,
            'findings' => $this->findings,
        ];
    }
}
