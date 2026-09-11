<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Tests;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\EventSubscriber\PageViewSubscriber;
use Bahdan\PrivacyAnalyticsBundle\Presentation\Http\BeaconController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BeaconControllerTest extends TestCase
{
    private function createRepository(): PageViewRepository
    {
        return new class implements PageViewRepository {
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
    }

    public function testHandlesOptionsCorsPreflight(): void
    {
        $repository = $this->createRepository();
        $subscriber = new PageViewSubscriber($repository, 'secret-123', beaconMode: true);
        $controller = new BeaconController($repository, 'secret-123', $subscriber);

        $request = Request::create('https://ileza.pl/api/pa/hit', 'OPTIONS');
        $response = $controller($request);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testRecordsValidBeaconPayload(): void
    {
        $repository = $this->createRepository();
        $subscriber = new PageViewSubscriber($repository, 'secret-123', beaconMode: true);
        $controller = new BeaconController($repository, 'secret-123', $subscriber);

        $request = Request::create(
            'https://ileza.pl/api/pa/hit',
            'POST',
            server: ['REMOTE_ADDR' => '93.159.13.50'],
            content: json_encode(['p' => '/ceny/macbook', 'r' => 'https://google.com/search?q=macbook'], JSON_THROW_ON_ERROR),
        );
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36');
        $request->headers->set('Accept-Language', 'pl-PL,pl;q=0.9');
        $request->headers->set('Accept', '*/*');
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Sec-Fetch-Mode', 'cors');
        $request->headers->set('Sec-CH-UA', '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152"');

        $response = $controller($request);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        /** @var mixed $repository */
        self::assertCount(1, $repository->saved);
        self::assertSame('/ceny/macbook', $repository->saved[0]->path);
        self::assertSame('search', $repository->saved[0]->source);
        self::assertSame('google.com', $repository->saved[0]->referrerHost);
    }

    public function testHonorsDoNotTrackHeader(): void
    {
        $repository = $this->createRepository();
        $subscriber = new PageViewSubscriber($repository, 'secret-123', beaconMode: true);
        $controller = new BeaconController($repository, 'secret-123', $subscriber);

        $request = Request::create(
            'https://ileza.pl/api/pa/hit',
            'POST',
            content: json_encode(['p' => '/ceny/macbook'], JSON_THROW_ON_ERROR),
        );
        $request->headers->set('DNT', '1');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

        $response = $controller($request);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        /** @var mixed $repository */
        self::assertCount(0, $repository->saved);
    }

    public function testExcludesStealthScrapersEvenIfPostingToBeacon(): void
    {
        $repository = $this->createRepository();
        $subscriber = new PageViewSubscriber($repository, 'secret-123', beaconMode: true);
        $controller = new BeaconController($repository, 'secret-123', $subscriber);

        $request = Request::create(
            'https://ileza.pl/api/pa/hit',
            'POST',
            content: json_encode(['p' => '/ceny/macbook'], JSON_THROW_ON_ERROR),
        );
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $request->headers->set('Sec-CH-UA', '"Chromium";v="143", "Google Chrome";v="143", "Not-A.Brand";v="99"');

        $response = $controller($request);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        /** @var mixed $repository */
        self::assertCount(0, $repository->saved);
    }

    public function testStripsQueryStringsFromBeaconPath(): void
    {
        $repository = $this->createRepository();
        $subscriber = new PageViewSubscriber($repository, 'secret-123', beaconMode: true);
        $controller = new BeaconController($repository, 'secret-123', $subscriber);

        $request = Request::create(
            'https://ileza.pl/api/pa/hit',
            'POST',
            content: json_encode(['p' => '/ceny/macbook?brand=apple&sort=price'], JSON_THROW_ON_ERROR),
        );
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $request->headers->set('Sec-Fetch-Mode', 'cors');
        $request->headers->set('Sec-CH-UA', '"Chromium";v="152", "Not?A_Brand";v="24", "Google Chrome";v="152"');

        $response = $controller($request);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        /** @var mixed $repository */
        self::assertCount(1, $repository->saved);
        self::assertSame('/ceny/macbook', $repository->saved[0]->path);
    }
}
