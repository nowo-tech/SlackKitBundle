<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Controller;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function hash;

/**
 * *Test connection* of the Slack settings section: POST only, the InstanceSettings access checker for the section, a
 * valid CSRF token and a per-user rate limit, then `auth.test`. Fills the workspace id when it is empty. Never shows
 * the token. The route exists only through the `nowo_slack_kit` route loader.
 */
final readonly class TestConnectionController
{
    use FlashTrait;

    /** Route name. */
    public const ROUTE = 'nowo_slack_kit_test_connection';

    /** CSRF token id. */
    public const CSRF_TOKEN_ID = 'nowo_slack_kit_test_connection';

    /**
     * @param InstanceSettingsAccessCheckerInterface $access instance settings access checker
     * @param CsrfOnlyFormFactory $csrfForms FormKit CSRF-only form factory
     * @param SlackApiClientInterface $api Web API client
     * @param SlackInstallationStoreInterface $store installation store
     * @param RateLimiterFactoryInterface $rateLimiter per-user limiter
     * @param UrlGeneratorInterface $urls router
     * @param string $section settings section name
     * @param TokenStorageInterface|null $tokens security token storage
     * @param TranslatorInterface|null $translator translator of the flash messages
     */
    public function __construct(
        private InstanceSettingsAccessCheckerInterface $access,
        private CsrfOnlyFormFactory $csrfForms,
        private SlackApiClientInterface $api,
        private SlackInstallationStoreInterface $store,
        private RateLimiterFactoryInterface $rateLimiter,
        private UrlGeneratorInterface $urls,
        private string $section = 'slack',
        private ?TokenStorageInterface $tokens = null,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Runs `auth.test` and redirects to the section page with a flash message.
     *
     * @throws AccessDeniedException when the user may not open the section
     * @throws AccessDeniedHttpException when the CSRF token is invalid
     */
    #[Route(path: '/test-connection', name: self::ROUTE, methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (!$this->access->canAccess($user, $this->section)) {
            throw new AccessDeniedException('Access denied to the Slack settings.');
        }
        $form = $this->csrfForms->createNamed($this->urls->generate(self::ROUTE), self::CSRF_TOKEN_ID);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $back = $this->sectionUrl();
        $key  = $user instanceof UserInterface ? 'user:' . hash('sha256', $user->getUserIdentifier()) : 'ip:' . hash('sha256', (string) $request->getClientIp());
        if (!$this->rateLimiter->create($key)->consume()->isAccepted()) {
            return $this->back($request, $back, 'error', 'flash.test_rate_limited', [], $this->translator);
        }

        try {
            $result = $this->api->authTest(SlackInstallationKey::default());
        } catch (SlackApiException $e) {
            return $this->back($request, $back, 'error', 'flash.test_failed', ['%error%' => $e->errorCode], $this->translator);
        }

        $installation = $this->store->find(SlackInstallationKey::default());
        $team         = SlackTeamId::tryFrom($result->teamId);
        if ($installation instanceof SlackInstallation && !$installation->teamId instanceof SlackTeamId && $team instanceof SlackTeamId) {
            $this->store->save($installation->withWorkspace($team, $result->userId));
        }

        return $this->back($request, $back, 'success', 'flash.test_ok', ['%team%' => $result->team, '%team_id%' => $result->teamId, '%bot%' => $result->user], $this->translator);
    }

    private function sectionUrl(): string
    {
        try {
            return $this->urls->generate(InstanceSettingsRoutes::SECTION, ['section' => $this->section]);
        } catch (RouteNotFoundException) {
            return '/';
        }
    }
}
