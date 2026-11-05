<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * A host profile page that includes the link card.
 */
final readonly class ProfileController
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->createTemplate("{% for t, ms in app.flashes %}{% for m in ms %}<p class=\"flash-{{ t }}\">{{ m }}</p>{% endfor %}{% endfor %}{{ include('@NowoSlackKitBundle/account/_link.html.twig') }}")->render());
    }
}
