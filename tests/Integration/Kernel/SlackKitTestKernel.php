<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;

/**
 * Integration kernel: Framework, Security (http_basic, in-memory users), Twig, Doctrine on in-memory SQLite,
 * DoctrineEncrypt, FormKit, UiKit, InstanceSettings and SlackKit, with a fake Slack API as the HttpClient.
 */
final class SlackKitTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param Closure(ContainerConfigurator): void $services
     * @param Closure(RoutingConfigurator): void $routes
     * @param array<string, mixed> $framework extra FrameworkBundle configuration
     */
    public function __construct(
        private readonly string $id,
        private readonly Closure $services,
        private readonly Closure $routes,
        string $environment = 'test',
        private readonly array $framework = [],
    ) {
        parent::__construct($environment, false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        yield new NowoDoctrineEncryptBundle();
        yield new NowoFormKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoInstanceSettingsBundle();
        yield new NowoSlackKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-slack-kit-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'test-secret-not-real',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'csrf_protection'       => true,
            'form'                  => ['csrf_protection' => true],
            'validation'            => true,
            'http_client'           => ['mock_response_factory' => 'test.fake_slack_api'],
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'translator'            => ['fallbacks' => ['en']],
            'router'                => ['utf8' => true],
        ] + $this->framework);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => []]);
        $container->extension('nowo_doctrine_encrypt', [
            'default_profile'      => 'default',
            'secret_permissions'   => ['enabled' => false],
            'generate_missing_key' => true,
            'profiles'             => ['default' => ['encryptor_class' => 'Halite', 'secret_directory_path' => self::baseDir() . '/' . $this->id . '/keys']],
        ]);
        $container->extension('security', [
            'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
            'providers'        => ['memory' => ['memory' => ['users' => [
                'admin'  => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                'viewer' => ['password' => 'viewer', 'roles' => ['ROLE_USER']],
            ]]]],
            'firewalls' => [
                'slack' => ['pattern' => '^/slack/(interactions|events)', 'security' => false],
                'main'  => ['lazy' => true, 'http_basic' => true],
            ],
        ]);
        ($this->services)($container);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        ($this->routes)($routes);
    }
}
