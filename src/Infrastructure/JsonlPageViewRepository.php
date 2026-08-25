<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Infrastructure;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;

final readonly class JsonlPageViewRepository implements PageViewRepository
{
    private string $filePath;

    public function __construct(
        string $directory,
        private int $retentionDays = 90,
    ) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
        $this->filePath = rtrim($directory, '/') . '/page-views.jsonl';
    }

    public function save(PageView $pageView): void
    {
        $line = json_encode($pageView->toArray(), JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
    }

    /** @return list<PageView> */
    public function since(\DateTimeImmutable $since): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $lines = @file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $views = [];
        foreach ($lines as $line) {
            /** @var array<string, mixed>|null $data */
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }
            try {
                $view = PageView::fromArray($data);
                if ($view->occurredAt >= $since) {
                    $views[] = $view;
                }
            } catch (\Throwable) {
                // Ignore malformed lines gracefully
            }
        }

        return $views;
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

        /** @var array<string, array{page_views: int, visitors: array<string, bool>}> $days */
        $days = [];
        for ($offset = 29; $offset >= 0; --$offset) {
            $date = $now->modify(sprintf('-%d days', $offset))->format('Y-m-d');
            $days[$date] = ['page_views' => 0, 'visitors' => []];
        }

        $p7 = ['views' => 0, 'visitors' => [], 'sources' => [], 'referrers' => [], 'paths' => []];
        $p30 = ['views' => 0, 'visitors' => [], 'sources' => [], 'referrers' => [], 'paths' => []];

        if (file_exists($this->filePath)) {
            $handle = @fopen($this->filePath, 'rb');
            if ($handle !== false) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    try {
                        /** @var array<string, mixed> $data */
                        $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        if (!is_array($data)) {
                            continue;
                        }
                        $occurredAt = new \DateTimeImmutable((string) ($data['timestamp'] ?? 'now'));
                        if ($occurredAt < $thirtyDaysAgo) {
                            continue;
                        }

                        $visitorHash = (string) ($data['visitor_hash'] ?? '');
                        $source = (string) ($data['source'] ?? 'direct');
                        $referrerHost = isset($data['referrer_host']) && is_string($data['referrer_host']) && $data['referrer_host'] !== '' ? $data['referrer_host'] : null;
                        $path = (string) ($data['path'] ?? '/');
                        $date = $occurredAt->format('Y-m-d');

                        // 30 days
                        ++$p30['views'];
                        $p30['visitors'][$visitorHash] = true;
                        $p30['sources'][$source] = ($p30['sources'][$source] ?? 0) + 1;
                        if ($referrerHost !== null) {
                            $p30['referrers'][$referrerHost] = ($p30['referrers'][$referrerHost] ?? 0) + 1;
                        }
                        $p30['paths'][$path] = ($p30['paths'][$path] ?? 0) + 1;

                        // 7 days
                        if ($occurredAt >= $sevenDaysAgo) {
                            ++$p7['views'];
                            $p7['visitors'][$visitorHash] = true;
                            $p7['sources'][$source] = ($p7['sources'][$source] ?? 0) + 1;
                            if ($referrerHost !== null) {
                                $p7['referrers'][$referrerHost] = ($p7['referrers'][$referrerHost] ?? 0) + 1;
                            }
                            $p7['paths'][$path] = ($p7['paths'][$path] ?? 0) + 1;
                        }

                        // Daily
                        if (isset($days[$date])) {
                            ++$days[$date]['page_views'];
                            $days[$date]['visitors'][$visitorHash] = true;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
                fclose($handle);
            }
        }

        $sortTop = static function (array $items): array {
            arsort($items);

            return array_slice($items, 0, 10, true);
        };

        $daily = [];
        foreach ($days as $date => $data) {
            $daily[] = [
                'date' => $date,
                'page_views' => $data['page_views'],
                'unique_visitors' => count($data['visitors']),
            ];
        }

        return [
            'privacy' => 'Cookie-free aggregates. IP addresses, query strings and full referrers are never stored.',
            'last_7_days' => [
                'page_views' => $p7['views'],
                'unique_visitors' => count($p7['visitors']),
                'sources' => $sortTop($p7['sources']),
                'referring_domains' => $sortTop($p7['referrers']),
                'top_paths' => $sortTop($p7['paths']),
            ],
            'last_30_days' => [
                'page_views' => $p30['views'],
                'unique_visitors' => count($p30['visitors']),
                'sources' => $sortTop($p30['sources']),
                'referring_domains' => $sortTop($p30['referrers']),
                'top_paths' => $sortTop($p30['paths']),
            ],
            'daily' => $daily,
        ];
    }

    public function prune(\DateTimeImmutable $now): int
    {
        if (!file_exists($this->filePath)) {
            return 0;
        }

        $cutoff = $now->modify(sprintf('-%d days', max(1, $this->retentionDays)));
        $lines = @file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return 0;
        }

        $retained = [];
        $prunedCount = 0;

        foreach ($lines as $line) {
            /** @var array<string, mixed>|null $data */
            $data = json_decode($line, true);
            if (!is_array($data)) {
                ++$prunedCount;
                continue;
            }
            try {
                $view = PageView::fromArray($data);
                if ($view->occurredAt >= $cutoff) {
                    $retained[] = $line;
                } else {
                    ++$prunedCount;
                }
            } catch (\Throwable) {
                ++$prunedCount;
            }
        }

        if ($prunedCount > 0) {
            $content = $retained === [] ? '' : implode("\n", $retained) . "\n";
            @file_put_contents($this->filePath, $content, LOCK_EX);
        }

        return $prunedCount;
    }
}
