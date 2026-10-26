<?php

namespace Skrybe\Exception;

class ApiException extends SkrybeException
{
    public function getType(): string
    {
        return 'api';
    }
}
