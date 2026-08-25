<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\MessageHandler;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\Message\RecordPageView;

final readonly class RecordPageViewHandler
{
    public function __construct(private PageViewRepository $pageViews)
    {
    }

    public function __invoke(RecordPageView $message): void
    {
        $this->pageViews->save($message->toPageView());
    }
}
