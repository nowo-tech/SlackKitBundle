<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Event;

/**
 * An unverified link gave way to another account (expired claim or proven e-mail claim, contract §8.2). Hosts write their audit row from it.
 */
final class SlackLinkReleasedEvent
{
    /**
     * @param string $holderAccountId account that lost the unverified id
     * @param string $claimantAccountId account that takes it
     * @param string $slackUserId member id
     * @param string $reason `expired` or `claimed_by_email`
     */
    public function __construct(
        public readonly string $holderAccountId,
        public readonly string $claimantAccountId,
        public readonly string $slackUserId,
        public readonly string $reason,
    ) {
    }
}
