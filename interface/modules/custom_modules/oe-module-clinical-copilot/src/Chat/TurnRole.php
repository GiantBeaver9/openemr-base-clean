<?php

/**
 * TurnRole — the author of an append-only chat turn row (mirrors mod_copilot_chat_turn.role).
 *
 * `user` is the physician's message, `assistant` the verified answer (or facts-only
 * degradation), `tool` a persisted tool request+result. Every turn is written append-only
 * so "what exactly did the physician see" is answerable byte-for-byte (T7).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

enum TurnRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
