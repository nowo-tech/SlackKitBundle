<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Controller;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Linking\ClickLinkerInterface;
use Nowo\SlackKitBundle\Linking\ClickLinkSigner;
use Nowo\SlackKitBundle\Linking\LinkResult;
use Nowo\SlackKitBundle\Linking\SlackAccountResolverInterface;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\ConfirmPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Link-from-click pages (1.4, spec 237 FR-011 / FR-013): `GET …/link/{token}` shows the workspace and the Slack member
 * by name and asks for an explicit *Confirm* (UiKit confirm page); `POST …/link/{token}` (CSRF) links the identity;
 * `POST …/unlink/{team}` (CSRF) removes the account's identity in one workspace. Every action needs a signed-in
 * account from {@see SlackAccountResolverInterface} (never from the request; the firewall's entry point asks to sign
 * in and comes back). Mounted only by the `nowo_slack_kit` route loader with `linking.from_click.enabled`.
 */
final readonly class ClickLinkController
{
    use FlashTrait;

    /** Route names. */
    public const PAGE    = 'nowo_slack_kit_link_click';
    public const CONFIRM = 'nowo_slack_kit_link_click_confirm';
    public const UNLINK  = 'nowo_slack_kit_link_unlink_team';

    /** CSRF token id of the confirm form. */
    public const CSRF_TOKEN_ID = 'nowo_slack_kit_link_click';

    /**
     * @param ClickLinkerInterface $links link-from-click
     * @param SlackAccountResolverInterface $accounts signed-in account
     * @param CsrfOnlyFormFactory $csrfForms FormKit CSRF-only forms
     * @param UrlGeneratorInterface $urls router
     * @param Environment $twig Twig
     * @param string|null $returnRoute route the actions end on (`linking.from_click.manage_route`, else
     *                                 `linking.return_route`; null: `/`)
     * @param string|null $layout UiKit layout of the confirm page (null: UiKit's admin layout)
     * @param TranslatorInterface|null $translator translator of the flash messages
     */
    public function __construct(
        private ClickLinkerInterface $links,
        private SlackAccountResolverInterface $accounts,
        private CsrfOnlyFormFactory $csrfForms,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
        private ?string $returnRoute = null,
        private ?string $layout = null,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * The confirmation page.
     *
     * @throws AccessDeniedException when nobody is signed in
     */
    #[Route(path: '/link/{token}', name: self::PAGE, requirements: ['token' => ClickLinkSigner::PATTERN], methods: ['GET'])]
    public function page(Request $request, string $token): Response
    {
        $preview = $this->links->preview($this->account(), $token);
        if ($preview instanceof LinkResult) {
            return $this->answer($request, $preview);
        }
        $url  = $this->urls->generate(self::CONFIRM, ['token' => $token]);
        $page = new ConfirmPage(
            new PageHeader(new Label('link_click.page_title', NowoSlackKitBundle::TRANSLATION_DOMAIN)),
            new Confirm(
                new Label('link_click.title', NowoSlackKitBundle::TRANSLATION_DOMAIN, ['%name%' => $preview->displayName, '%team%' => $preview->teamName]),
                new Label('link_click.message', NowoSlackKitBundle::TRANSLATION_DOMAIN, ['%name%' => $preview->displayName, '%team%' => $preview->teamName, '%id%' => $preview->slackUserId]),
                new Label('link_click.confirm', NowoSlackKitBundle::TRANSLATION_DOMAIN),
                'primary',
            ),
            new Action('custom', new Label('link_click.confirm', NowoSlackKitBundle::TRANSLATION_DOMAIN), $url, method: 'POST', variant: 'primary', attributes: ['data-testid' => self::CONFIRM], form: $this->csrfForms->createNamed($url, self::CSRF_TOKEN_ID)->createView()),
            $this->returnUrl(),
            'NowoSlackKitBundle',
            $this->layout,
        );

        return new Response($this->twig->render('@NowoUiKitBundle/page/confirm.html.twig', ['page' => $page, 'preview' => $preview]));
    }

    /**
     * Links the identity.
     *
     * @throws AccessDeniedException when nobody is signed in
     * @throws AccessDeniedHttpException when the CSRF token is invalid
     */
    #[Route(path: '/link/{token}', name: self::CONFIRM, requirements: ['token' => ClickLinkSigner::PATTERN], methods: ['POST'])]
    public function confirm(Request $request, string $token): RedirectResponse
    {
        $account = $this->account();
        $this->guard($request, $this->urls->generate(self::CONFIRM, ['token' => $token]), self::CSRF_TOKEN_ID);

        return $this->answer($request, $this->links->confirm($account, $token));
    }

    /**
     * Unlinks the account's identity in one workspace.
     *
     * @throws AccessDeniedException when nobody is signed in
     * @throws AccessDeniedHttpException when the CSRF token is invalid
     */
    #[Route(path: '/unlink/{team}', name: self::UNLINK, requirements: ['team' => '[TE][A-Z0-9]{2,30}'], methods: ['POST'])]
    public function unlink(Request $request, string $team): RedirectResponse
    {
        $account = $this->account();
        $this->guard($request, $this->urls->generate(self::UNLINK, ['team' => $team]), self::UNLINK);

        return $this->answer($request, $this->links->unlinkIn($account, SlackTeamId::fromString($team)));
    }

    /**
     * @throws AccessDeniedHttpException when the CSRF token is invalid
     */
    private function guard(Request $request, string $url, string $tokenId): void
    {
        $form = $this->csrfForms->createNamed($url, $tokenId);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /**
     * @throws AccessDeniedException when nobody is signed in
     */
    private function account(): string
    {
        return $this->accounts->currentAccountId() ?? throw new AccessDeniedException('Sign in to link your Slack account.');
    }

    private function answer(Request $request, LinkResult $result): RedirectResponse
    {
        return $this->back($request, $this->returnUrl(), $result->isSuccess() ? 'success' : 'error', $result->translationKey(), [], $this->translator);
    }

    private function returnUrl(): string
    {
        return $this->returnRoute !== null ? $this->urls->generate($this->returnRoute) : '/';
    }
}
