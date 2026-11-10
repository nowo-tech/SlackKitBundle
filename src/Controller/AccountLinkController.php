<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Controller;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\SlackKitBundle\Form\SlackUserIdType;
use Nowo\SlackKitBundle\Linking\AccountLinkerInterface;
use Nowo\SlackKitBundle\Linking\LinkResult;
use Nowo\SlackKitBundle\Linking\SlackAccountResolverInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_array;
use function is_string;

/**
 * The account link card's POST actions (contract §10): claim a typed id, find by e-mail, send the confirmation DM,
 * unlink. Each needs a signed-in account (from {@see SlackAccountResolverInterface}, never from the request) and a
 * valid CSRF token, and redirects back with a flash message. Mounted only by the `nowo_slack_kit` route loader.
 */
final readonly class AccountLinkController
{
    use FlashTrait;

    /** Route names. */
    public const CLAIM   = 'nowo_slack_kit_link_claim';
    public const FIND    = 'nowo_slack_kit_link_find';
    public const CONFIRM = 'nowo_slack_kit_link_confirm';
    public const UNLINK  = 'nowo_slack_kit_link_unlink';

    /**
     * @param AccountLinkerInterface $linker linking state machine
     * @param SlackAccountResolverInterface $accounts current account
     * @param FormFactoryInterface $forms form factory (claim form)
     * @param CsrfOnlyFormFactory $csrfForms FormKit CSRF-only forms (other actions)
     * @param UrlGeneratorInterface $urls router
     * @param string|null $returnRoute route to go back to (`linking.return_route`; null: `/`)
     * @param TranslatorInterface|null $translator translator of the flash messages
     */
    public function __construct(
        private AccountLinkerInterface $linker,
        private SlackAccountResolverInterface $accounts,
        private FormFactoryInterface $forms,
        private CsrfOnlyFormFactory $csrfForms,
        private UrlGeneratorInterface $urls,
        private ?string $returnRoute = null,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Claims the typed member id.
     */
    #[Route(path: '/claim', name: self::CLAIM, methods: ['POST'])]
    public function claim(Request $request): RedirectResponse
    {
        $account = $this->account();
        $form    = $this->forms->create(SlackUserIdType::class, null, ['action' => $this->urls->generate(self::CLAIM)]);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            throw new AccessDeniedHttpException('Invalid form.');
        }
        if (!$form->isValid()) {
            $csrf = $form->getErrors()->count() > 0;
            if ($csrf) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }

            return $this->answer($request, LinkResult::Invalid);
        }
        $data = $form->getData();
        $id   = is_array($data) && is_string($data['slack_user_id'] ?? null) ? $data['slack_user_id'] : '';

        return $this->answer($request, $this->linker->claim($account, $id));
    }

    /**
     * Finds the member id by the account's e-mail.
     */
    #[Route(path: '/find', name: self::FIND, methods: ['POST'])]
    public function find(Request $request): RedirectResponse
    {
        $account = $this->guard($request, self::FIND);

        return $this->answer($request, $this->linker->findByEmail($account));
    }

    /**
     * Sends the confirmation DM.
     */
    #[Route(path: '/confirm', name: self::CONFIRM, methods: ['POST'])]
    public function confirm(Request $request): RedirectResponse
    {
        $account = $this->guard($request, self::CONFIRM);

        return $this->answer($request, $this->linker->sendConfirmation($account));
    }

    /**
     * Removes the link.
     */
    #[Route(path: '/unlink', name: self::UNLINK, methods: ['POST'])]
    public function unlink(Request $request): RedirectResponse
    {
        $account = $this->guard($request, self::UNLINK);

        return $this->answer($request, $this->linker->unlink($account));
    }

    private function guard(Request $request, string $route): string
    {
        $account = $this->account();
        $form    = $this->csrfForms->createNamed($this->urls->generate($route), $route);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        return $account;
    }

    private function account(): string
    {
        return $this->accounts->currentAccountId() ?? throw new AccessDeniedHttpException('Sign in to link your Slack account.');
    }

    private function answer(Request $request, LinkResult $result): RedirectResponse
    {
        $url = $this->returnRoute !== null ? $this->urls->generate($this->returnRoute) : '/';

        return $this->back($request, $url, $result->isSuccess() ? 'success' : 'error', $result->translationKey(), [], $this->translator);
    }
}
