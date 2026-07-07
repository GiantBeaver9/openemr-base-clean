<?php

/**
 * Isolated tests for CircuitBreaker (U12b, §3.7) — pure cost-cap state transitions.
 *
 * Guards: closed under caps, open on daily cap, open on hourly burn cap, daily precedence,
 * manual override sticky, and implicit auto-reset when windowed spend falls back under.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Observability\BreakerReason;
use OpenEMR\Modules\ClinicalCopilot\Observability\BreakerState;
use OpenEMR\Modules\ClinicalCopilot\Observability\CircuitBreaker;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

function clinical_copilot_test_CircuitBreakerTest(): void
{
    $dailyCap = 100.0;
    $hourlyCap = 20.0;

    // ---- closed: spend under both caps ----
    $closed = CircuitBreaker::evaluate(50.0, 10.0, $dailyCap, $hourlyCap);
    Assert::equals(BreakerState::Closed, $closed->state, 'breaker is closed under both caps');
    Assert::equals(BreakerReason::Closed, $closed->reason, 'closed carries the Closed reason');
    Assert::equals('ok', $closed->readyToken(), 'closed breaker reports ok to /ready');
    Assert::that(!$closed->isOpen(), 'closed breaker isOpen() is false');

    // ---- open on daily cap (>=) ----
    $daily = CircuitBreaker::evaluate(100.0, 5.0, $dailyCap, $hourlyCap);
    Assert::equals(BreakerState::Open, $daily->state, 'breaker opens when daily spend meets the cap');
    Assert::equals(BreakerReason::DailyCapReached, $daily->reason, 'daily trip carries DailyCapReached');
    Assert::equals('circuit-open', $daily->readyToken(), 'open breaker reports circuit-open to /ready');

    // ---- open on hourly burn cap (daily still under) ----
    $hourly = CircuitBreaker::evaluate(50.0, 20.0, $dailyCap, $hourlyCap);
    Assert::equals(BreakerState::Open, $hourly->state, 'breaker opens when hourly burn meets the cap');
    Assert::equals(BreakerReason::HourlyBurnCapReached, $hourly->reason, 'hourly trip carries HourlyBurnCapReached');

    // ---- daily precedence when both exceeded ----
    $both = CircuitBreaker::evaluate(150.0, 40.0, $dailyCap, $hourlyCap);
    Assert::equals(BreakerReason::DailyCapReached, $both->reason, 'daily cap takes precedence over hourly when both exceeded');

    // ---- manual override is sticky regardless of spend ----
    $manual = CircuitBreaker::evaluate(0.0, 0.0, $dailyCap, $hourlyCap, BreakerState::Open);
    Assert::equals(BreakerState::Open, $manual->state, 'a manual open holds even with zero spend');
    Assert::equals(BreakerReason::ManualOpen, $manual->reason, 'manual trip carries ManualOpen');

    // ---- auto-reset: a fresh window (spend back under caps) closes with no extra logic ----
    $reset = CircuitBreaker::evaluate(1.0, 1.0, $dailyCap, $hourlyCap, BreakerState::Closed);
    Assert::equals(BreakerState::Closed, $reset->state, 'a new window under caps auto-resets to closed');

    // ---- a zero/absent cap is treated as "no cap", never trips ----
    $noCap = CircuitBreaker::evaluate(9999.0, 9999.0, 0.0, 0.0);
    Assert::equals(BreakerState::Closed, $noCap->state, 'a zero cap disables that trip (never a divide/false-open)');

    // ---- just under the cap stays closed (boundary) ----
    $justUnder = CircuitBreaker::evaluate(99.99, 19.99, $dailyCap, $hourlyCap);
    Assert::equals(BreakerState::Closed, $justUnder->state, 'spend just under the caps stays closed');
}
