<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\SlackKitBundle\Tests\Support\FakeIdentityStore;
use Nowo\SlackKitBundle\Tests\Support\FakeSlackApi;
use Nowo\SlackKitBundle\Tests\Support\ProfileController;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use Nowo\SlackKitBundle\Tests\Support\TokenAccountResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function count;

/**
 * Boots a fresh {@see SlackKitTestKernel} per call (own cache dir, schema on the in-memory database).
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<SlackKitTestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(SlackKitTestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $config nowo_slack_kit configuration
     */
    protected function bootKernel(array $config = []): SlackKitTestKernel
    {
        $id     = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel = new SlackKitTestKernel(
            $id,
            static function (ContainerConfigurator $container) use ($config): void {
                $container->extension('nowo_instance_settings', []);
                $container->extension('nowo_slack_kit', $config);
                $services = $container->services();
                $services->set('logger', RecordingLogger::class)->public();
                $services->set('test.fake_slack_api', FakeSlackApi::class)->public();
                $services->set('test.identities', FakeIdentityStore::class)->public();
                $services->set(ProfileController::class)->autowire()->tag('controller.service_arguments')->public();
                $services->set('test.accounts', TokenAccountResolver::class)->args([new ReferenceConfigurator('security.token_storage')])->public();
            },
            static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->import('.', 'nowo_slack_kit');
                $routes->add('test_profile', '/profile')->controller('Nowo\SlackKitBundle\Tests\Support\ProfileController');
            },
        );
        $this->kernels[] = $kernel;
        $kernel->boot();

        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $kernel;
    }

    protected function container(SlackKitTestKernel $kernel): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = $kernel->getContainer()->get('test.service_container');

        return $container;
    }

    protected function client(SlackKitTestKernel $kernel, ?string $user = 'admin'): KernelBrowser
    {
        /** @var KernelBrowser $client */
        $client = $kernel->getContainer()->get('test.client');
        $client->disableReboot();
        $client->setServerParameters($user === null ? [] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user]);

        return $client;
    }

    protected function settings(SlackKitTestKernel $kernel): InstanceSettingsInterface
    {
        $settings = $this->container($kernel)->get(InstanceSettingsInterface::class);
        self::assertInstanceOf(InstanceSettingsInterface::class, $settings);

        return $settings;
    }

    protected function fakeSlack(SlackKitTestKernel $kernel): FakeSlackApi
    {
        $fake = $this->container($kernel)->get('test.fake_slack_api');
        self::assertInstanceOf(FakeSlackApi::class, $fake);

        return $fake;
    }

    protected function identities(SlackKitTestKernel $kernel): FakeIdentityStore
    {
        $ids = $this->container($kernel)->get('test.identities');
        self::assertInstanceOf(FakeIdentityStore::class, $ids);

        return $ids;
    }
}
