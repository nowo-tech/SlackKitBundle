<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Linking\SlackAccountResolverInterface;
use Nowo\SlackKitBundle\Linking\SlackIdentityLinksInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\Linking\SlackVerifiedEmailCheck;
use Nowo\SlackKitBundle\Linking\SlackVerifiedEmailCheckerInterface;

use function array_values;

/**
 * A host with several Slack identities per account (stands for its own table), its verified e-mails, and the
 * current-account resolver. Rows survive across requests of one kernel, like a database.
 */
final class FakeIdentityLinks implements SlackIdentityLinksInterface, SlackAccountResolverInterface, SlackVerifiedEmailCheckerInterface
{
    /** Workspace of the default installation (the 1.2 single-link API acts on it). */
    public const DEFAULT_TEAM = Fake::TEAM;

    /** @var array<string, SlackLink> keyed by account|team */
    public array $links = [];

    /** @var array<string, bool> */
    public array $active = [];

    /** @var array<string, list<string>> verified e-mails per account */
    public array $verifiedEmails = [];

    /** @var list<SlackVerifiedEmailCheck> */
    public array $checks = [];

    public ?string $current = null;

    public function linksOf(string $accountId): array
    {
        $out = [];
        foreach ($this->links as $link) {
            if ($link->accountId === $accountId) {
                $out[] = $link;
            }
        }

        return $out;
    }

    public function removeLinkIn(string $accountId, SlackTeamId $team): void
    {
        unset($this->links[$accountId . '|' . $team->value]);
    }

    public function accountLabel(string $accountId): string
    {
        return 'Account ' . $accountId;
    }

    public function findLinkByAccount(string $accountId): ?SlackLink
    {
        return $this->links[$accountId . '|' . self::DEFAULT_TEAM] ?? null;
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
        return $this->verifiedEmails[$accountId][0] ?? null;
    }

    public function accountLocale(string $accountId): string
    {
        return 'en';
    }

    public function saveLink(SlackLink $link): void
    {
        foreach ($this->links as $key => $existing) {
            if ($existing->team->equals($link->team) && $existing->slackUserId->equals($link->slackUserId) && $existing->accountId !== $link->accountId) {
                unset($this->links[$key]);
            }
        }
        $this->links[$link->accountId . '|' . $link->team->value] = $link;
    }

    public function removeLink(string $accountId): void
    {
        unset($this->links[$accountId . '|' . self::DEFAULT_TEAM]);
    }

    public function currentAccountId(): ?string
    {
        return $this->current;
    }

    public function isVerifiedEmailOf(SlackVerifiedEmailCheck $check): bool
    {
        $this->checks[] = $check;
        foreach ($this->verifiedEmails[$check->accountId] ?? [] as $email) {
            if ($email === $check->email) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<SlackLink>
     */
    public function all(): array
    {
        return array_values($this->links);
    }
}
