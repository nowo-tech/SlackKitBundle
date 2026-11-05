<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Routing;

use Nowo\SlackKitBundle\Controller\AccountLinkController;
use Nowo\SlackKitBundle\Controller\TestConnectionController;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Ingress\IngressController;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function rtrim;
use function trim;

/**
 * Loads the bundle routes (`type: nowo_slack_kit`): the signed ingress (interactions, and the Events API when
 * enabled; each also with an `{app}` segment), *Test connection* and the account link actions. Paths come from the
 * configuration; the POST actions are declared with #[Route] on their controllers, which are not autoconfigured, so
 * only this loader mounts them (REQ-SF-004).
 */
final class SlackKitRouteLoader extends Loader
{
    /** Routing resource type. */
    public const TYPE = 'nowo_slack_kit';

    private bool $loaded = false;

    /**
     * @param array{enabled: bool, interactions: string, events: string|null} $ingress ingress paths (events null = off)
     * @param string $uiPrefix `ui.prefix`
     * @param bool $testConnection whether *Test connection* is mounted
     * @param bool $linking whether the account link actions are mounted
     */
    public function __construct(
        private readonly array $ingress,
        private readonly string $uiPrefix,
        private readonly bool $testConnection,
        private readonly bool $linking,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    /**
     * Builds the route collection (once per router warm-up).
     *
     * @param mixed $resource ignored
     * @param string|null $type `nowo_slack_kit`
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Do not add the "nowo_slack_kit" route loader twice.');
        }
        // @igor-ignore - Route loader builds routes at cache warm-up; not per-request state.
        $this->loaded = true;

        $routes = new RouteCollection();
        if ($this->ingress['enabled']) {
            $this->ingressRoutes($routes, $this->ingress['interactions'], 'interactions', IngressController::INTERACTIONS_ROUTE, IngressController::INTERACTIONS_APP_ROUTE);
            if ($this->ingress['events'] !== null) {
                $this->ingressRoutes($routes, $this->ingress['events'], 'events', IngressController::EVENTS_ROUTE, IngressController::EVENTS_APP_ROUTE);
            }
        }
        $prefix = '/' . trim($this->uiPrefix, '/');
        if ($this->testConnection) {
            $this->attributeRoutes($routes, TestConnectionController::class, rtrim($prefix, '/') . '/settings');
        }
        if ($this->linking) {
            $this->attributeRoutes($routes, AccountLinkController::class, rtrim($prefix, '/') . '/account/slack');
        }

        return $routes;
    }

    /**
     * Supports the `nowo_slack_kit` type.
     *
     * @param mixed $resource ignored
     * @param string|null $type resource type
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }

    private function ingressRoutes(RouteCollection $routes, string $path, string $method, string $name, string $appName): void
    {
        $path       = '/' . trim($path, '/');
        $controller = IngressController::class . '::' . $method;
        $routes->add($name, new Route($path, ['_controller' => $controller, 'app' => null, '_stateless' => true], methods: ['POST']));
        $routes->add($appName, new Route($path . '/{app}', ['_controller' => $controller, '_stateless' => true], ['app' => trim(SlackInstallationKey::APP_PATTERN, '/^$')], methods: ['POST']));
    }

    /**
     * @param class-string $controller
     */
    private function attributeRoutes(RouteCollection $routes, string $controller, string $prefix): void
    {
        foreach ((new ReflectionClass($controller))->getMethods() as $method) {
            foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                $declared = $attribute->newInstance();
                $suffix   = is_string($declared->path) ? $declared->path : '';
                $routes->add((string) $declared->name, new Route(
                    $prefix . $suffix,
                    ['_controller' => $method->getName() === '__invoke' ? $controller : $controller . '::' . $method->getName()],
                    methods: $declared->methods,
                ));
            }
        }
    }
}
