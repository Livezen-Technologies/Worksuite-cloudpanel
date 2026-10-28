<?php

namespace App\Services;

use App\Support\CanonicalJson;

final class CanonicalPayload
{
    public static function of(array $data): string
    {
        return CanonicalJson::encode($data);
    }
}
