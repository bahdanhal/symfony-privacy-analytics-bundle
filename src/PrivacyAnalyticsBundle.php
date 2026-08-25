<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle;

use Bahdan\PrivacyAnalyticsBundle\DependencyInjection\PrivacyAnalyticsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class PrivacyAnalyticsBundle extends AbstractBundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new PrivacyAnalyticsExtension();
    }
}
