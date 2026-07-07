<?php

/**
 * DB-backed U11 acceptance evals: session insert/find/freeze; append-only turn ledger.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Db\Chat;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStatus;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatSessionStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnRole;
use OpenEMR\Modules\ClinicalCopilot\Chat\ChatTurnStore;
use OpenEMR\Modules\ClinicalCopilot\Chat\NewChatSession;
use OpenEMR\Modules\ClinicalCopilot\Chat\NewChatTurn;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ChatSessionAndTurnStoreTest extends TestCase
{
    private ChatSessionStore $sessionStore;
    private ChatTurnStore $turnStore;
    private int $pid;

    protected function setUp(): void
    {
        QueryUtils::startTransaction();
        $this->pid = self::insertSyntheticPatient();
        $this->sessionStore = new ChatSessionStore();
        $this->turnStore = new ChatTurnStore();
    }

    protected function tearDown(): void
    {
        QueryUtils::rollbackTransaction();
    }

    public function testInsertAndFindRoundTrips(): void
    {
        $id = $this->sessionStore->insert(new NewChatSession($this->pid, 1, 42, 'digest-abc'));
        $session = $this->sessionStore->find($id);

        self::assertNotNull($session);
        self::assertSame($this->pid, $session->pid);
        self::assertSame(1, $session->userId);
        self::assertSame(42, $session->docId);
        self::assertSame('digest-abc', $session->factDigest);
        self::assertSame(ChatSessionStatus::Active, $session->status);
    }

    public function testFreezeIsIdempotentAndNeverReversedInThisClass(): void
    {
        $id = $this->sessionStore->insert(new NewChatSession($this->pid, 1, 42, 'digest-abc'));

        $this->sessionStore->freeze($id);
        $this->sessionStore->freeze($id); // idempotent -- must not throw or double-apply

        $session = $this->sessionStore->find($id);
        self::assertSame(ChatSessionStatus::Frozen, $session->status);

        // I3-style discipline: this class exposes no unfreeze method at all
        // (verified by construction, not by a runtime assertion) -- see the
        // class docblock.
    }

    public function testTurnsAreAppendOnlyAndOrderedBySeq(): void
    {
        $sessionId = $this->sessionStore->insert(new NewChatSession($this->pid, 1, 42, 'digest-abc'));

        $correlationId = Uuid::uuid7()->toString();
        $this->turnStore->insert(new NewChatTurn($sessionId, 1, ChatTurnRole::User, ['text' => 'q1'], null, null, $correlationId, null, null, null));
        $this->turnStore->insert(new NewChatTurn($sessionId, 2, ChatTurnRole::Assistant, ['claims' => []], null, null, $correlationId, 10, 20, null));

        $turns = $this->turnStore->forSession($sessionId);
        self::assertCount(2, $turns);
        self::assertSame(ChatTurnRole::User, $turns[0]->role);
        self::assertSame(ChatTurnRole::Assistant, $turns[1]->role);
        self::assertSame(1, $this->turnStore->countAssistantTurns($sessionId));
        self::assertSame(3, $this->turnStore->nextSeq($sessionId));

        $byCorrelation = $this->turnStore->findByCorrelationId($correlationId);
        self::assertCount(2, $byCorrelation);
    }

    private static function insertSyntheticPatient(): int
    {
        $pid = QueryUtils::fetchSingleValue('SELECT MAX(`pid`) + 1 AS pid FROM `patient_data`', 'pid');
        $pid = $pid !== null ? (int)$pid : 1;

        $uuid = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();
        QueryUtils::sqlInsert(
            'INSERT INTO `patient_data`
                (`uuid`, `pid`, `pubpid`, `fname`, `lname`, `DOB`, `sex`, `date`, `regdate`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'clinical_copilot_chat_store_test\')',
            [$uuid, $pid, 'CCP-STORE-' . $pid, 'Synthetic', 'Patient', '1970-01-01', 'Female'],
        );

        return $pid;
    }
}
