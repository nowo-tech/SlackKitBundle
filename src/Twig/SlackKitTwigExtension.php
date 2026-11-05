<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Twig;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\SlackKitBundle\Controller\AccountLinkController;
use Nowo\SlackKitBundle\Form\SlackUserIdType;
use Nowo\SlackKitBundle\Ingress\IngressController;
use Nowo\SlackKitBundle\Linking\AccountLinker;
use Nowo\SlackKitBundle\Linking\SlackAccountResolverInterface;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions: `nowo_slack_kit_link_card()` (view data of the account link card, `@NowoSlackKitBundle/account/_link.html.twig`)
 * and `nowo_slack_kit_ingress_url(kind, app)` (the URL to paste in the Slack app).
 */
final class SlackKitTwigExtension extends AbstractExtension
{
    /**
     * @param UrlGeneratorInterface $urls router
     * @param AccountLinker|null $linker linking state machine (null: linking disabled)
     * @param SlackAccountResolverInterface|null $accounts current account
     * @param FormFactoryInterface|null $forms form factory
     * @param CsrfOnlyFormFactory|null $csrfForms FormKit CSRF-only forms
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly ?AccountLinker $linker = null,
        private readonly ?SlackAccountResolverInterface $accounts = null,
        private readonly ?FormFactoryInterface $forms = null,
        private readonly ?CsrfOnlyFormFactory $csrfForms = null,
    ) {
    }

    /**
     * The two functions.
     *
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_slack_kit_link_card', $this->linkCard(...)),
            new TwigFunction('nowo_slack_kit_ingress_url', $this->ingressUrl(...)),
        ];
    }

    /**
     * View data of the link card, or null when linking is off or nobody is signed in.
     *
     * @return array{status: string, slack_user_id: string|null, claim_form: FormView|null, actions: list<Action>}|null
     */
    public function linkCard(): ?array
    {
        $account = $this->accounts?->currentAccountId();
        if (!$this->linker instanceof AccountLinker || $account === null || !$this->forms instanceof FormFactoryInterface || !$this->csrfForms instanceof CsrfOnlyFormFactory) {
            return null;
        }
        $link   = $this->linker->linkOf($account);
        $status = $link instanceof SlackLink ? $link->isVerified() ? 'verified' : 'claimed' : ('none');

        $actions = [];
        if ($this->linker->canFindByEmail() && $status !== 'verified') {
            $actions[] = $this->post(AccountLinkController::FIND, 'link.action.find', 'secondary');
        }
        if ($status === 'claimed') {
            $actions[] = $this->post(AccountLinkController::CONFIRM, 'link.action.confirm', 'primary');
        }
        if ($status !== 'none') {
            $actions[] = $this->post(AccountLinkController::UNLINK, 'link.action.unlink', 'danger', new Confirm(
                new Label('link.action.unlink_confirm', NowoSlackKitBundle::TRANSLATION_DOMAIN),
            ));
        }
        $claim = $status === 'verified' ? null : $this->forms->create(SlackUserIdType::class, null, ['action' => $this->urls->generate(AccountLinkController::CLAIM)])->createView();

        return ['status' => $status, 'slack_user_id' => $link?->slackUserId->value, 'claim_form' => $claim, 'actions' => $actions];
    }

    /**
     * Absolute URL of the interactions (`kind: interactions`) or Events API (`kind: events`) endpoint.
     */
    public function ingressUrl(string $kind = 'interactions', ?string $app = null): string
    {
        $route = $kind === 'events' ? IngressController::EVENTS_ROUTE : IngressController::INTERACTIONS_ROUTE;
        if ($app !== null) {
            return $this->urls->generate($route . '_app', ['app' => $app], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $this->urls->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function post(string $route, string $label, string $variant, ?Confirm $confirm = null): Action
    {
        $url = $this->urls->generate($route);

        return new Action(
            'custom',
            new Label($label, NowoSlackKitBundle::TRANSLATION_DOMAIN),
            $url,
            method: 'POST',
            confirm: $confirm,
            variant: $variant,
            attributes: ['data-testid' => $route],
            form: $this->csrfForms?->createNamed($url, $route)->createView(),
        );
    }
}
