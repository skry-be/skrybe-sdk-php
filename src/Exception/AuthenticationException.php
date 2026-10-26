<?php

namespace Skrybe\Exception;

class AuthenticationException extends SkrybeException
{
    public function getType(): string
    {
        return 'authentication';
    }
}
