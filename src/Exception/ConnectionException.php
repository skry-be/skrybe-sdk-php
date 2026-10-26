<?php

namespace Skrybe\Exception;

class ConnectionException extends SkrybeException
{
    public function getType(): string
    {
        return 'connection';
    }
}
