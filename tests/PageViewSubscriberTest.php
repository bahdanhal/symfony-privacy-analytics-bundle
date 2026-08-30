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
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

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

            public function summary(\DateTimeImmutable $now): array
            {
                return [];
            }
        };

        $subscriber = new PageViewSubscriber($repository, 'secret-key-123');

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://bahdanhal.pl/tools', 'GET');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $request->headers->set('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
        $response = new Response('<html>OK</html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onTerminate($event);

        self::assertCount(1, $repository->saved);
        self::assertSame('/tools', $repository->saved[0]->path);
        self::assertSame('direct', $repository->saved[0]->source);
    }

    public function testClassifiesBaiduAndSearchEnginesAsSearchSource(): void
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

            public function summary(\DateTimeImmutable $now): array
            {
                return [];
            }
        };

        $subscriber = new PageViewSubscriber($repository, 'secret-key-123');
        $kernel = $this->createStub(HttpKernelInterface::class);

        $request = Request::create('https://stackhal.com/', 'GET', server: ['HTTP_REFERER' => 'https://www.baidu.com/s?wd=stackhal']);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
        $request->headers->set('Accept-Language', 'zh-CN,zh;q=0.9,en;q=0.8');
        $request->headers->set('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
        $response = new Response('<html>OK</html>', 200, ['Content-Type' => 'text/html']);

        $subscriber->onTerminate(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertCount(1, $repository->saved);
        self::assertSame('search', $repository->saved[0]->source);
        self::assertSame('baidu.com', $repository->saved[0]->referrerHost);
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

            public function summary(\DateTimeImmutable $now): array
            {
                return [];
            }
        };

        $subscriber = new PageViewSubscriber($repository, 'secret-key-123');

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://bahdanhal.pl' . $uri, 'GET');
        $request->headers->remove('Accept-Language');
        $request->headers->remove('Accept');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $response = new Response('<html>OK</html>', 200, ['Content-Type' => 'text/html']);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onTerminate($event);

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
        yield 'headless chrome browser' => ['/tools', [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/108.0.5359.71 Safari/537.36',
        ]];
        yield 'puppeteer automation' => ['/tools', [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Puppeteer/19.0.0 Safari/537.36',
        ]];
        yield 'do not track' => ['/tools', ['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'en-US', 'DNT' => '1']];
        yield 'global privacy control' => ['/tools', ['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'en-US', 'Sec-GPC' => '1']];
        yield 'WordPress probe' => ['/wp-content/themes/index.php', ['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'en-US']];
        yield 'environment file probe' => ['/.env', ['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'en-US']];
        yield 'spoofed browser missing accept-language' => ['/tools', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/114.0.0.0 Safari/537.36']];
        yield 'spoofed browser with wildcard accept' => ['/tools', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/114.0.0.0 Safari/537.36', 'Accept-Language' => 'en-US', 'Accept' => '*/*']];
    }

    public function testAllowsStandardChromiumUserAgent(): void
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

            public function summary(\DateTimeImmutable $now): array
            {
                return [];
            }
        };

        $subscriber = new PageViewSubscriber($repository, 'secret-key-123');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://bahdanhal.pl/tools', 'GET');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $request->headers->set('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
        $response = new Response('<html>OK</html>', 200, ['Content-Type' => 'text/html']);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onTerminate($event);

        self::assertCount(1, $repository->saved);
    }

    public function testAllowsLegacyMobileBrowserUserAgent(): void
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

            public function summary(\DateTimeImmutable $now): array
            {
                return [];
            }
        };
        $subscriber = new PageViewSubscriber($repository, 'secret-key-123');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://bahdanhal.pl/tools', 'GET');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $request->headers->set('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
        $response = new Response('<html>OK</html>', 200, ['Content-Type' => 'text/html']);

        $subscriber->onTerminate(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertCount(1, $repository->saved);
    }

    public function testCustomBotPatternExcludesMatchingUserAgent(): void
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

            public function summary(\DateTimeImmutable $now): array
            {
                return [];
            }
        };
        $subscriber = new PageViewSubscriber($repository, 'secret-key-123', ['CompanyHealthCheck']);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://bahdanhal.pl/tools', 'GET');
        $request->headers->set('User-Agent', 'Mozilla/5.0 CompanyHealthCheck/1.0');
        $response = new Response('<html>OK</html>', 200, ['Content-Type' => 'text/html']);

        $subscriber->onTerminate(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertCount(0, $repository->saved);
    }

    public function testDependencyInjectionExtension(): void
    {
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $extension = new \Bahdan\PrivacyAnalyticsBundle\DependencyInjection\PrivacyAnalyticsExtension();
        $extension->load([
            [
                'secret' => 'test-secret',
                'storage' => 'jsonl',
                'storage_directory' => '/tmp/analytics',
                'retention_days' => 60,
            ],
        ], $container);

        self::assertTrue($container->hasDefinition(\Bahdan\PrivacyAnalyticsBundle\Application\TrafficAnalytics::class));
        self::assertTrue($container->hasDefinition(\Bahdan\PrivacyAnalyticsBundle\EventSubscriber\PageViewSubscriber::class));
        self::assertTrue($container->hasAlias(\Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository::class));
        self::assertFalse($container->hasDefinition(\Bahdan\PrivacyAnalyticsBundle\Infrastructure\DoctrinePageViewRepository::class));
        $container->compile();
    }

    public function testBundlePrependsDoctrineMappingWhenDoctrineIsAvailable(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'doctrine';
            }
        });

        (new \Bahdan\PrivacyAnalyticsBundle\PrivacyAnalyticsBundle())->prepend($container);

        $config = $container->getExtensionConfig('doctrine')[0];
        self::assertSame('attribute', $config['orm']['mappings']['PrivacyAnalyticsBundle']['type']);
        self::assertSame(
            'Bahdan\\PrivacyAnalyticsBundle\\Entity',
            $config['orm']['mappings']['PrivacyAnalyticsBundle']['prefix'],
        );
    }
}
