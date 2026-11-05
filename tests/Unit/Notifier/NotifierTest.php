<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Notifier;

use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Notifier\SlackKitOptions;
use Nowo\SlackKitBundle\Notifier\SlackKitTransportFactory;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\RecordingApi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Notifier\Exception\IncompleteDsnException;
use Symfony\Component\Notifier\Exception\RuntimeException;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\Dsn;

final class NotifierTest extends TestCase
{
    public function testSendsToAChannelOrAMemberDm(): void
    {
        $api       = new RecordingApi();
        $transport = (new SlackKitTransportFactory($api))->create(new Dsn('nowo-slack://default?app=default&team=' . Fake::TEAM));
        self::assertSame('nowo-slack://default?app=default&team=' . Fake::TEAM, (string) $transport);

        $sent = $transport->send(new ChatMessage('Deploy done', new SlackKitOptions(SlackChannelId::fromString(Fake::CHANNEL), [Blocks::section('Deploy done')])));
        self::assertSame(Fake::CHANNEL . ':1.000100', $sent?->getMessageId());
        $transport->send(new ChatMessage('Hi', new SlackKitOptions(SlackUserId::fromString(Fake::USER), threadTs: '1.0')));

        $transport->send(new ChatMessage('<!channel> <https://evil.example|Reset>', new SlackKitOptions(SlackChannelId::fromString(Fake::CHANNEL))));
        self::assertSame(['chat.postMessage', 'conversations.open', 'chat.postMessage', 'chat.postMessage'], $api->methods());
        $sentMessage = $api->calls[3][1][1] ?? null;
        self::assertInstanceOf(SlackMessage::class, $sentMessage);
        self::assertSame('&lt;!channel&gt; &lt;https://evil.example|Reset&gt;', $sentMessage->toArguments()['text'], 'subject escaped (FS-L3)');

        $options = new SlackKitOptions(SlackUserId::fromString(Fake::USER));
        self::assertSame(Fake::USER, $options->getRecipientId());
        self::assertSame(['recipient' => Fake::USER, 'blocks' => [], 'thread_ts' => null], $options->toArray());
        self::assertSame('nowo-slack://default?app=ops', (string) (new SlackKitTransportFactory($api, 'ops'))->create(new Dsn('nowo-slack://default')));
    }

    public function testRefusals(): void
    {
        $api       = new RecordingApi();
        $factory   = new SlackKitTransportFactory($api);
        $transport = $factory->create(new Dsn('nowo-slack://default'));
        self::assertFalse($transport->supports(new SmsMessage('+15550100123', 'x')));
        self::assertFalse($transport->supports(new ChatMessage('x')));
        self::assertFalse($transport->supports((new ChatMessage('x', new SlackKitOptions(SlackChannelId::fromString(Fake::CHANNEL))))->transport('other')));

        try {
            $transport->send(new ChatMessage('x'));
            self::fail('accepted');
        } catch (UnsupportedMessageTypeException) {
        }
        $api->fail = 'channel_not_found';
        try {
            $transport->send(new ChatMessage('x', new SlackKitOptions(SlackChannelId::fromString(Fake::CHANNEL))));
            self::fail('accepted');
        } catch (RuntimeException $e) {
            self::assertSame('Slack refused the message: channel_not_found.', $e->getMessage());
        }

        foreach (['nowo-slack://' . Fake::TOKEN . '@default', 'nowo-slack://other', 'nowo-slack://default?app=Bad%20App', 'nowo-slack://default?team=nope'] as $dsn) {
            try {
                $factory->create(new Dsn($dsn));
                self::fail('accepted ' . $dsn);
            } catch (IncompleteDsnException $e) {
                self::assertStringNotContainsString(Fake::TOKEN, $e->getMessage());
            }
        }
        $this->expectException(UnsupportedSchemeException::class);
        $factory->create(new Dsn('slack://default'));
    }
}
