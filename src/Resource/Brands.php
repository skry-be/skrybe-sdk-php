<?php

namespace Skrybe\Resource;

use Skrybe\Internal\Errors;
use Skrybe\Internal\Parse;
use Skrybe\Internal\Transport;

final class Brands
{
    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * The key's own brand: a brand key gets exactly one row. Empty, not an
     * error, when there is none.
     *
     * @return list<array{id: string, name: string}>
     */
    public function list(): array
    {
        $body = $this->transport->text('api/brands/get-brands.php', retryable: true);
        if (Errors::isProseEmpty($body)) {
            return [];
        }
        return Parse::numberedObject($body, 'brand');
    }
}
