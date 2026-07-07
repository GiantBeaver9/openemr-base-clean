<?php

/**
 * ParsedValue — the outcome of C3 value parsing.
 *
 * `parsed` is null unless a numeric was safely extracted; null ⇒ NO numeric claim is
 * permitted downstream. A comparator other than None marks a censored value ("<7.0"):
 * the number is retained for direction-only claims but must never be treated as exact.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Lab;

use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;

final readonly class ParsedValue
{
    public function __construct(
        public ?float $parsed,
        public Comparator $comparator,
    ) {
    }

    public function isCensored(): bool
    {
        return $this->comparator->isCensored();
    }
}
