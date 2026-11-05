<?php

declare(strict_types=1);

namespace App\Slack;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Linking\SlackIdentityStoreInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;

/**
 * Demo host accounts: one account (`demo-account`, demo@example.test) whose Slack link is kept in a JSON file under
 * var/. A real host maps these methods onto its user table (unique index on the Slack id, contract §8.1).
 */
final readonly class DemoIdentityStore implements SlackIdentityStoreInterface
{
    public const ACCOUNT = 'demo-account';

    public function __construct(private string $file)
    {
    }

    public function findLinkByAccount(string $accountId): ?SlackLink
    {
        return $this->links()[$accountId] ?? null;
    }

    public function findLinkBySlackUser(SlackTeamId $team, SlackUserId $user): ?SlackLink
    {
        foreach ($this->links() as $link) {
            if ($link->team->equals($team) && $link->slackUserId->equals($user)) {
                return $link;
            }
        }

        return null;
    }

    public function isAccountActive(string $accountId): bool
    {
        return $accountId === self::ACCOUNT;
    }

    public function accountEmail(string $accountId): ?string
    {
        return $accountId === self::ACCOUNT ? 'demo@example.test' : null;
    }

    public function accountLocale(string $accountId): ?string
    {
        return 'en';
    }

    public function saveLink(SlackLink $link): void
    {
        $links                   = $this->links();
        $links[$link->accountId] = $link;
        $this->write($links);
    }

    public function removeLink(string $accountId): void
    {
        $links = $this->links();
        unset($links[$accountId]);
        $this->write($links);
    }

    /**
     * @return array<string, SlackLink>
     */
    private function links(): array
    {
        $rows  = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : [];
        $links = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $links[(string) $row['account']] = new SlackLink(
                (string) $row['account'],
                SlackTeamId::fromString((string) $row['team']),
                SlackUserId::fromString((string) $row['user']),
                new DateTimeImmutable('@' . (int) $row['claimed_at']),
                isset($row['verified_at']) ? new DateTimeImmutable('@' . (int) $row['verified_at']) : null,
                (string) $row['source'],
            );
        }

        return $links;
    }

    /**
     * @param array<string, SlackLink> $links
     */
    private function write(array $links): void
    {
        $rows = array_map(static fn (SlackLink $l): array => [
            'account'     => $l->accountId,
            'team'        => $l->team->value,
            'user'        => $l->slackUserId->value,
            'claimed_at'  => $l->claimedAt->getTimestamp(),
            'verified_at' => $l->verifiedAt?->getTimestamp(),
            'source'      => $l->source,
        ], array_values($links));
        if (!is_dir(\dirname($this->file))) {
            mkdir(\dirname($this->file), 0o775, true);
        }
        file_put_contents($this->file, (string) json_encode($rows), \LOCK_EX);
    }
}
