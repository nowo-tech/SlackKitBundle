<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Controller;

use Nowo\SlackKitBundle\Linking\LinkResult;
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

    /**
     * REQ-RUNTIME-003 rule 6 on a Post/Redirect/Get form: a request that lost a link lock ({@see LinkResult::Busy},
     * nothing written) keeps its 303 and flash ("try again") and carries `X-Conflict-Code: CONFLICT_001` and
     * `Retry-After`, so clients and tests can key on the code.
     */
    private function conflictMarked(RedirectResponse $response, LinkResult $result): RedirectResponse
    {
        if ($result === LinkResult::Busy) {
            $response->headers->set('X-Conflict-Code', 'CONFLICT_001');
            $response->headers->set('Retry-After', '1');
        }

        return $response;
    }
}
