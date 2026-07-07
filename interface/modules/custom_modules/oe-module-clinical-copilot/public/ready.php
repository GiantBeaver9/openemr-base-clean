<?php

/**
 * GET /copilot/ready — unauthenticated but REDACTED readiness (§3.4, R6).
 *
 * Genuine dependency checks: DB round-trip, trace table writable (INSERT+ROLLBACK probe),
 * LLM reachable (a zero-cost countTokens on the U7 LlmClient, guarded by class_exists so
 * both php -l and runtime survive before U7 lands), worker heartbeat freshness, and
 * circuit-breaker state. Output is status enums ONLY — no latencies, no config values, no
 * PHI (e.g. "llm: ok | circuit-open | unreachable"). Degraded-but-serving is reported
 * honestly (I6). Per-IP rate-limited; external uptime probes point here (the worker's
 * dead-man switch, §3.5). This is the one place a DB outage legitimately shows red — that
 * is the point; liveness (health.php) stays green regardless.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Public endpoint: no session/auth required. Must be set before globals.php bootstraps.
$ignoreAuth = true;
$sessionAllowWrite = false;

require_once(dirname(__DIR__, 4) . "/globals.php");

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Observability\CadenceConfigStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\CircuitBreakerStore;
use OpenEMR\Modules\ClinicalCopilot\Observability\CorrelationId;

/**
 * Per-IP sliding-window limiter (best-effort, file-backed). Never throws — a limiter
 * failure must not take the probe down.
 */
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (mod_copilot_ready_rate_limited($clientIp, 60, 60)) {
    header('Content-Type: application/json');
    http_response_code(429);
    echo json_encode(['status' => 'rate-limited'], JSON_THROW_ON_ERROR);
    return;
}

// --- DB round-trip -----------------------------------------------------------------
$db = 'unreachable';
try {
    $one = QueryUtils::fetchSingleValue('SELECT 1 AS one', 'one', []);
    $db = ((int) $one === 1) ? 'ok' : 'unreachable';
} catch (\Throwable) {
    $db = 'unreachable';
}

// --- Trace table writable (INSERT + ROLLBACK probe, nothing persists) ---------------
$traceWritable = 'unwritable';
if ($db === 'ok') {
    try {
        QueryUtils::sqlStatementThrowException('START TRANSACTION', []);
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO mod_copilot_trace (correlation_id, span_id, kind, started_at, status)
             VALUES (?, ?, 'warm', ?, 'ok')",
            [CorrelationId::mint(), CorrelationId::spanId(), date('Y-m-d H:i:s.v')],
        );
        QueryUtils::sqlStatementThrowException('ROLLBACK', []);
        $traceWritable = 'ok';
    } catch (\Throwable) {
        try {
            QueryUtils::sqlStatementThrowException('ROLLBACK', []);
        } catch (\Throwable) {
            // best-effort cleanup
        }
        $traceWritable = 'unwritable';
    }
}

// --- Circuit-breaker state ----------------------------------------------------------
$breaker = 'unknown';
$breakerOpen = false;
if ($db === 'ok') {
    try {
        $decision = (new CircuitBreakerStore(new CadenceConfigStore()))->currentDecision();
        $breakerOpen = $decision->isOpen();
        $breaker = $decision->isOpen() ? 'open' : 'closed';
    } catch (\Throwable) {
        $breaker = 'unknown';
    }
}

// --- LLM reachability (zero-cost countTokens), breaker-aware ------------------------
$llm = mod_copilot_ready_llm_status($breakerOpen);

// --- Worker heartbeat freshness (dead-man switch, §3.5) -----------------------------
$worker = 'unknown';
if ($db === 'ok') {
    try {
        $last = QueryUtils::fetchSingleValue(
            "SELECT MAX(started_at) AS last_warm FROM mod_copilot_trace WHERE kind = 'warm'",
            'last_warm',
            [],
        );
        if (!is_string($last) || $last === '') {
            $worker = 'stale';
        } else {
            $ageSec = time() - (int) strtotime($last);
            // Tick interval default 5 min; stale after 2× (§3.5).
            $worker = ($ageSec > 600) ? 'stale' : 'ok';
        }
    } catch (\Throwable) {
        $worker = 'unknown';
    }
}

// --- Overall verdict ----------------------------------------------------------------
// DB / trace-store failures are "not ready". LLM-down or breaker-open is degraded-but-
// serving (facts-only mode, I6) — ready, honestly reported.
$ready = ($db === 'ok' && $traceWritable === 'ok');

header('Content-Type: application/json');
http_response_code($ready ? 200 : 503);
echo json_encode([
    'status' => $ready ? 'ready' : 'not-ready',
    'db' => $db,
    'trace_store' => $traceWritable,
    'llm' => $llm,
    'worker' => $worker,
    'breaker' => $breaker,
], JSON_THROW_ON_ERROR);

/**
 * Resolve the redacted LLM status token. Breaker-open short-circuits to circuit-open; the
 * U7 client is referenced by FQCN string and guarded so this survives before U7 exists.
 */
function mod_copilot_ready_llm_status(bool $breakerOpen): string
{
    if ($breakerOpen) {
        return 'circuit-open';
    }
    $llmClientClass = 'OpenEMR\\Modules\\ClinicalCopilot\\Reduce\\LlmClient';
    if (!class_exists($llmClientClass) || !method_exists($llmClientClass, 'countTokens')) {
        return 'unknown';
    }
    try {
        $client = new $llmClientClass();
        $client->countTokens('ready-probe');
        return 'ok';
    } catch (\Throwable) {
        return 'unreachable';
    }
}

/**
 * Best-effort per-IP sliding-window limiter. Returns true when the caller is over budget.
 * File-backed in the system temp dir; any I/O failure fails open (returns false).
 */
function mod_copilot_ready_rate_limited(string $ip, int $maxPerWindow, int $windowSec): bool
{
    try {
        $path = sys_get_temp_dir() . '/mod_copilot_ready_' . hash('sha256', $ip) . '.json';
        $now = time();
        $hits = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                foreach ($decoded as $ts) {
                    if (is_int($ts) && ($now - $ts) < $windowSec) {
                        $hits[] = $ts;
                    }
                }
            }
        }
        if (count($hits) >= $maxPerWindow) {
            return true;
        }
        $hits[] = $now;
        file_put_contents($path, json_encode($hits), LOCK_EX);
        return false;
    } catch (\Throwable) {
        return false;
    }
}
