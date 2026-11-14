<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;

/**
 * A DDD host's installation store (stands for the host's own table, keyed by app and workspace): it keeps its rows
 * across requests of one kernel, like a database, and is never reset by the bundle.
 */
final class HostInstallationStore implements SlackInstallationStoreInterface
{
    /** @var array<string, SlackInstallation> */
    public array $rows = [];

    public function find(SlackInstallationKey $key): ?SlackInstallation
    {
        if (!$key->team instanceof SlackTeamId) {
            return null;
        }

        return $this->rows[$key->workspaceKey()] ?? null;
    }

    public function findSigningSecret(string $app): ?SlackSecret
    {
        return $app === SlackInstallationKey::DEFAULT_APP ? SlackSecret::signingSecret(Fake::SIGNING_SECRET) : null;
    }

    public function save(SlackInstallation $installation): void
    {
        $this->rows[$installation->storageKey()->workspaceKey()] = $installation;
    }

    public function markRevoked(SlackInstallationKey $key, DateTimeImmutable $at): void
    {
        $row = $this->find($key);
        if ($row instanceof SlackInstallation && !$row->isRevoked()) {
            $this->save($row->revoked($at, 'revoked'));
        }
    }
}
