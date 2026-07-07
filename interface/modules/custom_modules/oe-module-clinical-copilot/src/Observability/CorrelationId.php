<?php

/**
 * CorrelationId — mints the UUIDv7 threaded through every span, log line, and stored row.
 *
 * One id per agent invocation (synthesis read, worker warm, chat turn, health probe),
 * minted at the entry point (R2/I12). UUIDv7 is time-ordered so traces sort naturally.
 * The whole module calls this — no unit invents its own id scheme.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Observability;

final class CorrelationId
{
    /**
     * Mint a UUIDv7 (unix-ms timestamp prefix + random). Uses a caller-supplied epoch-ms
     * so callers on the request path can pass a real timestamp; defaults to a random,
     * monotonic-enough value when none is given (kept out of clock APIs so this is testable).
     */
    public static function mint(?int $unixMillis = null): string
    {
        $ms = $unixMillis ?? (int) (hexdec(bin2hex(random_bytes(6))) & 0xFFFFFFFFFFFF);
        $rand = random_bytes(10);

        $timeHex = str_pad(dechex($ms & 0xFFFFFFFFFFFF), 12, '0', STR_PAD_LEFT);

        $bytes = hex2bin($timeHex) . $rand;
        // set version (7) and variant (10xx) bits
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * A span id is just a fresh UUIDv7 (children reference their parent's span id).
     */
    public static function spanId(?int $unixMillis = null): string
    {
        return self::mint($unixMillis);
    }

    public static function isValid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) === 1;
    }
}
