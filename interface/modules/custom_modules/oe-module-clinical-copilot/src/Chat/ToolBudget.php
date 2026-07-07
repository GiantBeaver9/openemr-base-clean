<?php

/**
 * ToolBudget — the ≤5-tool-call, ≤3-round chaining budget for one turn (§1.2).
 *
 * Unbounded agent loops are a latency and cost failure mode, so chaining is hard-capped. This
 * is a mutable per-turn counter (a turn is a single synchronous request); exhaustion is not an
 * error — it degrades transparently ("I retrieved X and Y; I did not retrieve Z — ask again").
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

final class ToolBudget
{
    public const MAX_TOOL_CALLS = 5;
    public const MAX_ROUNDS = 3;

    private int $callsUsed = 0;
    private int $roundsUsed = 0;

    public function __construct(
        private readonly int $maxToolCalls = self::MAX_TOOL_CALLS,
        private readonly int $maxRounds = self::MAX_ROUNDS,
    ) {
    }

    public function callsRemaining(): int
    {
        return max(0, $this->maxToolCalls - $this->callsUsed);
    }

    public function hasCallBudget(): bool
    {
        return $this->callsUsed < $this->maxToolCalls;
    }

    public function hasRoundBudget(): bool
    {
        return $this->roundsUsed < $this->maxRounds;
    }

    public function consumeCall(): void
    {
        $this->callsUsed++;
    }

    public function consumeRound(): void
    {
        $this->roundsUsed++;
    }

    public function callsUsed(): int
    {
        return $this->callsUsed;
    }

    public function roundsUsed(): int
    {
        return $this->roundsUsed;
    }
}
