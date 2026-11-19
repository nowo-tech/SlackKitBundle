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
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function hash;
use function intdiv;
use function max;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function sort;
use function str_contains;
use function trim;

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

    /** Default lifetime of the confirmation locks (released in `finally`); the bundle wires `api.max_duration` + 10 s. */
    public const DEFAULT_LOCK_TTL = 30.0;

    /** Longest account label shown in the DM. */
    public const MAX_LABEL = 80;

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
     * @param float $lockTtl lifetime of the confirmation locks: longer than one Slack call (`api.max_duration` + 10 s)
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
        private float $lockTtl = self::DEFAULT_LOCK_TTL,
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
     * What the confirmation page shows, or why it cannot. It neither consumes the token nor counts against the
     * per-account limit (audit R2-I1): only confirmations do.
     */
    public function preview(string $accountId, string $token): ClickLinkPreview|LinkResult
    {
        $verified = $this->signer->verify($token);
        if (!$verified instanceof ClickLinkToken) {
            return LinkResult::LinkExpired;
        }
        $checked = $this->check($accountId, $verified, false);
        if ($checked instanceof LinkResult) {
            return $checked;
        }
        [$installation, $profile] = $checked;

        return new ClickLinkPreview($verified->team->value, $installation->teamName ?? $verified->team->value, $verified->user->value, $profile->displayName);
    }

    /**
     * Links the identity. Every read and the write run under two locks, (workspace, member) then (account, workspace),
     * so two confirmations never both pass the checks (audit R2-M1); the token is claimed inside them, after every check
     * passed, so a refused confirmation does not burn it (R2-L2).
     */
    public function confirm(string $accountId, string $token): LinkResult
    {
        $verified = $this->signer->verify($token);
        if (!$verified instanceof ClickLinkToken) {
            return LinkResult::LinkExpired;
        }
        $keys = [self::pairLockKey($verified->installation->app, $verified->team, $verified->user), 'nowo_slack_kit.link_account.' . hash('sha256', $verified->installation->app . '|' . $accountId . '|' . $verified->team->value)];
        sort($keys);
        $held = [];
        try {
            foreach ($keys as $key) {
                $lock = $this->locks->createLock($key, $this->lockTtl, false); // Released below, never by a destructor.
                if (!$lock->acquire(false)) {
                    return LinkResult::Busy; // Another confirmation for this member or account is running.
                }
                $held[] = $lock;
            }
            $result = $this->link($accountId, $verified);
        } catch (LockAcquiringException $e) {
            // REQ-RUNTIME-003: the lock store is down; nothing was written (the single-use claim comes before the write).
            $this->logger->error('Slack link from a click refused.', ['team' => $verified->team->value, 'reason' => 'lock_store', 'exception' => ($e->getPrevious() ?? $e)::class]);

            return LinkResult::Unavailable;
        } finally {
            foreach ($held as $lock) {
                self::releaseQuietly($lock, $this->logger);
            }
        }
        if ($result instanceof SlackInstallation) {
            // The DM goes out after the locks are released (it is a Slack call, best effort).
            $this->notify($accountId, $verified, $result);

            return LinkResult::Linked;
        }

        return $result;
    }

    /**
     * Releases a link lock; a failed release is logged and the lock expires after its TTL (the answer is unchanged).
     *
     * @internal shared with {@see AccountLinker}
     */
    public static function releaseQuietly(LockInterface $lock, LoggerInterface $logger): void
    {
        try {
            $lock->release();
        } catch (LockReleasingException $e) {
            $logger->warning('Slack link lock could not be released; it expires after its TTL.', ['exception' => ($e->getPrevious() ?? $e)::class]);
        }
    }

    /**
     * The lock key of a (workspace, member) pair, shared by every flow that writes it (1.2 claims included).
     */
    public static function pairLockKey(string $app, SlackTeamId $team, SlackUserId $user): string
    {
        return 'nowo_slack_kit.link_pair.' . hash('sha256', $app . '|' . $team->value . '|' . $user->value);
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
     * The confirmation, under the locks: checks (re-read), e-mail match, single-use claim, then the write. Returns the
     * installation when linked (the caller sends the DM), else the refusal.
     */
    private function link(string $accountId, ClickLinkToken $verified): SlackInstallation|LinkResult
    {
        $checked = $this->check($accountId, $verified, true);
        if ($checked instanceof LinkResult) {
            return $checked;
        }
        [$installation, $profile] = $checked;

        $matched = false;
        if (!$installation->allowUnverifiedLinkConfirm) {
            $refusal = $this->emailRefusal($accountId, $verified, $profile);
            if ($refusal instanceof LinkResult) {
                return $refusal;
            }
            $matched = true;
        }

        $holder = $this->identities->findLinkBySlackUser($verified->team, $verified->user);
        if ($holder instanceof SlackLink && $holder->accountId !== $accountId && !$matched) {
            // Only an unverified claim yields, and only to a proven (e-mail matched) link.
            return LinkResult::InUse;
        }
        if (!$this->claim($verified)) {
            return LinkResult::LinkExpired;
        }
        if ($holder instanceof SlackLink && $holder->accountId !== $accountId) {
            // @igor-ignore - Host identity store write (persistent storage), not in-process state.
            $this->identities->removeLinkIn($holder->accountId, $verified->team);
            $this->events?->dispatch(new SlackLinkReleasedEvent($holder->accountId, $accountId, $verified->user->value, 'claimed_by_click'));
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            $this->identities->saveLink(new SlackLink($accountId, $verified->team, $verified->user, $now, $now, SlackLink::SOURCE_CLICK));
        } catch (SlackIdentityConflictException) {
            return LinkResult::InUse; // The store refuses a pair another account holds (contract).
        }
        $this->events?->dispatch(new SlackAccountVerifiedEvent($accountId, $verified->team->value, $verified->user->value));
        $this->events?->dispatch(new SlackIdentityLinkedEvent($accountId, $verified->team->value, $verified->user->value, $verified->installation, $matched));
        $this->logger->info('Slack identity linked from a click.', ['team' => $verified->team->value, 'email_matched' => $matched]);

        return $installation;
    }

    /**
     * Why the member's Slack e-mail does not prove the account, or null when it does.
     */
    private function emailRefusal(string $accountId, ClickLinkToken $verified, SlackUserProfile $profile): ?LinkResult
    {
        if ($profile->emailConfirmed === false) {
            return LinkResult::EmailUnconfirmed; // Slack says the member never confirmed that address (R2-L4).
        }
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

        return null;
    }

    /**
     * The checks shared by the page and the confirmation, then the member's profile.
     *
     * @return array{0: SlackInstallation, 1: SlackUserProfile}|LinkResult
     */
    private function check(string $accountId, ClickLinkToken $token, bool $countAttempt): array|LinkResult
    {
        if (!$this->identities->isAccountActive($accountId)) {
            return LinkResult::Unavailable;
        }
        $installation = $this->installations->find($token->installation);
        if (!$installation instanceof SlackInstallation || $installation->isRevoked() || !$token->installation->admits($installation)) {
            return LinkResult::Unavailable;
        }
        if ($countAttempt && !$this->limiter->create('account:' . hash('sha256', $accountId))->consume()->isAccepted()) {
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
        $text = $this->trans('slack.linked_dm.text', ['%account%' => $this->label($accountId), '%team%' => $installation->teamName ?? $token->team->value]);
        // Plain text (R2-L3): no mrkdwn, mentions or links from the account label or the workspace name.
        $blocks = [['type' => 'section', 'text' => ['type' => 'plain_text', 'text' => Blocks::truncate($text, Blocks::MAX_SECTION_TEXT), 'emoji' => false]]];
        if ($this->manageRoute !== null) {
            $blocks[] = Blocks::actions([Blocks::linkButton($this->trans('slack.linked_dm.unlink', []), $this->urls->generate($this->manageRoute, [], UrlGeneratorInterface::ABSOLUTE_URL), 'nowo_slack_kit.link.manage', $this->allowHttp)]);
        }
        try {
            $channel = $this->api->openDirectMessage($token->installation, $token->user);
            $this->api->postMessage($token->installation, $channel, new SlackMessage($this->trans('slack.confirm.linked', []), $blocks));
        } catch (SlackApiException $e) {
            $this->logger->info('Slack link notice failed.', ['team' => $token->team->value, 'error' => $e->errorCode]);
        }
    }

    /**
     * The host's account label, or a neutral one when it looks like an e-mail, a link or holds control characters
     * (audit R2-L3, Beacon Q9: a display name, never an e-mail); at most {@see self::MAX_LABEL} characters.
     */
    private function label(string $accountId): string
    {
        $label = trim($this->identities->accountLabel($accountId));
        if ($label === '' || str_contains($label, '@') || str_contains($label, '://') || preg_match('/^[^\p{C}]+$/u', $label) !== 1) {
            return $this->trans('slack.linked_dm.your_account', []);
        }

        return mb_strlen($label) > self::MAX_LABEL ? mb_substr($label, 0, self::MAX_LABEL - 1) . '…' : $label;
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params): string
    {
        return $this->translator?->trans($key, $params, NowoSlackKitBundle::TRANSLATION_DOMAIN, $this->defaultLocale) ?? $key;
    }
}
