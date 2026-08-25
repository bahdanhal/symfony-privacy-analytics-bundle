<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Tests;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Entity\PageViewEntity;
use Bahdan\PrivacyAnalyticsBundle\Infrastructure\DoctrinePageViewRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class DoctrinePageViewRepositoryTest extends TestCase
{
    public function testSummaryAggregatesDailyMetricsInSql(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            paths: [dirname(__DIR__) . '/src/Entity'],
            isDevMode: true,
        );
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $configuration->enableNativeLazyObjects(PHP_VERSION_ID >= 80400);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $configuration);
        $entityManager = new EntityManager($connection, $configuration);
        (new SchemaTool($entityManager))->createSchema([
            $entityManager->getClassMetadata(PageViewEntity::class),
        ]);
        $indexes = $connection->createSchemaManager()->listTableIndexes('page_views');
        self::assertArrayHasKey('idx_page_views_occurred_at_source', $indexes);
        self::assertSame(['occurred_at', 'source'], $indexes['idx_page_views_occurred_at_source']->getColumns());
        self::assertArrayHasKey('idx_page_views_occurred_at_referrer', $indexes);
        self::assertSame(
            ['occurred_at', 'referrer_host'],
            $indexes['idx_page_views_occurred_at_referrer']->getColumns(),
        );
        $repository = new DoctrinePageViewRepository($entityManager);
        $now = new \DateTimeImmutable('2026-08-25 12:00:00+02:00');

        $repository->save(new PageView($now->modify('-1 hour'), 'visitor-one', '/one', 'direct', null));
        $repository->save(new PageView($now->modify('-30 minutes'), 'visitor-one', '/two', 'search', 'google.com'));
        $repository->save(new PageView($now->modify('-10 days'), 'visitor-two', '/old', 'direct', null));

        $summary = $repository->summary($now);
        $today = $summary['daily'][29];

        self::assertSame($now->format('Y-m-d'), $today['date']);
        self::assertSame(2, $today['page_views']);
        self::assertSame(1, $today['unique_visitors']);
        self::assertSame(3, $summary['last_30_days']['page_views']);
        self::assertSame(2, $summary['last_30_days']['unique_visitors']);

        $entityManager->close();
    }
}
