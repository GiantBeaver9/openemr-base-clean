<?php

/**
 * ChatSessionStatus — the closed lifecycle state of a pid-pinned chat session (§1.1).
 *
 * `active` is the normal state; `frozen` is the verifier's SEV-1 terminal state — a V3
 * patient-identity-guard trip (§2.3) freezes the session and it is never continued. The
 * frozen row is preserved as incident evidence (§3.5); nothing un-freezes it in code.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Chat;

enum ChatSessionStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
