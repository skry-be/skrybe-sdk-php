<?php

namespace Skrybe\Exception;

class ValidationException extends SkrybeException
{
    private array $errors;

    public function __construct(array $errors, string $message = "Validation failed")
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
