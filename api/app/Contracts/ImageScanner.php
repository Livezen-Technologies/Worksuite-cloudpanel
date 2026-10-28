<?php

namespace App\Contracts;

interface ImageScanner
{
    /** @return array{critical:int, high:int, medium:int, low:int, vulnerabilities: array} */
    public function scan(string $reference, ?string $digest): array;
}
