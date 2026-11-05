<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function strlen;

/**
 * Owner rule (contract §17): tests, demo and docs contain fake Slack values only. Fails on the shapes secret scanners
 * look for: real bot/user/app tokens, incoming-webhook URLs with real-looking ids, and 32-hex values next to
 * "signing".
 */
final class FixturesContainNoRealSecretsTest extends TestCase
{
    private const PATTERNS = [
        'slack token'       => '/xox[abpre]-\d{10,}-/',
        'app-level token'   => '/xapp-\d-[A-Z0-9]{6,}/',
        'webhook url'       => '#hooks\.slack\.com/services/T[A-Z0-9]{8,}/B[A-Z0-9]{8,}/[A-Za-z0-9]{24}#',
        'hex signing value' => '/signing[^\n]{0,40}[\'"=:\s][a-f0-9]{32}\b/i',
    ];

    public function testNoRealSecretShapes(): void
    {
        $root  = dirname(__DIR__, 3);
        $found = [];
        foreach (['tests', 'docs', 'demo', 'README.md', 'src'] as $entry) {
            foreach ($this->files($root . '/' . $entry) as $file) {
                $content = (string) file_get_contents($file);
                foreach (self::PATTERNS as $name => $pattern) {
                    if (preg_match($pattern, $content) === 1) {
                        $found[] = substr($file, strlen($root) + 1) . ': ' . $name;
                    }
                }
            }
        }

        self::assertSame([], $found);
    }

    /**
     * @return iterable<string>
     */
    private function files(string $path): iterable
    {
        if (is_file($path)) {
            yield $path;

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $name = $file->getPathname();
            if (!$file->isFile() || preg_match('#/(vendor|node_modules|var|\.git|test-results|playwright-report)/#', $name) === 1
                || preg_match('/\.(php|ya?ml|md|twig|ts|js|json|env|example|test|dist|xml|txt)$/', $name) !== 1 || str_ends_with($name, 'package-lock.json')) {
                continue;
            }
            yield $name;
        }
    }
}
