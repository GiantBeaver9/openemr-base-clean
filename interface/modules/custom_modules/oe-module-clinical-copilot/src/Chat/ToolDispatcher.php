<?php

/**
 * ToolDispatcher — the seam between the agent loop and tool execution (I13, §1.2).
 *
 * The ChatAgent depends on this interface, not the concrete ToolExecutor, so the loop's
 * highest-stakes branch — a pin-violation freeze (§2.3) — is directly testable with a fake
 * dispatcher, and so the executor can be swapped without touching the loop. The one runtime
 * implementation is ToolExecutor; it injects the pinned pid server-side and asserts it on return.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

interface ToolDispatcher
{
    /**
     * Dispose one model-proposed tool call for the pinned session patient. `$rawArgs` is untrusted
     * model output; `$sessionPid` is the server-side pin (never taken from the args).
     *
     * @param array<string, mixed> $rawArgs
     */
    public function execute(
        ToolName $tool,
        array $rawArgs,
        int $sessionPid,
        string $correlationId,
        ?string $parentSpanId = null,
        ?int $userId = null,
    ): ToolCallOutcome;
}
