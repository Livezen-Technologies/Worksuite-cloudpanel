<?php

namespace App\Contracts;

interface SourceValidator
{
    /** @return array{ok: bool, message: string, digest?: ?string} */
    public function image(string $reference, ?array $auth = null): array;

    /** @return array{ok: bool, message: string, commit?: ?string} */
    public function git(string $repository, string $branch): array;

    /** The branch HEAD points at (e.g. main or master), or null when it can't be read. */
    public function defaultBranch(string $repository): ?string;
}
