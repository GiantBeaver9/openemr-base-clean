<?php

/**
 * Integration smoke test: CapabilityFactory wires the full deterministic stack
 * (LabSlice → 5 capabilities) against the U2 fixtures, and every produced fact is
 * pinned to the requested patient (I10) with >=1 citation (V2). Also confirms the
 * VersionBundle inputs the read path needs are present.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Capability\CapabilityFactory;
use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;
use OpenEMR\Modules\ClinicalCopilot\Fact\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Tests\Assert;

function clinical_copilot_test_CapabilityFactoryTest(): void
{
    $fixtures = __DIR__ . '/../Fixtures';
    // Order-id → LOINC stand-in for procedure_order_code (not populated in fixtures).
    $factory = CapabilityFactory::fixture($fixtures, [4203 => '4548-4', 4303 => '4548-4']);

    Assert::equals(5, count($factory->all()), 'factory wires all five capabilities');

    $versions = $factory->capabilityVersions();
    Assert::equals('control_proxy@1', $versions['control_proxy'] ?? null, 'capability versions expose control_proxy for the digest');
    Assert::that($factory->cadenceVersion() !== '', 'cadence version is available for the VersionBundle');

    // Run the whole stack for a synthetic patient; collect facts.
    $pid = 9001;
    $all = [];
    foreach ($factory->all() as $capability) {
        foreach ($capability->forPatient($pid) as $fact) {
            $all[] = $fact;
        }
    }
    Assert::that(count($all) > 0, 'the capability stack produces facts for a synthetic patient');

    // Every fact is well-formed: pinned pid + at least one citation.
    $allPinned = true;
    $allCited = true;
    foreach ($all as $fact) {
        /** @var Fact $fact */
        if ($fact->pid !== $pid) {
            $allPinned = false;
        }
        if ($fact->citations === []) {
            $allCited = false;
        }
    }
    Assert::that($allPinned, 'every produced fact is pinned to the requested patient (I10)');
    Assert::that($allCited, 'every produced fact carries >=1 citation (V2)');

    // FactSet accepts the whole set (pin invariant holds across capabilities).
    $set = new FactSet($pid, $all);
    Assert::that($set->count() === count($all), 'the combined fact set assembles under one pinned patient');
}
