<?php

/**
 * Isolated tests for DocStore + CopilotDoc + the DocGateway seam (U6).
 *
 * Guards: content-addressed round-trip (store -> find), history ordering by computed_at,
 * duplicate (pid, digest) served as the original row, and the append-only invariant (E7):
 * no mutation method on DocStore and no UPDATE/DELETE SQL in the store or db gateway.
 * Runs with the in-memory gateway, so no database is required.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Doc\CopilotDoc;
use OpenEMR\Modules\ClinicalCopilot\Doc\InMemoryDocGateway;
use OpenEMR\Modules\ClinicalCopilot\DocStore;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

function cc_make_doc(
    int $pid,
    string $digest,
    string $computedAt,
    string $narrative = 'body'
): CopilotDoc {
    return new CopilotDoc(
        $pid,
        $digest,
        'endo-previsit-v1',
        4242,
        json_encode(['facts' => [], 'citations' => [], 'narrative' => $narrative], JSON_THROW_ON_ERROR),
        json_encode(['control_proxy' => '1', 'med_response' => '2'], JSON_THROW_ON_ERROR),
        'prompt@1',
        $computedAt,
        '018f6c1e-0000-7000-8000-000000000001',
        1234,          // llm_latency_ms
        900,           // tokens_in
        450,           // tokens_out
        0.012345,      // cost_usd
        json_encode(['a1c' => 0], JSON_THROW_ON_ERROR),
    );
}

function clinical_copilot_test_DocStoreTest(): void
{
    // (a) round-trip: store then find via the in-memory gateway.
    $store = new DocStore(new InMemoryDocGateway());
    $digestA = str_repeat('a', 64);
    $docA = cc_make_doc(42, $digestA, '2026-01-05 09:00:00', 'first visit');

    $id = $store->store($docA);
    Assert::that($id > 0, 'store() returns a positive generated id');

    $found = $store->findByPidAndDigest(42, $digestA);
    Assert::that($found !== null, 'findByPidAndDigest resolves a stored document');
    Assert::equals($id, $found?->id, 'the found document carries its persisted id');
    Assert::equals(42, $found?->pid, 'round-trip preserves pid');
    Assert::equals($digestA, $found?->factDigest, 'round-trip preserves the content-address digest');
    Assert::equals(4242, $found?->apptId, 'round-trip preserves nullable appt_id metadata');
    Assert::equals(0.012345, $found?->costUsd, 'round-trip preserves decimal cost_usd');
    Assert::equals('2026-01-05 09:00:00', $found?->computedAt, 'round-trip preserves computed_at');
    Assert::that(
        str_contains((string) $found?->doc, 'first visit'),
        'round-trip preserves the served document JSON'
    );

    // A miss returns null rather than throwing.
    Assert::that(
        $store->findByPidAndDigest(42, str_repeat('f', 64)) === null,
        'findByPidAndDigest returns null for an unknown digest'
    );
    Assert::that(
        $store->findByPidAndDigest(99, $digestA) === null,
        'findByPidAndDigest is scoped by pid (I10 pinning)'
    );

    // Duplicate (pid, digest): digest recurrence serves the original row, id unchanged.
    $dupId = $store->store(cc_make_doc(42, $digestA, '2026-06-01 12:00:00', 'recomputed later'));
    Assert::equals($id, $dupId, 'a recurring (pid, digest) is served as the original row (unique key)');
    $stillFirst = $store->findByPidAndDigest(42, $digestA);
    Assert::that(
        str_contains((string) $stillFirst?->doc, 'first visit'),
        'the recurrence does not overwrite the original served document'
    );

    // (b) history ordering: inserted out of computed_at order, returned oldest first.
    $store->store(cc_make_doc(42, str_repeat('c', 64), '2026-03-10 08:00:00', 'third'));
    $store->store(cc_make_doc(42, str_repeat('b', 64), '2026-02-01 08:00:00', 'second'));
    $store->store(cc_make_doc(7, str_repeat('d', 64), '2026-01-01 08:00:00', 'other patient'));

    $history = $store->history(42);
    Assert::equals(3, count($history), 'history returns every distinct document for the patient');
    $order = array_map(static fn(CopilotDoc $d): string => $d->computedAt, $history);
    Assert::equals(
        ['2026-01-05 09:00:00', '2026-02-01 08:00:00', '2026-03-10 08:00:00'],
        $order,
        'history is ordered by computed_at, oldest first'
    );
    Assert::that(
        array_reduce($history, static fn(bool $c, CopilotDoc $d): bool => $c && $d->pid === 42, true),
        'history is scoped to the requested patient'
    );

    // (c) append-only invariant (E7): DocStore exposes no mutation method.
    $reflection = new ReflectionClass(DocStore::class);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();
        $mutating = stripos($name, 'update') !== false
            || stripos($name, 'delete') !== false
            || stripos($name, 'remove') !== false;
        Assert::that(!$mutating, "DocStore must expose no mutation method (found {$name})");
    }

    // (c) and no UPDATE/DELETE SQL in the store or the db-backed gateway.
    $srcRoot = __DIR__ . '/../../src/';
    foreach (['DocStore.php', 'Doc/DbDocGateway.php'] as $relative) {
        $source = file_get_contents($srcRoot . $relative);
        Assert::that($source !== false, "readable source for {$relative}");
        Assert::that(
            stripos((string) $source, 'UPDATE ') === false,
            "{$relative} contains no UPDATE SQL (append-only, E7)"
        );
        Assert::that(
            stripos((string) $source, 'DELETE ') === false,
            "{$relative} contains no DELETE SQL (append-only, E7)"
        );
    }
}
