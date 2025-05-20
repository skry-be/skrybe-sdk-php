<?php

namespace Skrybe;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

trait LoggerTrait
{
    private LoggerInterface $logger;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
