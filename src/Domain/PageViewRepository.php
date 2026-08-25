<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Domain;

interface PageViewRepository
{
    public function save(PageView $pageView): void;

    /** @return list<PageView> */
    public function since(\DateTimeImmutable $since): array;

    public function prune(\DateTimeImmutable $now): int;

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
    public function summary(\DateTimeImmutable $now): array;
}
