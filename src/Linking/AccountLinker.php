<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use DateTimeImmutable;
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Event\SlackAccountClaimedEvent;
use Nowo\SlackKitBundle\Event\SlackAccountUnlinkedEvent;
use Nowo\SlackKitBundle\Event\SlackAccountVerifiedEvent;
use Nowo\SlackKitBundle\Event\SlackConfirmationRateLimitedEvent;
use Nowo\SlackKitBundle\Event\SlackLinkReleasedEvent;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodecInterface;
use Nowo\SlackKitBundle\Interaction\LegacyValuePolicy;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\Interaction\SlackInteractionReply;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The account-linking state machine (contract §8): claim (typed or proven by e-mail), confirmation DM with a signed
 * button, confirm, unlink. A verified link is never taken; an unverified one yields to a proven claim or after
 * `claim_ttl`. Every message-sending step is rate limited per account and per target (OTHER REQ-THROTTLE-005).
 */
final readonly class AccountLinker implements AccountLinkerInterface
{
    /** `action_id` of the confirmation button. */
    public const CONFIRM_ACTION = 'nowo_slack_kit.link.confirm';

    /**
     * @param SlackIdentityStoreInterface $identities host accounts
     * @param SlackInstallationStoreInterface $installations installation store
     * @param SlackApiClientInterface $api Web API client
     * @param ButtonValueCodecInterface $codec `nsk1` codec
     * @param ClockInterface $clock clock
     * @param array{claim_per_account: RateLimiterFactoryInterface, email_lookup_per_account: RateLimiterFactoryInterface, confirmation_per_account: RateLimiterFactoryInterface, confirmation_per_target: RateLimiterFactoryInterface} $limiters rate limiters
     * @param int $claimTtlSeconds age after which an unverified claim yields
     * @param int $confirmationTtlSeconds lifetime of a confirmation button
     * @param bool $emailLookup `linking.email_lookup`
     * @param SlackInstallationKey $installation installation used for linking
     * @param EventDispatcherInterface|null $events event dispatcher
     * @param TranslatorInterface|null $translator translator of the Slack-side texts
     * @param string $defaultLocale locale when the account has none
     * @param LoggerInterface $logger PSR-3 logger (ids and codes only)
     * @param LegacyValuePolicy|null $legacy while its window is open, signed confirmations without `c` (issued before
     *                                       adoption) are still accepted; afterwards `c` is required
     */
    public function __construct(
        private SlackIdentityStoreInterface $identities,
        private SlackInstallationStoreInterface $installations,
        private SlackApiClientInterface $api,
        private ButtonValueCodecInterface $codec,
        private ClockInterface $clock,
        private array $limiters,
        private int $claimTtlSeconds = 86_400,
        private int $confirmationTtlSeconds = 86_400,
        private bool $emailLookup = false,
        private SlackInstallationKey $installation = new SlackInstallationKey(),
        private ?EventDispatcherInterface $events = null,
        private ?TranslatorInterface $translator = null,
        private string $defaultLocale = 'en',
        private LoggerInterface $logger = new NullLogger(),
        private ?LegacyValuePolicy $legacy = null,
    ) {
    }

    /**
     * The account's current link, or null.
     */
    public function linkOf(string $accountId): ?SlackLink
    {
        return $this->identities->findLinkByAccount($accountId);
    }

    /**
     * Whether "Find me by e-mail" is offered (configuration and installation both allow it).
     */
    public function canFindByEmail(): bool
    {
        return $this->emailLookup && ($this->workspace()->allowEmailLookup ?? false);
    }

    /**
     * Claims a typed Slack member id for the account.
     */
    public function claim(string $accountId, string $slackUserId): LinkResult
    {
        if (!$this->accept('claim_per_account', 'acc:' . $accountId, $accountId)) {
            return LinkResult::RateLimited;
        }
        $user = SlackUserId::tryFrom($slackUserId);
        if (!$user instanceof SlackUserId) {
            return LinkResult::Invalid;
        }
        $team = $this->workspace()?->teamId;

        return $team instanceof SlackTeamId ? $this->take($accountId, $team, $user, false) : LinkResult::Unavailable;
    }

    /**
     * Finds the account's member id with `users.lookupByEmail` (a proven claim).
     */
    public function findByEmail(string $accountId): LinkResult
    {
        $installation = $this->workspace();
        $email        = $this->identities->accountEmail($accountId);
        if (!$this->emailLookup || !$installation instanceof SlackInstallation || !$installation->allowEmailLookup || !$installation->teamId instanceof SlackTeamId || $email === null || $email === '') {
            return LinkResult::Unavailable;
        }
        if (!$this->accept('email_lookup_per_account', 'acc:' . $accountId, $accountId)) {
            return LinkResult::RateLimited;
        }
        try {
            $user = $this->api->lookupUserByEmail($this->installation, $email);
        } catch (SlackApiException $e) {
            $this->logger->warning('Slack e-mail lookup failed.', ['account_id' => $accountId, 'error' => $e->errorCode]);

            return LinkResult::Unavailable;
        }

        return $user instanceof SlackUserId ? $this->take($accountId, $installation->teamId, $user, true) : LinkResult::NotFound;
    }

    /**
     * Sends the confirmation DM with a signed *Confirm* button to the claimed member id.
     */
    public function sendConfirmation(string $accountId): LinkResult
    {
        $link = $this->identities->findLinkByAccount($accountId);
        if (!$link instanceof SlackLink || !$this->identities->isAccountActive($accountId) || !$this->workspace() instanceof SlackInstallation) {
            return LinkResult::Unavailable;
        }
        if ($link->isVerified()) {
            return LinkResult::AlreadyVerified;
        }
        if (!$this->accept('confirmation_per_account', 'acc:' . $accountId, $accountId)
            || !$this->accept('confirmation_per_target', 'slack:' . $link->team->value . ':' . $link->slackUserId->value, $accountId)) {
            return LinkResult::RateLimited;
        }

        $locale  = $this->identities->accountLocale($accountId);
        $value   = $this->codec->sign(self::CONFIRM_ACTION, ['acc' => $accountId, 'c' => $link->claimedAt->getTimestamp(), 'uid' => $link->slackUserId->value], $link->team, $this->confirmationTtlSeconds);
        $text    = $this->trans('slack.confirm.text', $locale);
        $message = new SlackMessage($text, [
            Blocks::section($text),
            Blocks::actions([Blocks::button($this->trans('slack.confirm.button', $locale), self::CONFIRM_ACTION, $value, 'primary')]),
        ]);
        try {
            $channel = $this->api->openDirectMessage($this->installation, $link->slackUserId);
            $this->api->postMessage($this->installation, $channel, $message);
        } catch (SlackApiException $e) {
            $this->logger->warning('Slack link confirmation failed.', ['account_id' => $accountId, 'error' => $e->errorCode]);

            return LinkResult::ConfirmationFailed;
        }

        return LinkResult::ConfirmationSent;
    }

    /**
     * The signed *Confirm* click: verified only when the account in the value, the member id in the value, the
     * clicker, the account's current link (id, workspace and claim time `c`) all match and the account is active.
     * `c` binds the button to one claim: a new claim (or an unlink and re-claim) makes every older button useless,
     * and confirming twice is a no-op, so no nonce store is needed. A signed value without `c` is accepted only while
     * the legacy window is open (buttons issued before adoption). Unsigned legacy values are always refused (the
     * container refuses mapping a legacy action to this one, audit FS-I4).
     */
    public function confirm(SlackInteraction $interaction): SlackInteractionReply
    {
        // A click verified with a host-resolved secret carries signer-chosen team and user ids (audit FS-M1).
        $accountId = $interaction->legacy || $interaction->verifiedByResolver ? null : $interaction->string('acc');
        $valueUser = $interaction->string('uid');
        $claimedAt = $interaction->int('c');
        $link      = $accountId !== null ? $this->identities->findLinkByAccount($accountId) : null;

        $matches = $link instanceof SlackLink && $accountId !== null && $interaction->user instanceof SlackUserId
            && $valueUser === $link->slackUserId->value && $interaction->user->equals($link->slackUserId)
            && $link->team->equals($interaction->team)
            && ($claimedAt === null ? ($this->legacy?->isWindowOpen() ?? false) : $claimedAt === $link->claimedAt->getTimestamp())
            && $this->identities->isAccountActive($accountId);
        if (!$matches || !$link instanceof SlackLink) {
            return SlackInteractionReply::forbidden('Confirmation refused')->withEphemeral(SlackMessage::plain($this->trans('slack.confirm.refused', null)));
        }

        if (!$link->isVerified()) {
            $this->identities->saveLink($link->verified($this->now()));
            $this->events?->dispatch(new SlackAccountVerifiedEvent($link->accountId, $link->team->value, $link->slackUserId->value));
        }

        return SlackInteractionReply::done('Linked')->replaceOriginal(SlackMessage::plain($this->trans('slack.confirm.linked', $this->identities->accountLocale($link->accountId))));
    }

    /**
     * Removes the account's link.
     */
    public function unlink(string $accountId): LinkResult
    {
        $link = $this->identities->findLinkByAccount($accountId);
        // @igor-ignore - Host identity store write (persistent storage), not in-process state.
        $this->identities->removeLink($accountId);
        $this->events?->dispatch(new SlackAccountUnlinkedEvent($accountId, $link?->slackUserId->value));

        return LinkResult::Unlinked;
    }

    private function take(string $accountId, SlackTeamId $team, SlackUserId $user, bool $proven): LinkResult
    {
        $holder = $this->identities->findLinkBySlackUser($team, $user);
        if ($holder instanceof SlackLink && $holder->accountId === $accountId) {
            if ($holder->isVerified()) {
                return LinkResult::AlreadyVerified;
            }

            return $proven ? LinkResult::Found : LinkResult::Claimed;
        }
        if ($holder instanceof SlackLink) {
            $stale = $holder->claimedAt->getTimestamp() <= $this->now()->getTimestamp() - $this->claimTtlSeconds;
            if ($holder->isVerified() || (!$proven && !$stale)) {
                return LinkResult::InUse;
            }
            // @igor-ignore - Host identity store write (persistent storage), not in-process state.
            $this->identities->removeLink($holder->accountId);
            $this->events?->dispatch(new SlackLinkReleasedEvent($holder->accountId, $accountId, $user->value, $proven ? 'claimed_by_email' : 'expired'));
        }

        $source = $proven ? SlackLink::SOURCE_EMAIL_LOOKUP : SlackLink::SOURCE_TYPED;
        $this->identities->saveLink(new SlackLink($accountId, $team, $user, $this->now(), null, $source));
        $this->events?->dispatch(new SlackAccountClaimedEvent($accountId, $team->value, $user->value, $source));

        return $proven ? LinkResult::Found : LinkResult::Claimed;
    }

    private function accept(string $limiter, string $key, string $accountId): bool
    {
        if ($this->limiters[$limiter]->create($key)->consume()->isAccepted()) {
            return true;
        }
        $this->events?->dispatch(new SlackConfirmationRateLimitedEvent($accountId, $limiter));

        return false;
    }

    private function workspace(): ?SlackInstallation
    {
        $installation = $this->installations->find($this->installation);

        return $installation instanceof SlackInstallation && !$installation->isRevoked() ? $installation : null;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function trans(string $key, ?string $locale): string
    {
        return $this->translator?->trans($key, [], NowoSlackKitBundle::TRANSLATION_DOMAIN, $locale ?? $this->defaultLocale) ?? $key;
    }
}
