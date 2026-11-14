<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Install;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Security\SlackSecret;

use function preg_match;

/**
 * The `oauth.v2.access` exchange of an "Add to Slack" callback: the app's client credentials, the one-time code and
 * the redirect URI the authorisation used.
 */
final readonly class SlackOAuthExchange
{
    /** Client id format (`<digits>.<digits>`). */
    public const CLIENT_ID_PATTERN = '/^[0-9]{1,20}\.[0-9]{1,20}$/';

    /** One-time code format. */
    public const CODE_PATTERN = '/^[A-Za-z0-9._-]{1,256}$/';

    /**
     * @param string $clientId app client id
     * @param SlackSecret $clientSecret app client secret ({@see SlackSecret::clientSecret()})
     * @param string $code one-time code from the callback
     * @param string $redirectUri redirect URI sent with the authorisation request
     *
     * @throws InvalidArgumentException when a value is malformed
     */
    public function __construct(
        public string $clientId,
        public SlackSecret $clientSecret,
        public string $code,
        public string $redirectUri,
    ) {
        if (preg_match(self::CLIENT_ID_PATTERN, $clientId) !== 1) {
            throw new InvalidArgumentException('Invalid Slack client id.');
        }
        if ($clientSecret->kind !== SlackSecret::CLIENT_SECRET) {
            throw new InvalidArgumentException('A Slack OAuth exchange needs a client secret.');
        }
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException('Invalid Slack OAuth code.');
        }
    }
}
