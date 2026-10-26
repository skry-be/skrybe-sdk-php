<?php

namespace Skrybe\Exception;

class InvalidRequestException extends SkrybeException
{
    public function getType(): string
    {
        return 'invalid_request';
    }
}
