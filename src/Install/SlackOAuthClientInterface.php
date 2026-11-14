<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Install;

use Nowo\SlackKitBundle\Api\SlackApiException;

/**
 * Exchanges the one-time code of an "Add to Slack" callback for the installation's grant (`oauth.v2.access`).
 */
interface SlackOAuthClientInterface
{
    /**
     * The grant of the code.
     *
     * @throws SlackApiException when Slack refuses the exchange (`invalid_code`, `bad_redirect_uri`…) or fails
     */
    public function exchange(SlackOAuthExchange $exchange): SlackOAuthGrant;
}
