<?php

/**
 * BreakerState — the cost circuit breaker's two states (§3.7).
 *
 * Closed: LLM calls proceed. Open: chat degrades to the facts browser, synthesis serves
 * cache hits + facts-only on miss (the I6/I11 degradation paths, reused). Persisted to a
 * mod_copilot_cadence config row, so this is a backed enum.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

enum BreakerState: string
{
    case Closed = 'closed';
    case Open = 'open';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
