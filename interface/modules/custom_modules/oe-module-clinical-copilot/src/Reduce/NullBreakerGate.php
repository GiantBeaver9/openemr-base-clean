<?php

/**
 * NullBreakerGate — a breaker that is always closed (the default when no cost breaker is
 * wired, and the isolated-test default).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

final class NullBreakerGate implements BreakerGate
{
    public function isOpen(): bool
    {
        return false;
    }
}
