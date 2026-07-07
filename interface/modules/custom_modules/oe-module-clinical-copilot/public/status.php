<?php

/**
 * status.php — the SSE polling fallback (ARCHITECTURE.md §1.3).
 *
 * For proxies/buffering setups that break SSE, the client fires the identical POST and then polls
 * GET status.php?cid=<correlation_id>. This reads the SAME trace spans the turn is already writing
 * (I12) to render progress, and returns the finished turn from mod_copilot_chat_turn once the root
 * chat_turn span has closed. The observability table double-duties as the progress feed, so the UI
 * is provably what happened — not a parallel status variable that can drift. The turn is only
 * returned to the user who owns its session (identity re-check), and the access is audit-logged.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// Read-only session (§1.3).
$sessionAllowWrite = false;

require_once(dirname(__DIR__, 4) . "/globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\ClinicalCopilot\Observability\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\Observability\TraceQuery;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('patients', 'med')) {
    http_response_code(403);
    echo (string) json_encode(['error' => 'access-denied'], JSON_THROW_ON_ERROR);
    exit;
}

$authUserId = (int) ($_SESSION['authUserID'] ?? 0);
$cid = (string) ($_GET['cid'] ?? '');
if ($cid === '' || !CorrelationId::isValid($cid)) {
    http_response_code(400);
    echo (string) json_encode(['error' => 'a valid correlation id is required'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $spans = (new TraceQuery())->waterfall($cid);

    // Progress: the ordered span kinds + statuses the turn has written so far (redacted — no PHI).
    $progress = [];
    $rootClosed = false;
    foreach ($spans as $span) {
        $kind = (string) ($span['kind'] ?? '');
        $status = (string) ($span['status'] ?? '');
        $progress[] = [
            'kind' => $kind,
            'status' => $status,
            'model' => isset($span['model']) ? (string) $span['model'] : null,
            'duration_ms' => isset($span['duration_ms']) ? (int) $span['duration_ms'] : null,
        ];
        if ($kind === 'chat_turn' && ($span['duration_ms'] ?? null) !== null) {
            $rootClosed = true;
        }
    }

    if (!$rootClosed) {
        echo (string) json_encode([
            'status' => 'running',
            'correlation_id' => $cid,
            'progress' => $progress,
            'turn' => null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Root span closed: return the assistant turn — but ONLY to the user who owns its session.
    $rows = QueryUtils::fetchRecords(
        "SELECT t.content, t.tool_calls, t.verification_verdict, t.tokens_in, t.tokens_out, t.seq, s.id AS session_id, s.user_id
         FROM mod_copilot_chat_turn t
         JOIN mod_copilot_chat_session s ON s.id = t.session_id
         WHERE t.correlation_id = ? AND t.role = 'assistant'
         ORDER BY t.seq DESC LIMIT 1",
        [$cid],
    );
    $row = $rows[0] ?? null;
    if (!is_array($row)) {
        echo (string) json_encode([
            'status' => 'running',
            'correlation_id' => $cid,
            'progress' => $progress,
            'turn' => null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int) ($row['user_id'] ?? -1) !== $authUserId) {
        http_response_code(403);
        echo (string) json_encode(['error' => 'this turn belongs to another user'], JSON_THROW_ON_ERROR);
        exit;
    }

    $verdict = null;
    if (($row['verification_verdict'] ?? null) !== null) {
        $decoded = json_decode((string) $row['verification_verdict'], true);
        $verdict = is_array($decoded) ? $decoded : null;
    }

    echo (string) json_encode([
        'status' => 'done',
        'correlation_id' => $cid,
        'progress' => $progress,
        'turn' => [
            'session_id' => (int) $row['session_id'],
            'seq' => (int) $row['seq'],
            'answer' => (string) $row['content'],
            'verdict' => $verdict,
            'tokens_in' => isset($row['tokens_in']) ? (int) $row['tokens_in'] : null,
            'tokens_out' => isset($row['tokens_out']) ? (int) $row['tokens_out'] : null,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    (new SystemLogger())->error('Clinical Co-Pilot status poll failed', ['correlation_id' => $cid, 'exception' => $e]);
    http_response_code(500);
    echo (string) json_encode(['error' => 'status could not be read'], JSON_THROW_ON_ERROR);
}
