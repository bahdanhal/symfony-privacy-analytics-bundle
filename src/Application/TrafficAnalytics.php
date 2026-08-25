<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Application;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

readonly class TrafficAnalytics
{
    public function __construct(
        private PageViewRepository $pageViews,
        private ?CacheInterface $cache = null,
        private int $cacheTtl = 60,
    ) {
    }

    /**
     * @return array{
     *     privacy: string,
     *     last_7_days: array{
     *         page_views: int,
     *         unique_visitors: int,
     *         sources: array<string, int>,
     *         referring_domains: array<string, int>,
     *         top_paths: array<string, int>
     *     },
     *     last_30_days: array{
     *         page_views: int,
     *         unique_visitors: int,
     *         sources: array<string, int>,
     *         referring_domains: array<string, int>,
     *         top_paths: array<string, int>
     *     },
     *     daily: list<array{date: string, page_views: int, unique_visitors: int}>
     * }
     */
    public function summary(\DateTimeImmutable $now): array
    {
        if ($this->cache === null || $this->cacheTtl <= 0) {
            return $this->pageViews->summary($now);
        }

        $key = 'privacy_analytics.summary.' . $now->format('Y-m-d-H-i');

        /** @var array<string, mixed> $summary */
        $summary = $this->cache->get($key, function (ItemInterface $item) use ($now): array {
            $item->expiresAfter($this->cacheTtl);

            return $this->pageViews->summary($now);
        });

        return $summary;
    }
}
