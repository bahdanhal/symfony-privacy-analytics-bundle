<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Tests;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\EventSubscriber\PageViewSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PageViewSubscriberTest extends TestCase
{
    public function testRecordsMainHtmlGetRequests(): void
    {
        $repository = new class implements PageViewRepository {
            /** @var list<PageView> */
            public array $saved = [];

            public function save(PageView $pageView): void
            {
                $this->saved[] = $pageView;
            }

            public function since(\DateTimeImmutable $since): array
            {
                return $this->saved;
            }

            public function prune(\DateTimeImmutable $now): int
            {
                return 0;
            }
        };

        $subscriber = new PageViewSubscriber($repository, 'secret-key-123');

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://bahdanhal.pl/tools', 'GET');
        $request->headers->set('User-Agent', 'Mozilla/5.0');
        $response = new Response('<html>OK</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onResponse($event);

        self::assertCount(1, $repository->saved);
        self::assertSame('/tools', $repository->saved[0]->path);
        self::assertSame('direct', $repository->saved[0]->source);
    }

    /**
     * @param array<string, string> $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideExcludedRequests')]
    public function testIgnoresAutomatedAndPrivacyRequests(string $uri, array $headers): void
    {
        $repository = new class implements PageViewRepository {
            /** @var list<PageView> */
            public array $saved = [];

            public function save(PageView $pageView): void
            {
                $this->saved[] = $pageView;
            }

            public function since(\DateTimeImmutable $since): array
            {
                return $this->saved;
            }

            public function prune(\DateTimeImmutable $now): int
            {
                return 0;
            }
        };

        $subscriber = new PageViewSubscriber($repository, 'secret-key-123');

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://bahdanhal.pl' . $uri, 'GET');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = new Response('<html>OK</html>', 200, ['Content-Type' => 'text/html']);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onResponse($event);

        self::assertCount(0, $repository->saved);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function provideExcludedRequests(): iterable
    {
        yield 'known search bot' => ['/tools', ['User-Agent' => 'Googlebot/2.1']];
        yield 'missing user agent' => ['/tools', ['User-Agent' => '']];
        yield 'internal audit crawler' => ['/tools', ['User-Agent' => 'BahdanToolbox/1.0']];
        yield 'google inspection crawler' => ['/tools', ['User-Agent' => 'Google-InspectionTool/1.0']];
        yield 'generic CMS scanner' => ['/tools', ['User-Agent' => 'Mozilla/5.0 CMS-Checker/1.0']];
        yield 'domain intelligence collector' => ['/tools', ['User-Agent' => 'DomainIntelCollector/1.0']];
        yield 'email scraper' => ['/tools', ['User-Agent' => 'SparixEmailScraper/1.0']];
        yield 'WordPress safety scanner' => ['/tools', ['User-Agent' => 'WP-Safe-Scanner/1.0']];
        yield 'internet measurement scanner' => ['/tools', ['User-Agent' => 'InternetMeasurement/1.0']];
        yield 'known synthetic iPhone signature' => ['/tools', [
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15',
        ]];
        yield 'Chromium without browser navigation headers' => ['/tools', [
            'User-Agent' => 'Mozilla/5.0 Chrome/148.0.0.0 Safari/537.36',
        ]];
        yield 'do not track' => ['/tools', ['User-Agent' => 'Mozilla/5.0', 'DNT' => '1']];
        yield 'global privacy control' => ['/tools', ['User-Agent' => 'Mozilla/5.0', 'Sec-GPC' => '1']];
        yield 'WordPress probe' => ['/wp-content/themes/index.php', ['User-Agent' => 'Mozilla/5.0']];
        yield 'environment file probe' => ['/.env', ['User-Agent' => 'Mozilla/5.0']];
    }
}
