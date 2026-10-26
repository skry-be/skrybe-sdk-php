<?php

namespace Skrybe\Exception;

class RateLimitException extends SkrybeException
{
    public function getType(): string
    {
        return 'rate_limit';
    }
}
