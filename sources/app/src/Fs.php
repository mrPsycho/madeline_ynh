<?php declare(strict_types=1);

namespace Madeline\Web;

use Throwable;

/**
 * Best-effort file removal. MadelineProto installs an error handler that turns
 * warnings into exceptions even under "@" (PHP 8 no longer zeroes
 * error_reporting() there), so failures are caught explicitly instead.
 */
final class Fs
{
    public static function remove(string $path): void
    {
        try {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        self::remove("$path/$entry");
                    }
                }
                rmdir($path);
            } elseif (file_exists($path) || is_link($path)) {
                unlink($path);
            }
        } catch (Throwable) {
        }
    }
}
