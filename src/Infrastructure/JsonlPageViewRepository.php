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
