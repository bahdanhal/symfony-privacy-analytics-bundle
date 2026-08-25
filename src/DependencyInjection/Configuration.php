<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('privacy_analytics');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('secret')
                    ->defaultValue('%kernel.secret%')
                    ->info('Secret salt used for visitor hashing.')
                ->end()
                ->enumNode('storage')
                    ->values(['doctrine', 'jsonl'])
                    ->defaultValue('doctrine')
                    ->info('Storage backend: doctrine or jsonl.')
                ->end()
                ->scalarNode('storage_directory')
                    ->defaultValue('%kernel.project_dir%/var/analytics-data')
                    ->info('Storage directory if jsonl backend is used.')
                ->end()
                ->integerNode('retention_days')
                    ->defaultValue(90)
                    ->min(1)
                    ->info('Retention period in days for page view logs.')
                ->end()
                ->booleanNode('async')
                    ->defaultFalse()
                    ->info('Dispatch page-view writes through Symfony Messenger.')
                ->end()
                ->arrayNode('custom_bot_patterns')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Additional case-insensitive user-agent substrings to exclude.')
                ->end()
                ->integerNode('summary_cache_ttl')
                    ->defaultValue(60)
                    ->min(0)
                    ->info('Seconds to cache dashboard summaries; set to zero to disable caching.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
