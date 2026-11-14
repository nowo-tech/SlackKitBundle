<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Install;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Controller\FlashTrait;
use Nowo\SlackKitBundle\Credentials\SlackAppRegistry;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBinding;
use Nowo\SlackKitBundle\Credentials\SlackInstallationBindingStoreInterface;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Credentials\SlackOwnerScope;
use Nowo\SlackKitBundle\Event\SlackInstallationCompletedEvent;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Linking\SlackAccountResolverInterface;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_diff;
use function array_unique;
use function array_values;
use function http_build_query;
use function implode;
use function in_array;
use function is_string;
use function preg_match;

/**
 * "Add to Slack" (OAuth v2, spec 237 FR-003). `start` (POST, CSRF) asks the host authoriser whether the signed-in
 * account may install the app for the owner scope, records a single-use state in the session and redirects to Slack.
 * `callback` (GET, Slack's redirect) consumes the state (same session, same account, 10 minutes, once), asks the
 * authoriser again, exchanges the code, checks the grant (bot token, same Slack app, workspace install, required
 * scopes, no token rotation), saves the installation (a re-install updates it), binds it to the scope and dispatches
 * {@see SlackInstallationCompletedEvent}. Neither the code, the state nor the token is ever logged or shown.
 */
final readonly class InstallController
{
    use FlashTrait;

    /** Start route name. */
    public const START = 'nowo_slack_kit_install_start';

    /** Callback route name (the redirect URL to register in the Slack app). */
    public const CALLBACK = 'nowo_slack_kit_install_callback';

    /** CSRF token id of the start form. */
    public const CSRF_TOKEN_ID = 'nowo_slack_kit_install';

    /** Slack's authorisation page. */
    public const AUTHORIZE_URL = 'https://slack.com/oauth/v2/authorize';

    /**
     * @param SlackAppRegistry $apps configured apps (app id, client id and secret)
     * @param SlackInstallAuthorizerInterface $authorizer host decision (`installations.oauth.authorizer`)
     * @param SlackAccountResolverInterface $accounts signed-in account
     * @param InstallStateStore $states single-use state
     * @param SlackOAuthClientInterface $oauth `oauth.v2.access`
     * @param SlackInstallationStoreInterface $store installation store
     * @param SlackInstallationBindingStoreInterface $bindings binding store
     * @param CsrfOnlyFormFactory $csrfForms FormKit CSRF-only form factory
     * @param UrlGeneratorInterface $urls router
     * @param ClockInterface $clock clock
     * @param list<string> $scopes required bot scopes
     * @param list<string> $optionalScopes bot scopes requested but not required
     * @param string|null $returnRoute route the flow ends on (null: `/`)
     * @param EventDispatcherInterface|null $events dispatcher of {@see SlackInstallationCompletedEvent}
     * @param TranslatorInterface|null $translator translator of the flash messages
     * @param LoggerInterface $logger PSR-3 logger (reason codes only)
     */
    public function __construct(
        private SlackAppRegistry $apps,
        private SlackInstallAuthorizerInterface $authorizer,
        private SlackAccountResolverInterface $accounts,
        private InstallStateStore $states,
        private SlackOAuthClientInterface $oauth,
        private SlackInstallationStoreInterface $store,
        private SlackInstallationBindingStoreInterface $bindings,
        private CsrfOnlyFormFactory $csrfForms,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
        private array $scopes,
        private array $optionalScopes = [],
        private ?string $returnRoute = null,
        private ?EventDispatcherInterface $events = null,
        private ?TranslatorInterface $translator = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Starts the install and redirects to Slack.
     *
     * @throws NotFoundHttpException when the app cannot be installed
     * @throws AccessDeniedException when nobody is signed in or the authoriser refuses
     * @throws AccessDeniedHttpException when the CSRF token is invalid
     */
    #[Route(path: '/{app}/{scopeType}/{scopeId}', name: self::START, requirements: ['app' => '[a-z0-9_]{1,32}', 'scopeType' => '[a-z][a-z0-9_]{0,31}', 'scopeId' => '[A-Za-z0-9_.:\-]{1,128}'], methods: ['POST'])]
    public function start(Request $request, string $app, string $scopeType, string $scopeId): RedirectResponse
    {
        if (!$this->apps->has($app) || !$this->apps->canInstall($app)) {
            throw new NotFoundHttpException('Unknown Slack app.');
        }
        // The route requirements are the scope patterns: the scope is well-formed here.
        $scope   = new SlackOwnerScope($scopeType, $scopeId);
        $account = $this->account();
        $form    = $this->csrfForms->createNamed($this->urls->generate(self::START, ['app' => $app, 'scopeType' => $scopeType, 'scopeId' => $scopeId]), self::CSRF_TOKEN_ID);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        if (!$this->authorizer->mayInstall(new SlackInstallAttempt($app, $scope, $account))) {
            $this->logger->info('Slack install refused.', ['app' => $app, 'reason' => 'authorizer']);

            throw new AccessDeniedException('You may not install Slack for this scope.');
        }
        $state = $this->states->issue($request->getSession(), $app, $scope, $account);

        return new RedirectResponse(self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id'    => $this->apps->clientId($app),
            'scope'        => implode(',', array_values(array_unique([...$this->scopes, ...$this->optionalScopes]))),
            'redirect_uri' => $this->redirectUri(),
            'state'        => $state,
        ]), RedirectResponse::HTTP_SEE_OTHER);
    }

    /**
     * Slack's redirect: finishes the install and redirects to the return route with a flash message.
     *
     * @throws AccessDeniedException when nobody is signed in
     */
    #[Route(path: '/callback', name: self::CALLBACK, methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $account = $this->account();
        $back    = $this->returnUrl();
        $state   = $request->query->get('state');
        $pending = is_string($state) && $request->hasSession() ? $this->states->consume($request->getSession(), $state, $account) : null;
        if ($pending === null) {
            $this->logger->warning('Slack install refused.', ['reason' => 'state']);

            return $this->back($request, $back, 'error', 'install.flash.invalid_state', [], $this->translator);
        }
        ['app' => $app, 'scope' => $scope] = $pending;
        if ($request->query->has('error')) {
            return $this->back($request, $back, 'warning', 'install.flash.cancelled', [], $this->translator);
        }
        if (!$this->authorizer->mayInstall(new SlackInstallAttempt($app, $scope, $account))) {
            $this->logger->info('Slack install refused.', ['app' => $app, 'reason' => 'authorizer']);

            return $this->back($request, $back, 'error', 'install.flash.refused', ['%reason%' => 'not_allowed'], $this->translator);
        }

        $code         = $request->query->get('code');
        $clientId     = $this->apps->clientId($app);
        $clientSecret = $this->apps->clientSecret($app);
        try {
            if (!is_string($code) || $clientId === null || !$clientSecret instanceof SlackSecret) {
                throw new InvalidArgumentException('Missing code or client credentials.');
            }
            $grant = $this->oauth->exchange(new SlackOAuthExchange($clientId, $clientSecret, $code, $this->redirectUri()));
        } catch (InvalidArgumentException) {
            return $this->back($request, $back, 'error', 'install.flash.failed', ['%error%' => 'invalid_request'], $this->translator);
        } catch (SlackApiException $e) {
            $this->logger->warning('Slack install failed.', ['app' => $app, 'error' => $e->errorCode]);

            return $this->back($request, $back, 'error', 'install.flash.failed', ['%error%' => $e->errorCode], $this->translator);
        }

        $refusal = $this->refusal($app, $grant);
        if ($refusal !== null || !$grant->team instanceof SlackTeamId || !$grant->botToken instanceof SlackSecret) {
            $this->logger->warning('Slack install refused.', ['app' => $app, 'reason' => $refusal ?? 'invalid_grant']);

            return $this->back($request, $back, 'error', 'install.flash.refused', ['%reason%' => $refusal ?? 'invalid_grant'], $this->translator);
        }

        $now         = DateTimeImmutable::createFromInterface($this->clock->now());
        $key         = new SlackInstallationKey($app, $grant->team, $grant->enterprise);
        $existing    = $this->store->find($key);
        $reinstalled = $existing instanceof SlackInstallation && $key->admits($existing);
        $this->store->save(new SlackInstallation(
            $key,
            $grant->botToken,
            $grant->team,
            in_array('users:read.email', $grant->scopes, true),
            $grant->botUserId,
            null,
            $grant->enterprise,
            $grant->appId,
            $grant->teamName,
            $grant->scopes,
            $grant->installer,
            $now,
        ));
        $this->bindings->bind(new SlackInstallationBinding($key, $scope, $now, $account));
        $this->events?->dispatch(new SlackInstallationCompletedEvent($key, $scope, $account, $reinstalled));
        $this->logger->info('Slack app installed.', ['app' => $app, 'installation' => $key->key(), 'reinstalled' => $reinstalled]);

        return $this->back($request, $back, 'success', 'install.flash.ok', ['%team%' => $grant->teamName], $this->translator);
    }

    /**
     * Why a grant is refused, or null: another Slack app, an org-wide install, a token rotation grant, or a missing
     * required scope.
     */
    private function refusal(string $app, SlackOAuthGrant $grant): ?string
    {
        return match (true) {
            $grant->appId === null || $grant->appId !== $this->apps->appId($app) => 'app_mismatch',
            $grant->enterpriseInstall                                            => 'enterprise_install',
            $grant->rotating                                                     => 'token_rotation_unsupported',
            array_diff($this->scopes, $grant->scopes) !== []                     => 'missing_scope',
            default                                                              => null,
        };
    }

    /**
     * @throws AccessDeniedException when nobody is signed in
     */
    private function account(): string
    {
        $account = $this->accounts->currentAccountId();
        if ($account === null || preg_match('/^.{1,128}$/u', $account) !== 1) {
            throw new AccessDeniedException('Sign in to install Slack.');
        }

        return $account;
    }

    private function redirectUri(): string
    {
        return $this->urls->generate(self::CALLBACK, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function returnUrl(): string
    {
        return $this->returnRoute !== null ? $this->urls->generate($this->returnRoute) : '/';
    }
}
