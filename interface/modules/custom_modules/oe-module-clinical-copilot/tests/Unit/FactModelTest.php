<?php

/**
 * Isolated tests for the fact model, canonical serializer, and digest (U3).
 *
 * Guards: fact_id includes value (T19), citation is mandatory (V2), digest is
 * order-independent and version-sensitive (E5/E6), FactSet pins one patient (I10).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Fact\Citation;
use OpenEMR\Modules\ClinicalCopilot\Fact\Comparator;
use OpenEMR\Modules\ClinicalCopilot\Fact\DateSource;
use OpenEMR\Modules\ClinicalCopilot\Fact\Digest;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactKind;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactStatus;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactValue;
use OpenEMR\Modules\ClinicalCopilot\Fact\VersionBundle;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

/**
 * @param int|string $pid
 */
function cc_make_fact(int $pid, string $raw, ?float $parsed, string $date, string $table = 'procedure_result', int $pk = 1): Fact
{
    return new Fact(
        Capability::ControlProxy,
        'control_proxy@1',
        FactKind::TrendPoint,
        $pid,
        $date,
        DateSource::Collected,
        new FactValue($raw, $parsed, Comparator::None, '%', '%', 'conv@1'),
        FactStatus::Final,
        [],
        [new Citation($table, $pk, 'result', DateSource::Collected)],
    );
}

function clinical_copilot_test_FactModelTest(): void
{
    $versions = new VersionBundle(
        ['control_proxy' => '1', 'med_response' => '1'],
        'cadence@1',
        'codeset@1',
        'endo-previsit-v1',
        'prompt@1',
    );
    $digest = new Digest();

    // fact_id embeds the value: same citations, different value ⇒ different id (T19).
    $a = cc_make_fact(42, '7.2', 7.2, '2026-01-05');
    $b = cc_make_fact(42, '8.4', 8.4, '2026-01-05');
    Assert::that($a->factId !== $b->factId, 'fact_id changes when the value changes (corrected-lab non-collision)');

    // Same inputs ⇒ same fact_id (deterministic).
    $a2 = cc_make_fact(42, '7.2', 7.2, '2026-01-05');
    Assert::equals($a->factId, $a2->factId, 'fact_id is deterministic for identical inputs');

    // Citation is mandatory.
    Assert::throws(
        static fn() => new Fact(
            Capability::ControlProxy,
            '1',
            FactKind::TrendPoint,
            42,
            '2026-01-05',
            DateSource::Collected,
            null,
            FactStatus::Final,
            [],
            [], // no citations
        ),
        'a fact with zero citations is rejected (V2 invariant)'
    );

    // Digest is order-independent: same facts in different input order ⇒ same digest (E6).
    $c = cc_make_fact(42, '9.0', 9.0, '2026-02-05', 'procedure_result', 2);
    $d1 = $digest->compute([$a, $c], $versions);
    $d2 = $digest->compute([$c, $a], $versions);
    Assert::equals($d1, $d2, 'digest is independent of fact input order (E6 determinism)');

    // Digest is version-sensitive: bump a version ⇒ digest changes (E5).
    $bumped = new VersionBundle(
        ['control_proxy' => '2', 'med_response' => '1'],
        'cadence@1',
        'codeset@1',
        'endo-previsit-v1',
        'prompt@1',
    );
    Assert::that($digest->compute([$a, $c], $bumped) !== $d1, 'digest changes when a capability version bumps (E5 config drift)');

    // Digest changes when a fact value changes (late arrival / correction, E1/E2).
    Assert::that($digest->compute([$b, $c], $versions) !== $d1, 'digest changes when a cited value changes (E1/E2)');

    // FactSet pins one patient (I10).
    Assert::throws(
        static fn() => new FactSet(42, [$a, cc_make_fact(99, '5.0', 5.0, '2026-01-05')]),
        'FactSet rejects a fact from a different patient (I10 pinning)'
    );

    $set = new FactSet(42, [$a, $c]);
    Assert::equals($a->factId, $set->findById($a->factId)?->factId, 'FactSet resolves a fact by id (V2 support)');
    Assert::equals(2, $set->count(), 'FactSet counts its facts');
}
