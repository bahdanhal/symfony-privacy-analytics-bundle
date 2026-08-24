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

    public function testIgnoresBotsAndDnt(): void
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
        $request->headers->set('User-Agent', 'Googlebot/2.1');
        $response = new Response('<html>OK</html>', 200, ['Content-Type' => 'text/html']);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $subscriber->onResponse($event);

        self::assertCount(0, $repository->saved);
    }
}
