<?php

/**
 * TraceKind — the closed set of span kinds (mirrors mod_copilot_trace.kind).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

enum TraceKind: string
{
    case Extract = 'extract';
    case Digest = 'digest';
    case CacheLookup = 'cache_lookup';
    case LlmReduce = 'llm_reduce';
    case ChatTurn = 'chat_turn';
    case ToolCall = 'tool_call';
    case Verify = 'verify';
    case Render = 'render';
    case Warm = 'warm';
    case AlertEval = 'alert_eval';
}
