<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;

/**
 * An account's Slack link (contract §8.1): which member of which workspace, when it was claimed, and when (if ever)
 * its owner confirmed it from Slack.
 */
final readonly class SlackLink
{
    /** Claimed by typing the id. */
    public const SOURCE_TYPED = 'typed';

    /** Claimed by `users.lookupByEmail` with the account's e-mail (proven). */
    public const SOURCE_EMAIL_LOOKUP = 'email_lookup';

    /**
     * @param string $accountId host account id
     * @param SlackTeamId $team workspace
     * @param SlackUserId $slackUserId member id
     * @param DateTimeImmutable $claimedAt when the id was set
     * @param DateTimeImmutable|null $verifiedAt when the owner confirmed it from Slack
     * @param string $source {@see self::SOURCE_TYPED} or {@see self::SOURCE_EMAIL_LOOKUP}
     */
    public function __construct(
        public string $accountId,
        public SlackTeamId $team,
        public SlackUserId $slackUserId,
        public DateTimeImmutable $claimedAt,
        public ?DateTimeImmutable $verifiedAt = null,
        public string $source = self::SOURCE_TYPED,
    ) {
    }

    /**
     * Whether the owner confirmed the id from Slack.
     */
    public function isVerified(): bool
    {
        return $this->verifiedAt instanceof DateTimeImmutable;
    }

    /**
     * The same link, verified at `$at`.
     */
    public function verified(DateTimeImmutable $at): self
    {
        return new self($this->accountId, $this->team, $this->slackUserId, $this->claimedAt, $at, $this->source);
    }
}
