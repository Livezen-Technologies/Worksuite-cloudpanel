<?php

namespace App\Contracts;

interface SourceValidator
{
    /** @return array{ok: bool, message: string, digest?: ?string} */
    public function image(string $reference, ?array $auth = null): array;

    /** @return array{ok: bool, message: string, commit?: ?string} */
    public function git(string $repository, string $branch): array;
}
