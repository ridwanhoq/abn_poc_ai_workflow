<?php

namespace App\Workflow\Exceptions;

use RuntimeException;

class InvalidWorkflowException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
