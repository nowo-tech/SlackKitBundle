<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Controller;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\SlackKitBundle\Controller\TestConnectionController;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\RecordingApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * *Test connection* when the host did not import the InstanceSettings routes: the redirect falls back to `/` and
 * the workspace id is still filled.
 */
final class TestConnectionControllerTest extends TestCase
{
    public function testRedirectsToTheRootWithoutTheSectionRoute(): void
    {
        $access = $this->createMock(InstanceSettingsAccessCheckerInterface::class);
        $access->method('canAccess')->willReturn(true);

        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $forms = $this->createMock(FormFactoryInterface::class);
        $forms->method('createNamed')->willReturn($form);

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $name): string {
            if ($name === InstanceSettingsRoutes::SECTION) {
                throw new RouteNotFoundException($name);
            }

            return '/slack-kit/settings/test-connection';
        });

        $store      = Fake::store(withTeam: false);
        $controller = new TestConnectionController(
            $access,
            new CsrfOnlyFormFactory($forms),
            new RecordingApi(),
            $store,
            new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 minute'], new InMemoryStorage()),
            $urls,
        );

        $response = $controller(Request::create('/slack-kit/settings/test-connection', 'POST'));
        self::assertSame('/', $response->getTargetUrl());
        self::assertSame(Fake::TEAM, $store->find(SlackInstallationKey::default())?->teamId?->value);
    }
}
