<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Linking;

use InvalidArgumentException;
use Nowo\SlackKitBundle\Api\ApiPolicy;
use Nowo\SlackKitBundle\Api\ClientMemory;
use Nowo\SlackKitBundle\Api\SlackApiClient;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Linking\ClickLinkSigner;
use Nowo\SlackKitBundle\Linking\SlackVerifiedEmailCheck;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function hash_hkdf;
use function hash_hmac;
use function json_encode;

/**
 * Spec 237 T000b pieces: link token parsing, `users.info` and the e-mail check value.
 */
final class ClickLinkPiecesTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badPayloads(): iterable
    {
        yield 'not json' => ['this is not json at all'];
        yield 'not an object' => ['"just a string value here"'];
        yield 'bad app' => [(string) json_encode(['a' => 'Bad App', 't' => Fake::TEAM, 'u' => Fake::USER, 'e' => Fake::NOW + 60, 'n' => 'nonce'])];
        yield 'no expiry' => [(string) json_encode(['a' => 'default', 't' => Fake::TEAM, 'u' => Fake::USER, 'n' => 'nonce'])];
        yield 'no nonce' => [(string) json_encode(['a' => 'default', 't' => Fake::TEAM, 'u' => Fake::USER, 'e' => Fake::NOW + 60])];
        yield 'bad team' => [(string) json_encode(['a' => 'default', 't' => 'nope', 'u' => Fake::USER, 'e' => Fake::NOW + 60, 'n' => 'nonce'])];
        yield 'bad user' => [(string) json_encode(['a' => 'default', 't' => Fake::TEAM, 'u' => 'nope', 'e' => Fake::NOW + 60, 'n' => 'nonce'])];
    }

    /**
     * A correctly signed payload that is not a valid token is refused too.
     */
    #[DataProvider('badPayloads')]
    public function testSignedButMalformedPayloadsAreRefused(string $json): void
    {
        $signer  = new ClickLinkSigner(ClickLinkSigner::deriveKey(Fake::KERNEL_SECRET), new MockClock('@' . Fake::NOW));
        $payload = ButtonValueCodec::base64UrlEncode($json);
        $mac     = ButtonValueCodec::base64UrlEncode(hash_hmac('sha256', $payload, hash_hkdf('sha256', Fake::KERNEL_SECRET, 32, 'nowo_slack_kit.link_click.v1', ''), true));

        self::assertNull($signer->verify($payload . '.' . $mac));
    }

    public function testAButtonKeyNeverSignsALinkToken(): void
    {
        $clock  = new MockClock('@' . Fake::NOW);
        $signer = new ClickLinkSigner(ClickLinkSigner::deriveKey(Fake::KERNEL_SECRET), $clock, 60);
        $token  = $signer->issue(new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM)), SlackTeamId::fromString(Fake::TEAM), SlackUserId::fromString(Fake::USER));
        self::assertSame(Fake::NOW + 60, $signer->verify($token)?->expiresAt);
        $buttons = new ClickLinkSigner(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), $clock);
        self::assertNull($buttons->verify($token), 'the key has its own HKDF info');
    }

    public function testUsersInfo(): void
    {
        $answers = [
            Fake::ok(['user' => ['id' => Fake::USER, 'name' => 'ana', 'is_bot' => false, 'profile' => ['display_name' => ' ', 'real_name' => 'Ana Real', 'email' => ' ana@example.test ']]]),
            Fake::ok(['user' => ['id' => Fake::USER, 'name' => 'ana', 'deleted' => true, 'profile' => []]]),
            Fake::ok(['user' => ['id' => Fake::USER, 'is_bot' => true]]),
            Fake::ok(['user' => ['id' => Fake::OTHER_USER]]),
        ];
        $bodies = [];
        $client = new SlackApiClient(new MockHttpClient(static function (string $method, string $url, array $options) use (&$answers, &$bodies): MockResponse {
            $bodies[] = $options['body'];

            return array_shift($answers) ?? Fake::error('no_more_answers');
        }), Fake::store(), new ApiPolicy(), new ClientMemory(new ArrayAdapter()), new MockClock('@' . Fake::NOW));
        $key  = new SlackInstallationKey('default', SlackTeamId::fromString(Fake::TEAM));
        $user = SlackUserId::fromString(Fake::USER);

        $profile = $client->userProfile($key, $user);
        self::assertSame('Ana Real', $profile->displayName);
        self::assertSame('ana@example.test', $profile->email);
        self::assertFalse($profile->bot);
        self::assertSame('user=' . Fake::USER, $bodies[0], 'form arguments');

        $deleted = $client->userProfile($key, $user);
        self::assertSame('ana', $deleted->displayName);
        self::assertTrue($deleted->deleted);
        self::assertNull($deleted->email);

        $bot = $client->userProfile($key, $user);
        self::assertSame(Fake::USER, $bot->displayName);
        self::assertTrue($bot->bot);

        $this->expectException(SlackApiException::class);
        $this->expectExceptionMessage('invalid_response');
        $client->userProfile($key, $user);
    }

    public function testEmailCheckValidation(): void
    {
        self::assertSame('ana@example.test', (new SlackVerifiedEmailCheck('a', " Ana@EXAMPLE.test\n"))->email);
        foreach (['', 'no-at-sign', str_repeat('a', 250) . '@x.test'] as $bad) {
            try {
                new SlackVerifiedEmailCheck('a', $bad);
                self::fail('accepted ' . $bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
