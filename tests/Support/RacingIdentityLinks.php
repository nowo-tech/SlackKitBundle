<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Closure;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Linking\SlackIdentityLinksInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\Linking\SlackVerifiedEmailCheck;
use Nowo\SlackKitBundle\Linking\SlackVerifiedEmailCheckerInterface;

/**
 * Audit R2-M1 (ported from PzRound2PocTest): delegates to {@see FakeIdentityLinks} and, on the 2nd
 * `findLinkBySlackUser()` read (the last read before the write), runs another request once: a TOCTOU interleaving.
 * Optionally returns a fixed account label.
 */
final class RacingIdentityLinks implements SlackIdentityLinksInterface, SlackVerifiedEmailCheckerInterface
{
    private int $reads = 0;

    public function __construct(private readonly FakeIdentityLinks $inner, private ?Closure $other = null, private readonly ?string $label = null)
    {
    }

    public function findLinkBySlackUser(SlackTeamId $team, SlackUserId $user): ?SlackLink
    {
        $found = $this->inner->findLinkBySlackUser($team, $user);
        if (++$this->reads === 2 && $this->other instanceof Closure) {
            $other       = $this->other;
            $this->other = null;
            $other();
        }

        return $found;
    }

    public function linksOf(string $accountId): array
    {
        return $this->inner->linksOf($accountId);
    }

    public function removeLinkIn(string $accountId, SlackTeamId $team): void
    {
        $this->inner->removeLinkIn($accountId, $team);
    }

    public function accountLabel(string $accountId): string
    {
        return $this->label ?? $this->inner->accountLabel($accountId);
    }

    public function findLinkByAccount(string $accountId): ?SlackLink
    {
        return $this->inner->findLinkByAccount($accountId);
    }

    public function isAccountActive(string $accountId): bool
    {
        return $this->inner->isAccountActive($accountId);
    }

    public function accountEmail(string $accountId): ?string
    {
        return $this->inner->accountEmail($accountId);
    }

    public function accountLocale(string $accountId): string
    {
        return $this->inner->accountLocale($accountId);
    }

    public function saveLink(SlackLink $link): void
    {
        $this->inner->saveLink($link);
    }

    public function removeLink(string $accountId): void
    {
        $this->inner->removeLink($accountId);
    }

    public function isVerifiedEmailOf(SlackVerifiedEmailCheck $check): bool
    {
        return $this->inner->isVerifiedEmailOf($check);
    }
}
