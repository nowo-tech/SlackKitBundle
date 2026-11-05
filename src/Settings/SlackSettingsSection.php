<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Settings;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionNoticesInterface;
use Nowo\SlackKitBundle\Controller\TestConnectionController;
use Nowo\SlackKitBundle\Credentials\SlackInstallation;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Credentials\SlackInstallationStoreInterface;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The Slack instance settings section (default name `slack`): bot token and signing secret (encrypted secrets),
 * workspace id and the e-mail lookup switch — the keys Symfony Beacon already uses. The intro shows the Interactivity
 * URL; *Test connection* (POST + CSRF) follows Save once a token is stored; notices say when Slack is not configured
 * and add host notices.
 */
final class SlackSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface, SettingsSectionNoticesInterface
{
    /**
     * @param string $name section name
     * @param SlackInstallationStoreInterface $store installation store (status and notices)
     * @param iterable<SlackSettingsNoticeProviderInterface> $noticeProviders host notices
     * @param UrlGeneratorInterface|null $urls router (Test connection, Interactivity URL)
     * @param CsrfOnlyFormFactory|null $csrfForms FormKit CSRF-only form factory
     * @param bool $testConnection whether the Test connection action is enabled
     * @param string|null $interactivityRoute route of the interactions endpoint (null: not mounted)
     */
    public function __construct(
        private readonly string $name,
        private readonly SlackInstallationStoreInterface $store,
        private readonly iterable $noticeProviders = [],
        private readonly ?UrlGeneratorInterface $urls = null,
        private readonly ?CsrfOnlyFormFactory $csrfForms = null,
        private readonly bool $testConnection = true,
        private readonly ?string $interactivityRoute = null,
    ) {
    }

    /**
     * Section name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Title key.
     */
    public function getLabel(): string
    {
        return 'settings.slack.label';
    }

    /**
     * `NowoSlackKitBundle`.
     */
    public function getTranslationDomain(): string
    {
        return NowoSlackKitBundle::TRANSLATION_DOMAIN;
    }

    /**
     * Intro with the Interactivity URL to paste in the Slack app.
     */
    public function getIntro(): TranslatableMessage
    {
        $url = '-';
        if ($this->urls instanceof UrlGeneratorInterface && $this->interactivityRoute !== null) {
            $url = $this->urls->generate($this->interactivityRoute, [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return new TranslatableMessage('settings.slack.intro', ['%url%' => $url], NowoSlackKitBundle::TRANSLATION_DOMAIN);
    }

    /**
     * UiKit icon.
     */
    public function getIcon(): string
    {
        return 'tabler:brand-slack';
    }

    /**
     * Fields in form order.
     *
     * @return iterable<SettingField>
     */
    public function getFields(): iterable
    {
        yield SettingField::secret('bot_token', 'settings.slack.bot_token', 'settings.slack.bot_token_help', constraints: [
            new Regex(pattern: SlackSecret::BOT_TOKEN_PATTERN, message: 'A bot token starts with xoxb- (user, app-level and refresh tokens are refused).'),
        ]);
        yield SettingField::secret('signing_secret', 'settings.slack.signing_secret', 'settings.slack.signing_secret_help', constraints: [
            new Length(min: 16, max: 128),
            new Regex(pattern: SlackSecret::SIGNING_SECRET_PATTERN, message: 'A signing secret has 16 to 128 letters or digits.'),
        ]);
        yield SettingField::string('team_id', null, 'settings.slack.team_id', 'settings.slack.team_id_help', constraints: [
            new Regex(pattern: SlackTeamId::PATTERN, message: 'A workspace id starts with T (or E) followed by capital letters and digits.'),
        ]);
        yield SettingField::bool('allow_email_lookup', false, 'settings.slack.allow_email_lookup', 'settings.slack.allow_email_lookup_help');
    }

    /**
     * *Test connection* once a token is stored.
     *
     * @param SectionActionContext $context page being rendered (unused: the page itself is access-checked)
     *
     * @return iterable<Action>
     */
    public function getActions(SectionActionContext $context): iterable
    {
        if (!$this->testConnection || !$this->urls instanceof UrlGeneratorInterface || !$this->csrfForms instanceof CsrfOnlyFormFactory || !$this->store->find(SlackInstallationKey::default()) instanceof SlackInstallation) {
            return [];
        }
        $url = $this->urls->generate(TestConnectionController::ROUTE);

        return [new Action(
            'custom',
            new Label('settings.slack.test_connection', NowoSlackKitBundle::TRANSLATION_DOMAIN),
            $url,
            method: 'POST',
            variant: 'secondary',
            attributes: ['data-testid' => 'slack-kit-test-connection'],
            form: $this->csrfForms->createNamed($url, TestConnectionController::CSRF_TOKEN_ID)->createView(),
        )];
    }

    /**
     * "Not configured" / "workspace id missing" plus the host notices.
     *
     * @param SectionActionContext $context page being rendered (unused)
     *
     * @return iterable<TranslatableMessage>
     */
    public function getNotices(SectionActionContext $context): iterable
    {
        $installation = $this->store->find(SlackInstallationKey::default());
        if (!$installation instanceof SlackInstallation || !$this->store->findSigningSecret(SlackInstallationKey::DEFAULT_APP) instanceof SlackSecret) {
            yield new TranslatableMessage('settings.slack.notice_off', [], NowoSlackKitBundle::TRANSLATION_DOMAIN);
        } elseif (!$installation->teamId instanceof SlackTeamId) {
            yield new TranslatableMessage('settings.slack.notice_no_team', [], NowoSlackKitBundle::TRANSLATION_DOMAIN);
        }
        foreach ($this->noticeProviders as $provider) {
            yield from $provider->getNotices();
        }
    }
}
