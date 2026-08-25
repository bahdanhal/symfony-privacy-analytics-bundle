<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle;

use Bahdan\PrivacyAnalyticsBundle\DependencyInjection\PrivacyAnalyticsExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class PrivacyAnalyticsBundle extends AbstractBundle implements PrependExtensionInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new PrivacyAnalyticsExtension();
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'PrivacyAnalyticsBundle' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__ . '/Entity',
                        'prefix' => 'Bahdan\\PrivacyAnalyticsBundle\\Entity',
                        'alias' => 'PrivacyAnalyticsBundle',
                    ],
                ],
            ],
        ]);
    }
}
