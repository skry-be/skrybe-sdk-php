<?php

namespace Skrybe\Resource;

use Skrybe\Internal\Transport;

final class Stats
{
    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Emails sent across the whole installation (campaigns, autoresponders
     * and transactional), not a per-brand figure. Public and cached for 5 minutes.
     */
    public function totalEmailsSent(): int
    {
        $data = $this->transport->json('api/stats/emails-sent.php', method: 'GET', anonymous: true, retryable: true);
        return (int) ($data['total_emails_sent'] ?? 0);
    }
}
