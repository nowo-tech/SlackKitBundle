<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * A host integrations page: flashes and the "Add to Slack" action of `organization:org-1`.
 */
final readonly class IntegrationsController
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->createTemplate(
            '{% for t, ms in app.flashes %}{% for m in ms %}<p class="flash-{{ t }}">{{ m }}</p>{% endfor %}{% endfor %}'
            . "{% set a = nowo_slack_kit_install_action('organization', 'org-1') %}"
            . "{% if a is not null %}{{ include('@NowoUiKitBundle/partials/_action.html.twig', {action: a}) }}{% endif %}"
            . '<p data-testid="redirect">{{ nowo_slack_kit_install_redirect_url() }}</p>',
        )->render());
    }
}
