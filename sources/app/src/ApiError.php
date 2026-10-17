<?php declare(strict_types=1);

namespace Madeline\Web;

use RuntimeException;

/**
 * An error meant for the browser: an HTTP status plus a stable, translatable
 * code (Telegram RPC errors are passed through as-is, e.g. PHONE_CODE_INVALID).
 */
final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }
}
