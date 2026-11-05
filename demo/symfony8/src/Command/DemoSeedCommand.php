<?php

declare(strict_types=1);

namespace App\Command;

use App\FakeSlack\FakeSlackApi;
use App\Slack\SlackClickSimulator;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seeds the demo settings (idempotent): the site section and the slack section with FAKE values only (owner rule,
 * contract §17). The bot token and signing secret are obvious placeholders; every Slack call goes to the in-app fake.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Seed demo instance settings with fake Slack values (idempotent)')]
final class DemoSeedCommand extends Command
{
    public function __construct(
        private readonly InstanceSettingsInterface $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changed = [
            ...$this->settings->save('site', [
                'name'        => 'Acme Ops',
                'footer'      => 'Acme Ops — status and incident tooling.',
                'support_url' => 'https://support.example.test',
                'page_size'   => 25,
                'theme'       => 'auto',
            ]),
            ...$this->settings->save('slack', [
                'bot_token'          => 'xoxb-test-not-a-real-token',
                'signing_secret'     => SlackClickSimulator::SIGNING_SECRET,
                'team_id'            => FakeSlackApi::TEAM,
                'allow_email_lookup' => true,
            ]),
        ];

        $output->writeln(sprintf('Demo instance settings seeded (%d value(s) changed).', count($changed)));

        return Command::SUCCESS;
    }
}
