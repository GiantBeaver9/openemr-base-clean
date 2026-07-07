<?php

/**
 * Isolated tests for RateLimiter (U12b, §3.7) — pure limit decision logic.
 *
 * Guards: one active turn per session (409), per-session turn cap, per-user session cap,
 * per-user hourly turn cap, decision precedence, and the allow path.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimitConfig;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimitCounts;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimiter;
use OpenEMR\Modules\ClinicalCopilot\Observability\RateLimitReason;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

function clinical_copilot_test_RateLimiterTest(): void
{
    $config = new RateLimitConfig(); // defaults: 1 / 30 / 3 / 60

    // ---- allow: well within every limit ----
    $ok = RateLimiter::decide(new RateLimitCounts(0, 5, 1, 10), $config);
    Assert::that($ok->allowed, 'a turn within all limits is allowed');
    Assert::equals(200, $ok->httpStatus(), 'allowed decision reports HTTP 200');
    Assert::equals('', $ok->clientHint(), 'allowed decision carries no hint');

    // ---- one active turn per session => 409 ----
    $busy = RateLimiter::decide(new RateLimitCounts(1, 5, 1, 10), $config);
    Assert::that(!$busy->allowed, 'a second turn while one is running is denied');
    Assert::equals(RateLimitReason::SessionTurnInProgress, $busy->reason, 'in-flight turn maps to SessionTurnInProgress');
    Assert::equals(409, $busy->httpStatus(), 'an in-flight turn is a 409 conflict (deterministic double-submit)');
    Assert::that($busy->clientHint() !== '', 'a denied decision carries a client hint');

    // ---- per-session turn cap (30) ----
    $sessionCap = RateLimiter::decide(new RateLimitCounts(0, 30, 1, 10), $config);
    Assert::equals(RateLimitReason::SessionTurnCapReached, $sessionCap->reason, 'the 30th completed turn caps the session');
    Assert::equals(429, $sessionCap->httpStatus(), 'session turn cap is a 429');

    // ---- per-user active session cap (3) ----
    $sessionsCap = RateLimiter::decide(new RateLimitCounts(0, 5, 4, 10), $config);
    Assert::equals(RateLimitReason::UserSessionCapReached, $sessionsCap->reason, 'a 4th active session is denied');
    Assert::that(RateLimiter::decide(new RateLimitCounts(0, 5, 3, 10), $config)->allowed, 'exactly 3 active sessions is still allowed');

    // ---- per-user hourly turn cap (60) ----
    $hourCap = RateLimiter::decide(new RateLimitCounts(0, 5, 1, 60), $config);
    Assert::equals(RateLimitReason::UserHourlyTurnCapReached, $hourCap->reason, 'the 60th turn this hour is denied');
    Assert::equals(429, $hourCap->httpStatus(), 'hourly cap is a 429');

    // ---- precedence: in-flight turn wins over other exhausted caps ----
    $allExhausted = RateLimiter::decide(new RateLimitCounts(1, 30, 4, 60), $config);
    Assert::equals(RateLimitReason::SessionTurnInProgress, $allExhausted->reason, 'in-flight 409 is reported before the softer caps');

    // ---- config is honoured (tighter limits) ----
    $tight = new RateLimitConfig(1, 2, 1, 5);
    Assert::equals(RateLimitReason::SessionTurnCapReached, RateLimiter::decide(new RateLimitCounts(0, 2, 1, 0), $tight)->reason, 'a tighter session cap fires earlier');
}
