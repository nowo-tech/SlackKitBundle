<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Settings;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * Host notices on the Slack settings section (for example delivery counts of the last 24 hours). Autoconfigured with
 * the tag `nowo_slack_kit.settings_notice`.
 */
interface SlackSettingsNoticeProviderInterface
{
    /**
     * Notices to show; never include secrets.
     *
     * @return iterable<TranslatableMessage>
     */
    public function getNotices(): iterable;
}
