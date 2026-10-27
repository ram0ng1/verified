<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Support;

use Psr\Http\Message\UploadedFileInterface;

/**
 * MIME real a partir dos bytes do arquivo temporário; nunca o tipo declarado
 * pelo cliente.
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
