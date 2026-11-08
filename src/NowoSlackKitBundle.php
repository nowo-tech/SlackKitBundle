<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle;

use Nowo\SlackKitBundle\DependencyInjection\Compiler\ActionHandlerPass;
use Nowo\SlackKitBundle\DependencyInjection\Compiler\CsrfPass;
use Nowo\SlackKitBundle\DependencyInjection\Compiler\LinkingLimiterPoolPass;
use Nowo\SlackKitBundle\DependencyInjection\Compiler\ReplayLockPass;
use Nowo\SlackKitBundle\DependencyInjection\NowoSlackKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Slack for Symfony: a hardened Web API client, signed interactions and Events API ingress with replay protection,
 * MAC-signed button values (`nsk1`), an interactions router with verified actors, verified account linking, a Block
 * Kit builder, the Slack instance settings section and an optional Notifier chat transport.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoSlackKitBundle extends Bundle
{
    /** Translation domain (REQ-I18N-003). */
    public const TRANSLATION_DOMAIN = 'NowoSlackKitBundle';

    /** Tag of {@see Interaction\SlackActionHandlerInterface} services (attribute `action`, `requires_actor`). */
    public const HANDLER_TAG = 'nowo_slack_kit.action_handler';

    /** Tag of {@see Settings\SlackSettingsNoticeProviderInterface} services. */
    public const NOTICE_TAG = 'nowo_slack_kit.settings_notice';

    /**
     * Registers the handler map, the CSRF guard of the POST actions and the replay lock store check.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ActionHandlerPass());
        $container->addCompilerPass(new CsrfPass());
        $container->addCompilerPass(new ReplayLockPass());
        $container->addCompilerPass(new LinkingLimiterPoolPass());
    }

    /**
     * The extension alias is `nowo_slack_kit`.
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoSlackKitExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }

    /**
     * Bundle root (templates and translations live in src/Resources).
     */
    public function getPath(): string
    {
        return __DIR__;
    }
}
