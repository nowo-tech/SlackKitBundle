<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Controller;

use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function strtr;

/**
 * Flash + 303 redirect shared by the bundle's POST actions.
 */
trait FlashTrait
{
    /**
     * @param array<string, string> $params
     */
    private function back(Request $request, string $url, string $type, string $key, array $params = [], ?TranslatorInterface $translator = null): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $translator?->trans($key, $params, NowoSlackKitBundle::TRANSLATION_DOMAIN) ?? strtr($key, $params));
        }

        return new RedirectResponse($url, RedirectResponse::HTTP_SEE_OTHER);
    }
}
