<?php

namespace App\Support;

/**
 * Unexpected failures: full details go to the server log (Railway logs), the client only
 * gets a generic message. Exception text can contain SQL, table names and file paths.
 */
final class Errors
{
    public const GENERIC = 'Erro interno no servidor. Tente novamente.';

    public static function report(\Throwable $e): string
    {
        error_log(sprintf(
            '[api] %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        return self::GENERIC;
    }
}
