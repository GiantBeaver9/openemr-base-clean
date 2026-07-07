<?php

/**
 * PatientPinViolationException — thrown when a fact returned by a capability does not carry the
 * pinned session pid (I10, §1.2 defense-in-depth with verifier V3).
 *
 * This is never a recoverable error: it means data for the wrong patient reached the tool
 * boundary, which is a SEV-1 condition (§3.5). The message deliberately carries only the fact
 * id and the two pids' equality failure — never a patient identifier.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

final class PatientPinViolationException extends \RuntimeException
{
}
