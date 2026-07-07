<?php

/**
 * CapabilityFactory — wires the five capabilities to their readers in one place.
 *
 * Shared by the read path (U8) and the chat tool executor (U11): both need the same
 * five capabilities, constructed identically, so they must not each re-wire them.
 * Two constructors: `db()` for runtime (QueryUtils/host-service readers) and
 * `fixture()` for isolated tests (fixture-JSON readers) — the read path and the chat
 * loop are therefore isolated-testable without a database.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Fact\Capability;
use OpenEMR\Modules\ClinicalCopilot\Lab\DbLabRowSource;
use OpenEMR\Modules\ClinicalCopilot\Lab\FixtureLabRowSource;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabCadenceConfig;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabRowSource;
use OpenEMR\Modules\ClinicalCopilot\Lab\LabSlice;
use OpenEMR\Modules\ClinicalCopilot\Lab\UnitConverter;

final class CapabilityFactory
{
    private function __construct(
        public readonly ControlProxy $controlProxy,
        public readonly OverdueTests $overdueTests,
        public readonly PendingResults $pendingResults,
        public readonly MedResponse $medResponse,
        public readonly VitalsTrend $vitalsTrend,
        private readonly CadenceConfig $cadence,
    ) {
    }

    /**
     * Fixture-backed wiring for isolated tests (no DB).
     *
     * @param array<int, string> $orderCodes optional procedure_order_id => LOINC map
     *                                        (stands in for procedure_order_code, which
     *                                        the fixtures don't populate)
     */
    public static function fixture(string $fixturesDir, array $orderCodes = []): self
    {
        $labCadence = LabCadenceConfig::fromFile($fixturesDir . '/mod_copilot_cadence.json');
        $cadence = CadenceConfig::fromFile($fixturesDir . '/mod_copilot_cadence.json');
        $source = new FixtureLabRowSource($fixturesDir);
        $pending = new FixturePendingOrderSource($fixturesDir, $orderCodes);
        $meds = new FixtureMedReader($fixturesDir);
        $vitals = new FixtureVitalsReader($fixturesDir);

        return self::wire($source, $labCadence, $cadence, $pending, $meds, $vitals);
    }

    /**
     * Runtime wiring over host tables. Cadence rows come from mod_copilot_cadence
     * (module-owned); pass a pre-loaded rows array to keep this testable.
     *
     * @param list<array<string, mixed>>|null $cadenceRows
     */
    public static function db(?array $cadenceRows = null): self
    {
        $cadenceRows ??= QueryUtils::fetchRecords(
            "SELECT config_key, config_value, version FROM mod_copilot_cadence",
            []
        );
        $labCadence = LabCadenceConfig::fromRows($cadenceRows);
        $cadence = CadenceConfig::fromRows($cadenceRows);
        $source = new DbLabRowSource();
        $pending = new DbPendingOrderSource();
        $meds = new DbMedReader();
        $vitals = new DbVitalsReader();

        return self::wire($source, $labCadence, $cadence, $pending, $meds, $vitals);
    }

    private static function wire(
        LabRowSource $source,
        LabCadenceConfig $labCadence,
        CadenceConfig $cadence,
        PendingOrderSource $pending,
        MedReader $meds,
        VitalsReader $vitals,
    ): self {
        $slice = new LabSlice($source, new UnitConverter($labCadence), $labCadence);
        $controlProxy = new ControlProxy($slice, $source, $cadence);

        return new self(
            $controlProxy,
            new OverdueTests($slice, $source, $cadence, $pending),
            new PendingResults($pending, $slice, $cadence),
            new MedResponse($meds, $controlProxy),
            new VitalsTrend($vitals),
            $cadence,
        );
    }

    /**
     * All five capabilities, in a stable order.
     *
     * @return list<CapabilityInterface>
     */
    public function all(): array
    {
        return [$this->controlProxy, $this->overdueTests, $this->pendingResults, $this->medResponse, $this->vitalsTrend];
    }

    /**
     * capability enum value => version string, for the VersionBundle (digest input).
     *
     * @return array<string, string>
     */
    public function capabilityVersions(): array
    {
        return [
            Capability::ControlProxy->value => $this->controlProxy->version(),
            Capability::OverdueTests->value => $this->overdueTests->version(),
            Capability::PendingResults->value => $this->pendingResults->version(),
            Capability::MedResponse->value => $this->medResponse->version(),
            Capability::VitalsTrend->value => $this->vitalsTrend->version(),
        ];
    }

    public function cadenceVersion(): string
    {
        return $this->cadence->version();
    }
}
