<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Interaction;

use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Interaction\ButtonValue;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Nowo\SlackKitBundle\Interaction\InvalidButtonValueException;
use Nowo\SlackKitBundle\Interaction\LegacyValuePolicy;
use Nowo\SlackKitBundle\Tests\Support\Fake;
use Nowo\SlackKitBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function strlen;

/**
 * The normative `nsk1` format (contract §6.5) against the three test vectors of §6.5.9 and every negative check.
 */
final class ButtonValueCodecTest extends TestCase
{
    private const KEY_HEX = 'adbe8161531cc4b4d44580b25d84dac36fdde158cac87c7ccf2d74e179f37345';

    private const V1 = 'nsk1.eyJkIjp7ImQiOiIwMDAwMDAwMC0wMDAwLTQwMDAtODAwMC0wMDAwMDAwMDAwMDEiLCJpIjoiMDAwMDAwMDAtMDAwMC00MDAwLTgwMDAtMDAwMDAwMDAwMDAzIiwicCI6IjAwMDAwMDAwLTAwMDAtNDAwMC04MDAwLTAwMDAwMDAwMDAwMiJ9LCJleHAiOjE3OTg3NjE2MDB9.P35UhoiWA3ifD2JzwlJ_Nw';
    private const V2 = 'nsk1.eyJkIjp7ImFjYyI6IjAwMDAwMDAwLTAwMDAtNDAwMC04MDAwLTAwMDAwMDAwMDBhYSIsIm4iOiJBQUFBQUFBQUFBQUFBQUFBQUFBQUFBIiwidWlkIjoiVTAwMDAwMDAxIn0sImV4cCI6MTc2NDk3OTIwMCwidCI6IlQwMDAwMDAwMCJ9.cfaRwTAs5lgF5NiVsRTuww';
    private const V3 = 'nsk1.eyJkIjp7InAiOiIwMDAwMDAwMC0wMDAwLTQwMDAtODAwMC0wMDAwMDAwMDAwMDIiLCJ0IjoiNDIiLCJ0aXRsZSI6IkNhZsOpIMOxIn0sImV4cCI6MTc5ODc2MTYwMCwidCI6IlQwMDAwMDAwMCJ9.hjyO1_MFD5Dvbl_0i1Nlxw';

    private const D1 = ['d' => '00000000-0000-4000-8000-000000000001', 'i' => '00000000-0000-4000-8000-000000000003', 'p' => '00000000-0000-4000-8000-000000000002'];
    private const D2 = ['acc' => '00000000-0000-4000-8000-0000000000aa', 'n' => 'AAAAAAAAAAAAAAAAAAAAAA', 'uid' => 'U00000001'];
    private const D3 = ['p' => '00000000-0000-4000-8000-000000000002', 't' => '42', 'title' => 'Café ñ'];

    public function testDerivesTheVectorKey(): void
    {
        self::assertSame(self::KEY_HEX, bin2hex(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET)->reveal()));
    }

    public function testProducesTheThreeVectorsByteForByte(): void
    {
        $codec = $this->codec();

        // Keys are given out of order on purpose: canonical JSON sorts them.
        self::assertSame(self::V1, $codec->signUntil('beacon.issue.resolve', ['p' => self::D1['p'], 'i' => self::D1['i'], 'd' => self::D1['d']], null, 1_798_761_600));
        self::assertSame(self::V2, $codec->signUntil('nowo_slack_kit.link.confirm', ['uid' => self::D2['uid'], 'n' => self::D2['n'], 'acc' => self::D2['acc']], SlackTeamId::fromString(Fake::TEAM), 1_764_979_200));
        self::assertSame(self::V3, $codec->signUntil('beacon.task.done', ['title' => self::D3['title'], 't' => '42', 'p' => self::D3['p']], SlackTeamId::fromString(Fake::TEAM), 1_798_761_600));
        self::assertSame(232, strlen(self::V1));
        self::assertSame(204, strlen(self::V2));
        self::assertSame(176, strlen(self::V3));
    }

    public function testVerifiesVectorOneAndThree(): void
    {
        $codec = $this->codec();

        $one = $codec->verify('beacon.issue.resolve', self::V1, Fake::OTHER_TEAM);
        self::assertSame(self::D1, $one->data);
        self::assertSame(1_798_761_600, $one->expiresAt);
        self::assertNull($one->teamId, 'no t: any team (the destination secret scopes it)');

        $three = $codec->verify('beacon.task.done', self::V3, Fake::TEAM);
        self::assertSame(self::D3, $three->data);
        self::assertSame(Fake::TEAM, $three->teamId);
        self::assertSame('beacon.task.done', $three->actionId);
    }

    public function testVectorTwoHasAValidMacButIsExpired(): void
    {
        $this->assertReason(InvalidButtonValueException::EXPIRED, fn (): ButtonValue => $this->codec()->verify('nowo_slack_kit.link.confirm', self::V2, Fake::TEAM));
        // The same value verifies before its expiry.
        $earlier = new ButtonValueCodec(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), [], new MockClock('@1764979199'));
        self::assertSame(self::D2, $earlier->verify('nowo_slack_kit.link.confirm', self::V2, Fake::TEAM)->data);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string|null, 3: string}>
     */
    public static function negatives(): iterable
    {
        yield 'other action id' => ['beacon.issue.assign', self::V1, null, InvalidButtonValueException::BAD_MAC];
        yield 'payload changed' => ['beacon.issue.resolve', 'nsk1.fyJ' . substr(self::V1, 8), null, InvalidButtonValueException::BAD_MAC];
        yield 'other team' => ['beacon.task.done', self::V3, Fake::OTHER_TEAM, InvalidButtonValueException::TEAM_MISMATCH];
        yield 'no click team' => ['beacon.task.done', self::V3, null, InvalidButtonValueException::TEAM_MISMATCH];
        yield 'nsk2 prefix' => ['beacon.issue.resolve', 'nsk2' . substr(self::V1, 4), null, InvalidButtonValueException::MALFORMED];
        yield 'padded mac' => ['beacon.issue.resolve', self::V1 . '==', null, InvalidButtonValueException::MALFORMED];
        yield 'two parts' => ['beacon.issue.resolve', 'nsk1.' . substr(self::V1, 5, 100), null, InvalidButtonValueException::MALFORMED];
        yield 'bad action id' => ['Beacon Resolve', self::V1, null, InvalidButtonValueException::MALFORMED];
        yield 'payload length 1 mod 4' => ['a', 'nsk1.AAAAA.' . str_repeat('A', 22), null, InvalidButtonValueException::MALFORMED];
        yield 'non-canonical trailing bits' => ['a', 'nsk1.AB.' . str_repeat('A', 22), null, InvalidButtonValueException::MALFORMED];
    }

    #[DataProvider('negatives')]
    public function testRefuses(string $actionId, string $value, ?string $team, string $reason): void
    {
        $this->assertReason($reason, fn (): ButtonValue => $this->codec()->verify($actionId, $value, $team));
    }

    public function testRotationKeepsOldValuesValid(): void
    {
        $rotated = new ButtonValueCodec(ButtonValueCodec::deriveKey('other-secret-not-real'), [ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET)], new MockClock('@' . Fake::NOW));
        self::assertSame(self::D1, $rotated->verify('beacon.issue.resolve', self::V1, null)->data);

        $withoutPrevious = new ButtonValueCodec(ButtonValueCodec::deriveKey('other-secret-not-real'), [], new MockClock('@' . Fake::NOW));
        $this->assertReason(InvalidButtonValueException::BAD_MAC, static fn (): ButtonValue => $withoutPrevious->verify('beacon.issue.resolve', self::V1, null));
    }

    public function testSignUsesTheTtl(): void
    {
        $codec = new ButtonValueCodec(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), [], new MockClock('@' . Fake::NOW), 60);
        $value = $codec->sign('a.b', ['x' => 1, 'y' => true, 'z' => ['k' => ['m' => 'v']], 'e' => []]);
        $data  = $codec->verify('a.b', $value, null);
        self::assertSame(Fake::NOW + 60, $data->expiresAt);
        self::assertSame(['e' => [], 'x' => 1, 'y' => true, 'z' => ['k' => ['m' => 'v']]], $data->data);
        self::assertSame(Fake::NOW + 5, $codec->verify('a.b', $codec->sign('a.b', [], null, 5), null)->expiresAt);
    }

    /**
     * @return iterable<string, array{0: string, 1: array<mixed>}>
     */
    public static function unencodable(): iterable
    {
        yield 'list' => ['a', ['x' => [1, 2]]];
        yield 'float' => ['a', ['x' => 1.5]];
        yield 'null' => ['a', ['x' => null]];
        yield 'upper key' => ['a', ['X' => 'v']];
        yield 'too deep' => ['a', ['a' => ['b' => ['c' => ['d' => 'v']]]]];
        yield 'top-level list' => ['a', ['v']];
        yield 'bad action id' => ['A', []];
        yield 'too big' => ['a', ['x' => str_repeat('x', 1400)]];
        yield 'nested bad' => ['a', ['a' => ['B' => 1]]];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('unencodable')]
    public function testRefusesToSign(string $actionId, array $data): void
    {
        /* @var array<string, mixed> $data */
        $this->assertReason(InvalidButtonValueException::UNENCODABLE, fn (): string => $this->codec()->signUntil($actionId, $data, null, Fake::NOW + 10));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badPayloads(): iterable
    {
        yield 'not json' => ['{'];
        yield 'list' => ['[1]'];
        yield 'unknown key' => ['{"d":{},"exp":1898761600,"x":1}'];
        yield 'd not object' => ['{"d":"s","exp":1898761600}'];
        yield 'exp string' => ['{"d":{},"exp":"1"}'];
        yield 'bad team' => ['{"d":{},"exp":1898761600,"t":"x"}'];
        yield 'team null' => ['{"d":{},"exp":1898761600,"t":null}'];
        yield 'float in d' => ['{"d":{"a":1.5},"exp":1898761600}'];
        yield 'bad key in d' => ['{"d":{"A":1},"exp":1898761600}'];
        yield 'too deep' => ['{"d":{"a":{"b":{"c":{"d":1}}}},"exp":1898761600}'];
        yield 'bad nested' => ['{"d":{"a":{"b":null}},"exp":1898761600}'];
    }

    #[DataProvider('badPayloads')]
    public function testRefusesBadPayloadsWithAValidMac(string $json): void
    {
        $key     = ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET);
        $encoded = ButtonValueCodec::base64UrlEncode($json);
        $mac     = substr(hash_hmac('sha256', "nsk1\na\n" . $encoded, $key->reveal(), true), 0, 16);
        $this->assertReason(InvalidButtonValueException::BAD_PAYLOAD, fn (): ButtonValue => $this->codec()->verify('a', 'nsk1.' . $encoded . '.' . ButtonValueCodec::base64UrlEncode($mac), null));
    }

    public function testHelpers(): void
    {
        self::assertTrue(ButtonValueCodec::isSignedFamily('nsk9.x'));
        self::assertFalse(ButtonValueCodec::isSignedFamily('{"a":"resolve"}'));
        self::assertNull(ButtonValueCodec::base64UrlDecode('a+b'));
        self::assertSame('{"a":{"b":1},"c":"/é"}', ButtonValueCodec::canonicalJson(['c' => '/é', 'a' => ['b' => 1]]));
        self::assertSame('{"d":{},"exp":1}', ButtonValueCodec::canonicalJson(['exp' => 1, 'd' => []]));
    }

    private function codec(): ButtonValueCodec
    {
        return new ButtonValueCodec(ButtonValueCodec::deriveKey(Fake::KERNEL_SECRET), [], new MockClock('@' . Fake::NOW));
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertReason(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('Expected ' . $reason);
        } catch (InvalidButtonValueException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($reason, $e->getMessage(), 'the message is the code only, never the value');
        }
    }

    public function testWeakKernelSecretsAreLogged(): void
    {
        // Audit FS-I11.
        $logger = new RecordingLogger();
        ButtonValueCodec::deriveCheckedKey('change_this_secret_key_to_a_random_value', $logger);
        ButtonValueCodec::deriveCheckedKey('short', $logger);
        self::assertCount(2, $logger->records);
        self::assertSame('weak_kernel_secret', $logger->records[0]['context']['reason']);
        $strong = ButtonValueCodec::deriveCheckedKey('k9Q2vX7mP4tR8wZ1bN6cF3hJ5lD0sG2aE7uY', $logger);
        self::assertCount(2, $logger->records);
        self::assertSame(ButtonValueCodec::deriveKey('k9Q2vX7mP4tR8wZ1bN6cF3hJ5lD0sG2aE7uY')->reveal(), $strong->reveal());
    }

    public function testLegacyUntilIsAStrictUtcInstant(): void
    {
        // Audit FS-L4.
        self::assertSame(1796428800, LegacyValuePolicy::parseUntil('2026-12-05T00:00:00Z')?->getTimestamp());
        self::assertSame(1796428800, LegacyValuePolicy::parseUntil('2026-12-05T00:00:00+00:00')?->getTimestamp());
        foreach (['+30 days', 'tomorrow', '2026-12-05', '2026-12-05T00:00:00', '2026-12-05T00:00:00+01:00', '2026-13-45T00:00:00Z', '', null] as $value) {
            self::assertNull(LegacyValuePolicy::parseUntil($value), (string) $value);
        }
        $clock = new MockClock('@' . Fake::NOW);
        self::assertFalse((new LegacyValuePolicy('a', ['r' => 'x'], '+30 days', $clock))->isWindowOpen(), 'a relative env value turns legacy off');
        self::assertTrue((new LegacyValuePolicy('a', ['r' => 'x'], '2027-01-01T00:00:00Z', $clock))->isWindowOpen());
    }
}
