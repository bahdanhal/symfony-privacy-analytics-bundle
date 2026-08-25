<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Tests;

use Bahdan\PrivacyAnalyticsBundle\Application\TrafficAnalytics;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Infrastructure\JsonlPageViewRepository;
use PHPUnit\Framework\TestCase;

final class TrafficAnalyticsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/traffic-analytics-bundle-test-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function testSummarizesPageViewsVisitorsSourcesAndPaths(): void
    {
        $repository = new JsonlPageViewRepository($this->directory, 90);
        $now = new \DateTimeImmutable('2026-08-22 12:00:00 UTC');
        $repository->save(new PageView(
            $now->modify('-1 day'),
            'visitor-one',
            '/tools',
            'search',
            'google.com',
        ));
        $repository->save(new PageView(
            $now->modify('-1 hour'),
            'visitor-one',
            '/tools',
            'direct',
            null,
        ));
        $repository->save(new PageView(
            $now->modify('-10 days'),
            'visitor-two',
            '/',
            'referral',
            'example.com',
        ));

        $summary = (new TrafficAnalytics($repository))->summary($now);

        self::assertSame(2, $summary['last_7_days']['page_views']);
        self::assertSame(1, $summary['last_7_days']['unique_visitors']);
        self::assertSame(3, $summary['last_30_days']['page_views']);
        self::assertSame(2, $summary['last_30_days']['unique_visitors']);
        self::assertSame(2, $summary['last_30_days']['top_paths']['/tools']);
        self::assertSame(1, $summary['last_30_days']['referring_domains']['google.com']);
        self::assertCount(30, $summary['daily']);
    }

    public function testPartitionsFilesAndStreamsPruningAcrossLegacyStorage(): void
    {
        $repository = new JsonlPageViewRepository($this->directory, 30);
        $now = new \DateTimeImmutable('2026-03-15 12:00:00 UTC');
        $repository->save(new PageView($now->modify('-40 days'), 'old', '/old', 'direct', null));
        $repository->save(new PageView($now->modify('-1 day'), 'new', '/new', 'direct', null));

        file_put_contents(
            $this->directory . '/page-views.jsonl',
            json_encode((new PageView($now->modify('-2 days'), 'legacy', '/legacy', 'direct', null))->toArray()) . "\n",
        );

        self::assertFileExists($this->directory . '/page-views-2026-02.jsonl');
        self::assertFileExists($this->directory . '/page-views-2026-03.jsonl');
        self::assertCount(3, $repository->since($now->modify('-60 days')));
        self::assertSame(1, $repository->prune($now));

        $views = $repository->since($now->modify('-60 days'));
        self::assertCount(2, $views);
        self::assertSame(['/legacy', '/new'], array_map(static fn (PageView $view): string => $view->path, $views));
    }
}
