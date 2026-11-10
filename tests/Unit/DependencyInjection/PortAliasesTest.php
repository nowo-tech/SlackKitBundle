<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\DependencyInjection;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SlackKitBundle\Controller\AccountLinkController;
use Nowo\SlackKitBundle\DependencyInjection\NowoSlackKitExtension;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodecInterface;
use Nowo\SlackKitBundle\Interaction\InteractionRouter;
use Nowo\SlackKitBundle\Linking\AccountLinker;
use Nowo\SlackKitBundle\Linking\AccountLinkerInterface;
use Nowo\SlackKitBundle\Linking\ConfirmLinkHandler;
use Nowo\SlackKitBundle\Twig\SlackKitTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;

/**
 * 1.2 (DDD P2): AccountLinkerInterface and ButtonValueCodecInterface are public aliases, and the bundle's own services
 * reference the interface ids so a host replacement applies everywhere.
 */
final class PortAliasesTest extends TestCase
{
    public function testInterfacesArePublicAliasesOfTheDefaults(): void
    {
        $container = $this->load(['linking' => ['enabled' => true, 'identity_store' => 'app.ids', 'account_resolver' => 'app.accounts']]);

        $codec = $container->getAlias(ButtonValueCodecInterface::class);
        self::assertSame(ButtonValueCodec::class, (string) $codec);
        self::assertTrue($codec->isPublic());

        $linker = $container->getAlias(AccountLinkerInterface::class);
        self::assertSame(AccountLinker::class, (string) $linker);
        self::assertTrue($linker->isPublic());
    }

    public function testBundleServicesReferenceTheInterfaceIds(): void
    {
        $container = $this->load(['linking' => ['enabled' => true, 'identity_store' => 'app.ids', 'account_resolver' => 'app.accounts']]);

        self::assertContains(ButtonValueCodecInterface::class, $this->references($container, InteractionRouter::class));
        self::assertContains(ButtonValueCodecInterface::class, $this->references($container, AccountLinker::class));
        self::assertContains(AccountLinkerInterface::class, $this->references($container, ConfirmLinkHandler::class));
        self::assertContains(AccountLinkerInterface::class, $this->references($container, AccountLinkController::class));
        self::assertContains(AccountLinkerInterface::class, $this->references($container, SlackKitTwigExtension::class));
        self::assertNotContains(AccountLinker::class, $this->references($container, SlackKitTwigExtension::class));
    }

    public function testNoLinkerAliasWhenLinkingIsOff(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->hasAlias(AccountLinkerInterface::class));
        self::assertTrue($container->hasAlias(ButtonValueCodecInterface::class));
        self::assertNotContains(AccountLinkerInterface::class, $this->references($container, SlackKitTwigExtension::class));
    }

    /**
     * @return list<string>
     */
    private function references(ContainerBuilder $container, string $id): array
    {
        $ids = [];
        foreach ($container->getDefinition($id)->getArguments() as $argument) {
            if ($argument instanceof Reference) {
                $ids[] = (string) $argument;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag(['kernel.secret' => 'test-secret-not-real']));
        $extension = new NowoSlackKitExtension(InstanceSettingsInterface::class, MessageBusInterface::class, TransportFactoryInterface::class);
        $container->registerExtension($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
