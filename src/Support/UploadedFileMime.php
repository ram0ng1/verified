<?php

namespace Ramon\Verified\Support;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Detecta o MIME real de um arquivo enviado lendo os bytes do stream
 * temporário com `finfo` (ou `mime_content_type` como fallback). Nunca
 * confia em `getClientMediaType()`, que vem do cliente.
 */
final class UploadedFileMime
{
    public static function detect(UploadedFileInterface $file): ?string
    {
        $tmpPath = $file->getStream()->getMetadata('uri');
        if (! is_string($tmpPath) || ! is_readable($tmpPath)) {
            return null;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);

                return is_string($detected) && $detected !== '' ? $detected : null;
            }
        }

        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($tmpPath);

            return is_string($detected) && $detected !== '' ? $detected : null;
        }

        return null;
    }
}
