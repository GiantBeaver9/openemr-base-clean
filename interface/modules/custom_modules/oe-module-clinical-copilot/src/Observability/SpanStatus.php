<?php

/**
 * SpanStatus — outcome of a traced span (mirrors mod_copilot_trace.status).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

enum SpanStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Retried = 'retried';
    case Degraded = 'degraded';
}
