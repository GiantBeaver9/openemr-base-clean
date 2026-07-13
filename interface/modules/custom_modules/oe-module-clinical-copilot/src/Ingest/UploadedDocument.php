<?php

/**
 * A safely-parsed uploaded file: bytes + filename + mime, from a $_FILES entry.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * Parse, don't validate, at the HTTP boundary: the raw `$_FILES` superglobal is
 * turned into this typed object once (in the endpoint), and the rest of the
 * ingestion works with bytes it can trust. Returns null for a missing/failed
 * upload or a disallowed type, so the endpoint can re-render the form with an
 * error instead of the domain layer having to reason about upload plumbing.
 */
final readonly class UploadedDocument
{
    /** Accepted source document mime types (lab reports / intake forms). */
    private const ALLOWED_MIME = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        public string $bytes,
        public string $filename,
        public string $mimeType,
    ) {
    }

    /**
     * @param mixed $entry the `$_FILES['document']` array, or null
     */
    public static function fromFilesEntry(mixed $entry): ?self
    {
        if (!is_array($entry)) {
            return null;
        }

        $tmpName = $entry['tmp_name'] ?? null;
        $error = $entry['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_string($tmpName) || $tmpName === '' || $error !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
            return null;
        }

        $bytes = @file_get_contents($tmpName);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $mime = self::resolveMime($tmpName, is_string($entry['type'] ?? null) ? $entry['type'] : '');
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return null;
        }

        $filename = is_string($entry['name'] ?? null) && $entry['name'] !== ''
            ? basename($entry['name'])
            : 'upload';

        return new self($bytes, $filename, $mime);
    }

    private static function resolveMime(string $tmpName, string $declared): string
    {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($tmpName);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return $declared;
    }
}
