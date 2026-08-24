<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Application;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;

readonly class TrafficAnalytics
{
    public function __construct(private PageViewRepository $pageViews)
    {
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
        $thirtyDaysAgo = $now->modify('-30 days');
        $sevenDaysAgo = $now->modify('-7 days');
        $views = $this->pageViews->since($thirtyDaysAgo);

        return [
            'privacy' => 'Cookie-free aggregates. IP addresses, query strings and full referrers are never stored.',
            'last_7_days' => $this->period($views, $sevenDaysAgo),
            'last_30_days' => $this->period($views, $thirtyDaysAgo),
            'daily' => $this->daily($views, $now),
        ];
    }

    /**
     * @param list<PageView> $views
     * @return array{page_views: int, unique_visitors: int, sources: array<string, int>, referring_domains: array<string, int>, top_paths: array<string, int>}
     */
    private function period(array $views, \DateTimeImmutable $since): array
    {
        $periodViews = array_values(array_filter(
            $views,
            static fn (PageView $view): bool => $view->occurredAt >= $since,
        ));

        return [
            'page_views' => count($periodViews),
            'unique_visitors' => count(array_unique(array_map(
                static fn (PageView $view): string => $view->visitorHash,
                $periodViews,
            ))),
            'sources' => $this->frequencies(array_map(
                static fn (PageView $view): string => $view->source,
                $periodViews,
            )),
            'referring_domains' => $this->frequencies(array_values(array_filter(array_map(
                static fn (PageView $view): ?string => $view->referrerHost,
                $periodViews,
            )))),
            'top_paths' => $this->frequencies(array_map(
                static fn (PageView $view): string => $view->path,
                $periodViews,
            )),
        ];
    }

    /**
     * @param list<PageView> $views
     * @return list<array{date: string, page_views: int, unique_visitors: int}>
     */
    private function daily(array $views, \DateTimeImmutable $now): array
    {
        /** @var array<string, array{page_views: int, visitors: array<string, bool>}> $days */
        $days = [];
        for ($offset = 29; $offset >= 0; --$offset) {
            $date = $now->modify(sprintf('-%d days', $offset))->format('Y-m-d');
            $days[$date] = ['page_views' => 0, 'visitors' => []];
        }

        foreach ($views as $view) {
            $date = $view->occurredAt->format('Y-m-d');
            if (!isset($days[$date])) {
                continue;
            }
            ++$days[$date]['page_views'];
            $days[$date]['visitors'][$view->visitorHash] = true;
        }

        $result = [];
        foreach ($days as $date => $data) {
            $result[] = [
                'date' => $date,
                'page_views' => $data['page_views'],
                'unique_visitors' => count($data['visitors']),
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $items
     * @return array<string, int>
     */
    private function frequencies(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $counts[$item] = ($counts[$item] ?? 0) + 1;
        }
        arsort($counts);

        return array_slice($counts, 0, 10, true);
    }
}
