<?php

/**
 * LlmUnavailableException — the LLM path could not be used (missing creds, outage, timeout,
 * quota, or the breaker being open).
 *
 * The Reducer catches \Throwable broadly for degradation (I6); this type exists so the
 * runtime clients and the stub's "down" mode raise a single, recognizable failure whose
 * message never carries PHI and is never surfaced to a user (logged behind payload_ref).
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Reduce;

final class LlmUnavailableException extends \RuntimeException
{
}
