<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Interaction\InteractionRouter;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;
use function is_bool;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Collects the tagged action handlers into the router's locator and `action_id` → requires-actor map. Two handlers
 * for one action, a malformed action id, or a legacy mapping to an action without a handler fail the build.
 */
final class ActionHandlerPass implements CompilerPassInterface
{
    /**
     * Wires the handlers.
     *
     * @throws LogicException on duplicates, malformed ids or unmapped legacy actions
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(InteractionRouter::class)) {
            return;
        }
        $handlers = [];
        $requires = [];
        foreach ($container->findTaggedServiceIds(NowoSlackKitBundle::HANDLER_TAG) as $id => $tags) {
            foreach ($tags as $tag) {
                $action = $tag['action'] ?? null;
                if (!is_string($action) || preg_match(ButtonValueCodec::ACTION_ID_PATTERN, $action) !== 1) {
                    throw new LogicException(sprintf('Slack action handler "%s" needs an action id matching %s.', $id, ButtonValueCodec::ACTION_ID_PATTERN));
                }
                if (isset($handlers[$action])) {
                    throw new LogicException(sprintf('Two Slack action handlers for "%s".', $action));
                }
                $handlers[$action] = new Reference($id);
                $requires[$action] = !is_bool($tag['requires_actor'] ?? null) || $tag['requires_actor'];
            }
        }
        $map = $container->hasParameter('nowo_slack_kit.interactions.legacy_action_map') ? $container->getParameter('nowo_slack_kit.interactions.legacy_action_map') : [];
        foreach (is_array($map) ? $map : [] as $legacy => $action) {
            if (!isset($handlers[$action])) {
                throw new LogicException(sprintf('nowo_slack_kit.interactions.legacy_action_map: "%s" maps to "%s", which has no handler.', $legacy, is_string($action) ? $action : '?'));
            }
        }

        $router = $container->getDefinition(InteractionRouter::class);
        $router->setArgument('$handlers', ServiceLocatorTagPass::register($container, $handlers));
        $router->setArgument('$requiresActor', $requires);
    }
}
