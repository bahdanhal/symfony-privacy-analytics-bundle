<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Message;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;

final readonly class RecordPageView
{
    public function __construct(
        public string $occurredAt,
        public string $visitorHash,
        public string $path,
        public string $source,
        public ?string $referrerHost,
    ) {
    }

    public static function fromPageView(PageView $pageView): self
    {
        return new self(
            $pageView->occurredAt->format(DATE_ATOM),
            $pageView->visitorHash,
            $pageView->path,
            $pageView->source,
            $pageView->referrerHost,
        );
    }

    public function toPageView(): PageView
    {
        return new PageView(
            new \DateTimeImmutable($this->occurredAt),
            $this->visitorHash,
            $this->path,
            $this->source,
            $this->referrerHost,
        );
    }
}
