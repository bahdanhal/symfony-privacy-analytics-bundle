<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Domain;

interface PageViewRepository
{
    public function save(PageView $pageView): void;

    /** @return list<PageView> */
    public function since(\DateTimeImmutable $since): array;

    public function prune(\DateTimeImmutable $now): int;
}
