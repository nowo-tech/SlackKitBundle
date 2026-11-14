<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Integration\Functional;

use DateTimeImmutable;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodecInterface;
use Nowo\SlackKitBundle\Interaction\ReplyDispatcher;
use Nowo\SlackKitBundle\Linking\SlackLink;
use Nowo\SlackKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\HostInstallationStore;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use Nowo\SlackKitBundle\Tests\Support\UpdatingHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function gc_collect_cycles;
use function json_decode;
use function memory_get_usage;
use function time;

/**
 * REQ-RUNTIME-002 / spec 237: FrankenPHP worker mode with the kernel kept alive and never reset. One kernel serves
 * clicks from two workspaces in turn, driven through `http_kernel` directly (the services resetter never runs). Every
 * click must act as its own workspace's account and update its message with its own workspace's token; nothing of the
 * previous request may leak into the next, and memory stays flat.
 */
final class MultiWorkspaceWorkerTest extends KernelTestCase
{
    /** Clicks per workspace (2 × 150 = 300 requests in one kernel). */
    private const ROUNDS = 150;

    public function testTwoWorkspacesAlternateInOneKernelWithoutLeaks(): void
    {
        $kernel = $this->bootKernel([
            'apps'        => ['default' => ['app_id' => Fake::APP_ID]],
            'credentials' => ['store' => 'test.host_store'],
            'linking'     => ['enabled' => true, 'identity_store' => 'test.identities', 'account_resolver' => 'test.accounts'],
        ]);
        $container = $this->container($kernel);
        $store     = $container->get('test.host_store');
        self::assertInstanceOf(HostInstallationStore::class, $store);
        $store->save(Fake::installation(Fake::TEAM, Fake::TOKEN));
        $store->save(Fake::installation(Fake::OTHER_TEAM, Fake::OTHER_TOKEN));
        $this->identities($kernel)->saveLink(new SlackLink('acct-a', SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER), new DateTimeImmutable('@1'), new DateTimeImmutable('@1')));
        $this->identities($kernel)->saveLink(new SlackLink('acct-b', SlackTeamId::fromString(Fake::OTHER_TEAM), SlackUserId::fromString(Fake::OTHER_USER), new DateTimeImmutable('@1'), new DateTimeImmutable('@1')));

        $httpKernel = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $httpKernel);
        $codec = $container->get(ButtonValueCodecInterface::class);
        self::assertInstanceOf(ButtonValueCodecInterface::class, $codec);
        $handler = $container->get('test.updating_handler');
        self::assertInstanceOf(UpdatingHandler::class, $handler);
        $replies = $container->get(ReplyDispatcher::class);
        self::assertInstanceOf(ReplyDispatcher::class, $replies);
        $logger = $container->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        $fake = $this->fakeSlack($kernel);

        $workspaces = [
            ['team' => Fake::TEAM, 'user' => Fake::USER, 'token' => Fake::TOKEN, 'account' => 'acct-a'],
            ['team' => Fake::OTHER_TEAM, 'user' => Fake::OTHER_USER, 'token' => Fake::OTHER_TOKEN, 'account' => 'acct-b'],
        ];
        $baseline = 0;
        for ($i = 0; $i < 2 * self::ROUNDS; ++$i) {
            $w                                  = $workspaces[$i % 2];
            $payload                            = Fake::blockActions('test.item.done', $codec->sign('test.item.done', ['item' => 'i-' . $i], SlackTeamId::fromString($w['team'])), $w['team'], $w['user']);
            $payload['actions'][0]['action_ts'] = '1793836801.' . $i;
            $payload['message']                 = ['text' => 'Item ' . $i, 'blocks' => [Blocks::section('Item ' . $i), Blocks::actions([Blocks::button('Done', 'test.item.done', 'v')])]];
            $body                               = Fake::form($payload);
            $request                            = Request::create('/slack/interactions', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + Fake::signed($body, time()), $body);

            $response = $httpKernel->handle($request);
            if ($httpKernel instanceof TerminableInterface) {
                $httpKernel->terminate($request, $response);
            }

            self::assertSame(200, $response->getStatusCode(), "Request {$i}: " . $response->getContent());
            self::assertSame(['team' => $w['team'], 'account' => $w['account'], 'installation' => 'default:' . $w['team']], $handler->seen[0], "Request {$i}: the actor and installation of its own workspace.");
            self::assertCount(1, $fake->calls, "Request {$i}: one chat.update, after the response.");
            self::assertSame('https://slack.com/api/chat.update', $fake->calls[0]['url']);
            self::assertSame('Authorization: Bearer ' . $w['token'], $fake->calls[0]['auth'], "Request {$i}: its own workspace's token.");
            $update = json_decode($fake->calls[0]['body'], true);
            self::assertIsArray($update);
            self::assertSame('Item ' . $i, $update['text']);
            self::assertCount(2, $update['blocks'], 'the spent button block is gone, the context line is added');
            self::assertSame('Done by ' . $w['account'], $update['blocks'][1]['elements'][0]['text']);
            self::assertSame(0, $replies->count(), 'nothing left queued for the next request');

            $handler->clear();
            $fake->calls     = [];
            $logger->records = [];
            if ($i === 20) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan($baseline + 2 * 1024 * 1024, memory_get_usage(), 'Memory stays flat over the requests.');
    }
}
