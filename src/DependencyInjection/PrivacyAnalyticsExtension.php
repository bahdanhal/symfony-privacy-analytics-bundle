<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\DependencyInjection;

use Bahdan\PrivacyAnalyticsBundle\Application\TrafficAnalytics;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\EventSubscriber\PageViewSubscriber;
use Bahdan\PrivacyAnalyticsBundle\Infrastructure\DoctrinePageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\Infrastructure\JsonlPageViewRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class PrivacyAnalyticsExtension extends Extension
{
    /** @param array<array-key, mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array{secret: string, storage: string, storage_directory: string, retention_days: int} $config */
        $config = $this->processConfiguration($configuration, $configs);

        $jsonlDefinition = new Definition(JsonlPageViewRepository::class, [
            $config['storage_directory'],
            $config['retention_days'],
        ]);
        $jsonlDefinition->setAutowired(true);
        $jsonlDefinition->setAutoconfigured(true);
        $container->setDefinition(JsonlPageViewRepository::class, $jsonlDefinition);

        $targetRepository = JsonlPageViewRepository::class;
        if ($config['storage'] === 'doctrine') {
            $doctrineDefinition = new Definition(DoctrinePageViewRepository::class, [
                new Reference('doctrine.orm.entity_manager'),
                $config['retention_days'],
            ]);
            $doctrineDefinition->setAutowired(true);
            $doctrineDefinition->setAutoconfigured(true);
            $container->setDefinition(DoctrinePageViewRepository::class, $doctrineDefinition);
            $targetRepository = DoctrinePageViewRepository::class;
        }

        $container->setAlias(PageViewRepository::class, $targetRepository)->setPublic(true);

        $trafficAnalyticsDefinition = new Definition(TrafficAnalytics::class, [
            new Reference(PageViewRepository::class),
        ]);
        $trafficAnalyticsDefinition->setAutowired(true);
        $trafficAnalyticsDefinition->setAutoconfigured(true);
        $trafficAnalyticsDefinition->setPublic(true);
        $container->setDefinition(TrafficAnalytics::class, $trafficAnalyticsDefinition);

        $subscriberDefinition = new Definition(PageViewSubscriber::class, [
            new Reference(PageViewRepository::class),
            $config['secret'],
        ]);
        $subscriberDefinition->setAutowired(true);
        $subscriberDefinition->setAutoconfigured(true);
        $subscriberDefinition->addTag('kernel.event_subscriber');
        $container->setDefinition(PageViewSubscriber::class, $subscriberDefinition);
    }

    public function getAlias(): string
    {
        return 'privacy_analytics';
    }
}
