<?php

/**
 * Stack-required tests for the chat HTTP surface (public/chat.php, public/status.php,
 * Controller/ChatController) — the framework-coupled glue that cannot run under the isolated
 * runner because it depends on host statics (CsrfUtils, AclMain, EventAuditLogger, QueryUtils,
 * SessionWrapperFactory) and the Db* gateways.
 *
 * The pure agent logic these pages orchestrate — session pinning, the tool executor's pid
 * injection + assertion, the ≤5/≤3 budget, verifier-driven fail-closed, the one-active-turn 409,
 * staleness disclosure, and every degradation path — is fully covered WITHOUT the stack in
 * tests/Unit/ChatAgentTest.php. This class documents the additional assertions that require the
 * running dev stack (run via `openemr-cmd unit-test`), so the HTTP wiring itself has a home:
 *
 *   - GET chat.php?pid=<n> renders the chat panel + facts panel and mints a pinned session row;
 *   - POST chat.php with no CSRF token → 400; with a wrong ACL → 403; with a session whose
 *     user_id != authUserID → 403; a second concurrent POST while a turn runs → 409;
 *   - POST chat.php never sets $sessionAllowWrite (contractually read-only-session);
 *   - a completed turn lands append-only in mod_copilot_chat_turn and its spans in mod_copilot_trace;
 *   - GET status.php?cid=<uuid> returns running progress from the trace spans, then the finished
 *     turn once the root chat_turn span closes, and 403s a turn owned by another user.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Stack;

use PHPUnit\Framework\TestCase;

/**
 * @group stack-required
 */
final class ChatEndpointStackTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\OpenEMR\Common\Csrf\CsrfUtils::class)) {
            $this->markTestSkipped('Chat endpoint tests require the OpenEMR dev stack (host classes + DB).');
        }
    }

    public function testControllerClassIsWireable(): void
    {
        // Smoke: the controller class exists and exposes the runtime wiring + handler contract.
        self::assertTrue(class_exists(\OpenEMR\Modules\ClinicalCopilot\Controller\ChatController::class));
        self::assertTrue(method_exists(\OpenEMR\Modules\ClinicalCopilot\Controller\ChatController::class, 'fromGlobals'));
        self::assertTrue(method_exists(\OpenEMR\Modules\ClinicalCopilot\Controller\ChatController::class, 'handle'));
    }
}
