<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\DependencyInjection;

use Bahdan\PrivacyAnalyticsBundle\Application\TrafficAnalytics;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\EventSubscriber\PageViewSubscriber;
use Bahdan\PrivacyAnalyticsBundle\Infrastructure\DoctrinePageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\Infrastructure\JsonlPageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\MessageHandler\RecordPageViewHandler;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class PrivacyAnalyticsExtension extends Extension
{
    /** @param array<array-key, mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array{secret: string, storage: string, storage_directory: string, retention_days: int, async: bool, custom_bot_patterns: list<string>, summary_cache_ttl: int} $config */
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
            new Reference(CacheInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['summary_cache_ttl'],
        ]);
        $trafficAnalyticsDefinition->setAutowired(true);
        $trafficAnalyticsDefinition->setAutoconfigured(true);
        $trafficAnalyticsDefinition->setPublic(true);
        $container->setDefinition(TrafficAnalytics::class, $trafficAnalyticsDefinition);

        $subscriberDefinition = new Definition(PageViewSubscriber::class, [
            new Reference(PageViewRepository::class),
            $config['secret'],
            $config['custom_bot_patterns'],
            $config['async'] ? new Reference(MessageBusInterface::class) : null,
        ]);
        $subscriberDefinition->setAutowired(true);
        $subscriberDefinition->setAutoconfigured(true);
        $subscriberDefinition->addTag('kernel.event_subscriber');
        $container->setDefinition(PageViewSubscriber::class, $subscriberDefinition);

        if ($config['async']) {
            if (!interface_exists(MessageBusInterface::class)) {
                throw new \LogicException('Install symfony/messenger before enabling privacy_analytics.async.');
            }

            $handlerDefinition = new Definition(RecordPageViewHandler::class, [
                new Reference(PageViewRepository::class),
            ]);
            $handlerDefinition->setAutowired(true);
            $handlerDefinition->setAutoconfigured(true);
            $handlerDefinition->addTag('messenger.message_handler');
            $container->setDefinition(RecordPageViewHandler::class, $handlerDefinition);
        }
    }

    public function getAlias(): string
    {
        return 'privacy_analytics';
    }
}
