<?php

/**
 * ClaimSchemaException — raised when raw model output does not parse into the claim schema (V1).
 *
 * Thrown by Claim::fromArray() when a raw claim object is malformed: missing text, an unknown
 * claim_type, or a citation/numeric/flag field that is not a list of scalars. Free prose with no
 * claim structure trips this too. The Verifier catches it and records a V1 failure — it is never
 * surfaced to the physician (house rule: no raw exception messages in user output).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Verify;

final class ClaimSchemaException extends \RuntimeException
{
}
