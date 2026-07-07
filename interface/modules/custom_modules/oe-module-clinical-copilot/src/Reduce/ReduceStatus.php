<?php

/**
 * ReduceStatus — the outcome of a reduce pass.
 *
 * Ok: the model returned raw output for the verifier to gate (U10). Degraded: the LLM was
 * unavailable after breaker-aware retries, so the pass returns facts-only, marked "narrative
 * unavailable" (I6). There is no third state — a reduce either produced candidate prose or it
 * degraded; it never partially serves.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

enum ReduceStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
}
