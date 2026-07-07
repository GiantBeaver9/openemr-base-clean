<?php

/**
 * CapabilityInterface — the one shape every deterministic capability implements.
 *
 * The five capabilities (ControlProxy, MedResponse, VitalsTrend, OverdueTests,
 * PendingResults) are the only producers of Facts, and the only tools the chat agent
 * may invoke (USERS.md UC6). Each is a pure function of a patient id: it reads chart
 * rows through an injected data-access interface and returns a list of citation-carrying
 * Facts — never touching global state, never mutating a core table, never seeing data
 * outside its own citation-backed fact set. `version()` is a digest input (E5): bumping
 * it invalidates exactly the docs that consumed the capability.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Capability;

use OpenEMR\Modules\ClinicalCopilot\Fact\Fact;

interface CapabilityInterface
{
    /**
     * Extract this capability's facts for one patient, fresh (I2 — never cached).
     *
     * @return list<Fact>
     */
    public function forPatient(int $pid): array;

    /**
     * The capability version string (a digest input, E5). Convention: `<name>@<n>`.
     */
    public function version(): string;
}
