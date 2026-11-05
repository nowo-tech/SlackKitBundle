<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Linking\SlackAccountResolverInterface;
use Nowo\SlackKitBundle\Linking\SlackIdentityStoreInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;

/**
 * In-memory host accounts for linking tests; also the current-account resolver.
 */
final class FakeIdentityStore implements SlackIdentityStoreInterface, SlackAccountResolverInterface
{
    /** @var array<string, SlackLink> */
    public array $links = [];

    /** @var array<string, bool> */
    public array $active = [];

    /** @var array<string, string> */
    public array $emails = [];

    public ?string $current = null;

    public function findLinkByAccount(string $accountId): ?SlackLink
    {
        return $this->links[$accountId] ?? null;
    }

    public function findLinkBySlackUser(SlackTeamId $team, SlackUserId $user): ?SlackLink
    {
        foreach ($this->links as $link) {
            if ($link->team->equals($team) && $link->slackUserId->equals($user)) {
                return $link;
            }
        }

        return null;
    }

    public function isAccountActive(string $accountId): bool
    {
        return $this->active[$accountId] ?? true;
    }

    public function accountEmail(string $accountId): ?string
    {
        return $this->emails[$accountId] ?? null;
    }

    public function accountLocale(string $accountId): string
    {
        return 'en';
    }

    public function saveLink(SlackLink $link): void
    {
        $this->links[$link->accountId] = $link;
    }

    public function removeLink(string $accountId): void
    {
        unset($this->links[$accountId]);
    }

    public function currentAccountId(): ?string
    {
        return $this->current;
    }
}
