<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\SlackKitBundle\Controller\AccountLinkController;
use Nowo\SlackKitBundle\Controller\TestConnectionController;
use Nowo\SlackKitBundle\Install\InstallController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when a session POST action (Test connection, account link, "Add to Slack") is enabled without form CSRF protection
 * (REQ-SEC-005).
 */
final class CsrfPass implements CompilerPassInterface
{
    /**
     * Throws when a POST action is registered and form CSRF is unavailable.
     *
     * @throws LogicException when CSRF protection is missing
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TestConnectionController::class) && !$container->hasDefinition(AccountLinkController::class) && !$container->hasDefinition(InstallController::class)) {
            return;
        }
        $disabled = $container->hasParameter('form.type_extension.csrf.enabled') && $container->getParameter('form.type_extension.csrf.enabled') === false;
        if (!$container->has('security.csrf.token_manager') || !$container->hasDefinition('form.type_extension.csrf') || $disabled) {
            throw new LogicException('NowoSlackKitBundle: Test connection, the account link actions and "Add to Slack" require CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection, or disable test_connection, linking and installations.oauth.');
        }
    }
}
