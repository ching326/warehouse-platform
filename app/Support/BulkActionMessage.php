<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

class BulkActionMessage
{
    public static function make(string $key, int $updated, int $skipped, array $replace = []): string
    {
        $messageKey = $skipped === 0 && Lang::has($key.'_no_skips')
            ? $key.'_no_skips'
            : $key;

        return __($messageKey, [
            ...$replace,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }
}
