<?php

declare(strict_types=1);

namespace App\Settings;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Host section: site-wide values the demo home page reads with instance_setting().
 */
#[AsSettingsSection(priority: 30)]
final class SiteSettingsSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'site';
    }

    public function getIntro(): ?string
    {
        return 'site.intro';
    }

    public function getIcon(): ?string
    {
        return 'config';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('name', 'Acme Ops', 'site.name', required: true, constraints: [new Length(max: 80)]);
        yield SettingField::text('footer', null, 'site.footer', 'site.footer_help');
        yield SettingField::url('support_url', null, 'site.support_url');
        yield SettingField::int('page_size', 25, 'site.page_size', 'site.page_size_help', constraints: [new Range(min: 5, max: 200)]);
        yield SettingField::choice('theme', ['site.theme_light' => 'light', 'site.theme_dark' => 'dark', 'site.theme_auto' => 'auto'], 'auto', 'site.theme');
        yield SettingField::bool('maintenance_banner', false, 'site.maintenance_banner')->notPortable();
    }
}
