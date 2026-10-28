<?php

namespace App\Drivers;

use App\Contracts\SourceValidator;

/** Accepts any well-formed source except names containing "missing" / "private" without auth. */
class FakeSourceValidator implements SourceValidator
{
    public function image(string $reference, ?array $auth = null): array
    {
        if (str_contains($reference, 'missing')) {
            return ['ok' => false, 'message' => "We couldn't find the image {$reference}. Check the name and tag."];
        }
        if (str_contains($reference, 'private') && empty($auth['password'])) {
            return ['ok' => false, 'message' => 'This image is private. Add registry credentials.'];
        }

        return ['ok' => true, 'message' => 'Image found.', 'digest' => 'sha256:'.hash('sha256', $reference)];
    }

    public function git(string $repository, string $branch): array
    {
        return str_contains($repository, 'missing')
            ? ['ok' => false, 'message' => "We couldn't reach {$repository}."]
            : ['ok' => true, 'message' => 'Repository reachable.', 'commit' => substr(hash('sha1', $repository.$branch), 0, 40)];
    }
}
