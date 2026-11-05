<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Notifier;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Symfony\Component\Notifier\Exception\IncompleteDsnException;
use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;
use Symfony\Component\Notifier\Transport\TransportInterface;

use function is_string;

/**
 * Creates the `nowo-slack://default?app=<app>&team=<T…>` chat transport. A DSN with a user or password is refused:
 * secrets never live in a DSN.
 */
final readonly class SlackKitTransportFactory implements TransportFactoryInterface
{
    /** DSN scheme. */
    public const SCHEME = 'nowo-slack';

    /**
     * @param SlackApiClientInterface $api Web API client
     * @param string $defaultApp `default_app`
     */
    public function __construct(
        private SlackApiClientInterface $api,
        private string $defaultApp = SlackInstallationKey::DEFAULT_APP,
    ) {
    }

    /**
     * Builds the transport.
     *
     * @throws UnsupportedSchemeException for another scheme
     * @throws IncompleteDsnException for a DSN with credentials, another host or a bad app / team
     */
    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn, self::SCHEME, [self::SCHEME]);
        }
        if ($dsn->getUser() !== null || $dsn->getPassword() !== null || $dsn->getHost() !== 'default') {
            throw new IncompleteDsnException('Use nowo-slack://default?app=<app>: credentials come from the installation store, never from the DSN.');
        }
        $team = $dsn->getOption('team');
        try {
            $key = new SlackInstallationKey((string) ($dsn->getOption('app') ?? $this->defaultApp), is_string($team) ? SlackTeamId::fromString($team) : null);
        } catch (InvalidArgumentException) {
            throw new IncompleteDsnException('Invalid app or team in the nowo-slack DSN.');
        }

        return new SlackKitTransport($this->api, $key);
    }

    /**
     * The `nowo-slack` scheme.
     */
    public function supports(Dsn $dsn): bool
    {
        return $dsn->getScheme() === self::SCHEME;
    }
}
