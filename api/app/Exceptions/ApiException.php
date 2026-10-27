<?php

namespace App\Exceptions;

use RuntimeException;

/** Raised by services; rendered in the structured error format. */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $fields = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $message, array $extra = []): self
    {
        return new self('conflict', $message, 409, [], $extra);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function quota(string $message, array $extra = []): self
    {
        return new self('quota_exceeded', $message, 402, [], $extra);
    }

    public static function validation(array $fields, string $message = 'The given data was invalid.'): self
    {
        return new self('validation_failed', $message, 422, $fields);
    }
}
