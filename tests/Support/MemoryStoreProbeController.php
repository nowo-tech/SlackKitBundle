<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A host page that installs the app for the workspace of the request, after reporting which installation a
 * workspace-less lookup returns at the start of the request (it must be none: the store starts every request fresh).
 */
final readonly class MemoryStoreProbeController
{
    public function __construct(private SlackInstallationStoreInterface $store)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $seen = $this->store->find(SlackInstallationKey::default());
        $team = SlackTeamId::fromString($request->query->getString('team'));
        $this->store->save(new SlackInstallation(new SlackInstallationKey(SlackInstallationKey::DEFAULT_APP, $team), SlackSecret::botToken(Fake::TOKEN), $team));

        return new JsonResponse(['seen' => $seen?->teamId?->value, 'saved' => $team->value]);
    }
}
