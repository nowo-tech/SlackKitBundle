<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Api\SlackUserDirectoryInterface;
use Nowo\SlackKitBundle\Api\SlackUserProfile;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\Controller\ClickLinkController;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Event\SlackAccountUnlinkedEvent;
use Nowo\SlackKitBundle\Event\SlackAccountVerifiedEvent;
use Nowo\SlackKitBundle\Event\SlackIdentityLinkedEvent;
use Nowo\SlackKitBundle\Event\SlackLinkReleasedEvent;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\SlackInteraction;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function hash;
use function intdiv;
use function max;

/**
 * Link-from-click (spec 237 T000b, FR-010 – FR-013). An unlinked clicker gets an ephemeral answer with a signed,
 * single-use link (10 minutes; workspace, member and installation). Opening it needs the host session; confirming it
 * links the identity **only when the member's e-mail, read through that installation, is a verified e-mail of the
 * signed-in account** (unless the installation opts in to `allowUnverifiedLinkConfirm`), never takes an identity that
 * another account verified, keeps one identity per workspace and account, and then sends the member a direct message
 * "Linked to <account> — not you? Unlink". Links are offered only for clicks signed by Slack through a configured app:
 * never for resolver-verified or own-app clicks, nor legacy values. No e-mail, token or secret is logged.
 */
final readonly class ClickLinker implements ClickLinkerInterface
{
    /** `action_id` of the link button (a URL button; Slack sends a callback the router answers with 200). */
    public const OPEN_ACTION = 'nowo_slack_kit.link.open';

    /**
     * @param SlackIdentityLinksInterface $identities host identities (several per account)
     * @param SlackVerifiedEmailCheckerInterface $emails host verified e-mail check
     * @param SlackInstallationStoreInterface $installations installation store
     * @param SlackApiClientInterface $api Web API client (confirmation DM)
     * @param SlackUserDirectoryInterface $directory `users.info`
     * @param ClickLinkSigner $signer token signer
     * @param LockFactory $locks single-use claims (`ingress.replay_lock_factory`)
     * @param RateLimiterFactoryInterface $limiter per-account limiter of previews and confirmations
     * @param UrlGeneratorInterface $urls router
     * @param ClockInterface $clock clock
     * @param string|null $manageRoute route of the host page that lists and unlinks identities (DM link)
     * @param bool $allowHttp allow `http://` link URLs (development and tests only)
     * @param EventDispatcherInterface|null $events event dispatcher
     * @param TranslatorInterface|null $translator translator of the Slack-side texts
     * @param string $defaultLocale locale of the Slack-side texts
     * @param LoggerInterface $logger PSR-3 logger (ids and codes only)
     * @param int $ttl lifetime of the link in seconds (`linking.from_click.ttl`), named in the offer text
     */
    public function __construct(
        private SlackIdentityLinksInterface $identities,
        private SlackVerifiedEmailCheckerInterface $emails,
        private SlackInstallationStoreInterface $installations,
        private SlackApiClientInterface $api,
        private SlackUserDirectoryInterface $directory,
        private ClickLinkSigner $signer,
        private LockFactory $locks,
        private RateLimiterFactoryInterface $limiter,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
        private ?string $manageRoute = null,
        private bool $allowHttp = false,
        private ?EventDispatcherInterface $events = null,
        private ?TranslatorInterface $translator = null,
        private string $defaultLocale = 'en',
        private LoggerInterface $logger = new NullLogger(),
        private int $ttl = ClickLinkSigner::MAX_TTL,
    ) {
    }

    /**
     * The "Link your account" ephemeral answer, or null.
     */
    public function offer(SlackInteraction $interaction): ?SlackMessage
    {
        $team = $interaction->team;
        $user = $interaction->user;
        if ($interaction->verifiedByResolver || $interaction->verifiedByOwnApp || $interaction->legacy || !$team instanceof SlackTeamId || !$user instanceof SlackUserId
            || !$team->equals($interaction->installation->team)) {
            return null;
        }
        $holder = $this->identities->findLinkBySlackUser($team, $user);
        if ($holder instanceof SlackLink && $holder->isVerified()) {
            return null;
        }
        $url = $this->urls->generate(ClickLinkController::PAGE, ['token' => $this->signer->issue($interaction->installation, $team, $user)], UrlGeneratorInterface::ABSOLUTE_URL);
        // Beacon Q7: the configured lifetime, in minutes when it is a whole number of minutes, else in seconds.
        $text = $this->ttl % 60 === 0
            ? $this->trans('slack.link_offer.text_minutes', ['%count%' => (string) intdiv($this->ttl, 60)])
            : $this->trans('slack.link_offer.text_seconds', ['%count%' => (string) $this->ttl]);

        return new SlackMessage($text, [Blocks::section($text), Blocks::actions([Blocks::linkButton($this->trans('slack.link_offer.button', []), $url, self::OPEN_ACTION, $this->allowHttp)])]);
    }

    /**
     * What the confirmation page shows, or why it cannot.
     */
    public function preview(string $accountId, string $token): ClickLinkPreview|LinkResult
    {
        $verified = $this->signer->verify($token);
        if (!$verified instanceof ClickLinkToken) {
            return LinkResult::LinkExpired;
        }
        $checked = $this->check($accountId, $verified);
        if ($checked instanceof LinkResult) {
            return $checked;
        }
        [$installation, $profile] = $checked;

        return new ClickLinkPreview($verified->team->value, $installation->teamName ?? $verified->team->value, $verified->user->value, $profile->displayName);
    }

    /**
     * Consumes the token and links the identity.
     */
    public function confirm(string $accountId, string $token): LinkResult
    {
        $verified = $this->signer->verify($token);
        if (!$verified instanceof ClickLinkToken || !$this->claim($verified)) {
            return LinkResult::LinkExpired;
        }
        $checked = $this->check($accountId, $verified);
        if ($checked instanceof LinkResult) {
            return $checked;
        }
        [$installation, $profile] = $checked;

        $matched = false;
        if (!$installation->allowUnverifiedLinkConfirm) {
            if ($profile->email === null) {
                return LinkResult::EmailUnavailable;
            }
            try {
                $check = new SlackVerifiedEmailCheck($accountId, $profile->email);
            } catch (InvalidArgumentException) {
                return LinkResult::EmailUnavailable;
            }
            if (!$this->emails->isVerifiedEmailOf($check)) {
                $this->logger->info('Slack link from a click refused.', ['team' => $verified->team->value, 'reason' => 'email_mismatch']);

                return LinkResult::EmailMismatch;
            }
            $matched = true;
        }

        $holder = $this->identities->findLinkBySlackUser($verified->team, $verified->user);
        if ($holder instanceof SlackLink && $holder->accountId !== $accountId) {
            // Only an unverified claim yields, and only to a proven (e-mail matched) link.
            if (!$matched) {
                return LinkResult::InUse;
            }
            // @igor-ignore - Host identity store write (persistent storage), not in-process state.
            $this->identities->removeLinkIn($holder->accountId, $verified->team);
            $this->events?->dispatch(new SlackLinkReleasedEvent($holder->accountId, $accountId, $verified->user->value, 'claimed_by_click'));
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $this->identities->saveLink(new SlackLink($accountId, $verified->team, $verified->user, $now, $now, SlackLink::SOURCE_CLICK));
        $this->events?->dispatch(new SlackAccountVerifiedEvent($accountId, $verified->team->value, $verified->user->value));
        $this->events?->dispatch(new SlackIdentityLinkedEvent($accountId, $verified->team->value, $verified->user->value, $verified->installation, $matched));
        $this->logger->info('Slack identity linked from a click.', ['team' => $verified->team->value, 'email_matched' => $matched]);
        $this->notify($accountId, $verified, $installation);

        return LinkResult::Linked;
    }

    /**
     * Every identity of the account.
     *
     * @return list<SlackLink>
     */
    public function linksOf(string $accountId): array
    {
        return $this->identities->linksOf($accountId);
    }

    /**
     * Unlinks the account's identity in that workspace.
     */
    public function unlinkIn(string $accountId, SlackTeamId $team): LinkResult
    {
        foreach ($this->identities->linksOf($accountId) as $link) {
            if ($link->accountId === $accountId && $link->team->equals($team)) {
                // @igor-ignore - Host identity store write (persistent storage), not in-process state.
                $this->identities->removeLinkIn($accountId, $team);
                $this->events?->dispatch(new SlackAccountUnlinkedEvent($accountId, $link->slackUserId->value, $team->value));
            }
        }

        return LinkResult::Unlinked;
    }

    /**
     * The checks shared by the page and the confirmation, then the member's profile.
     *
     * @return array{0: SlackInstallation, 1: SlackUserProfile}|LinkResult
     */
    private function check(string $accountId, ClickLinkToken $token): array|LinkResult
    {
        if (!$this->identities->isAccountActive($accountId)) {
            return LinkResult::Unavailable;
        }
        $installation = $this->installations->find($token->installation);
        if (!$installation instanceof SlackInstallation || $installation->isRevoked() || !$token->installation->admits($installation)) {
            return LinkResult::Unavailable;
        }
        if (!$this->limiter->create('account:' . hash('sha256', $accountId))->consume()->isAccepted()) {
            return LinkResult::RateLimited;
        }
        $holder = $this->identities->findLinkBySlackUser($token->team, $token->user);
        if ($holder instanceof SlackLink && $holder->isVerified()) {
            return $holder->accountId === $accountId ? LinkResult::AlreadyVerified : LinkResult::InUse;
        }
        foreach ($this->identities->linksOf($accountId) as $link) {
            if ($link->team->equals($token->team) && !$link->slackUserId->equals($token->user)) {
                return LinkResult::AlreadyLinkedInWorkspace;
            }
        }
        try {
            $profile = $this->directory->userProfile($token->installation, $token->user);
        } catch (SlackApiException $e) {
            $this->logger->info('Slack profile lookup failed.', ['team' => $token->team->value, 'error' => $e->errorCode]);

            return LinkResult::Unavailable;
        }

        return $profile->bot || $profile->deleted ? LinkResult::Invalid : [$installation, $profile];
    }

    /**
     * Single use: a lock that is never released, until the token expires.
     */
    private function claim(ClickLinkToken $token): bool
    {
        $ttl = max(1, $token->expiresAt - $this->clock->now()->getTimestamp());

        return $this->locks->createLock('nowo_slack_kit.link_click.' . hash('sha256', $token->nonce), $ttl, false)->acquire(false);
    }

    /**
     * "Linked to <account> — not you? Unlink" in the member's direct messages; best effort.
     */
    private function notify(string $accountId, ClickLinkToken $token, SlackInstallation $installation): void
    {
        $text   = $this->trans('slack.linked_dm.text', ['%account%' => $this->identities->accountLabel($accountId), '%team%' => $installation->teamName ?? $token->team->value]);
        $blocks = [Blocks::section($text)];
        if ($this->manageRoute !== null) {
            $blocks[] = Blocks::actions([Blocks::linkButton($this->trans('slack.linked_dm.unlink', []), $this->urls->generate($this->manageRoute, [], UrlGeneratorInterface::ABSOLUTE_URL), 'nowo_slack_kit.link.manage', $this->allowHttp)]);
        }
        try {
            $channel = $this->api->openDirectMessage($token->installation, $token->user);
            $this->api->postMessage($token->installation, $channel, new SlackMessage($text, $blocks));
        } catch (SlackApiException $e) {
            $this->logger->info('Slack link notice failed.', ['team' => $token->team->value, 'error' => $e->errorCode]);
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params): string
    {
        return $this->translator?->trans($key, $params, NowoSlackKitBundle::TRANSLATION_DOMAIN, $this->defaultLocale) ?? $key;
    }
}
