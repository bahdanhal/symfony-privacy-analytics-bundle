<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Infrastructure;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\Entity\PageViewEntity;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePageViewRepository implements PageViewRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private int $retentionDays = 90,
    ) {
    }

    public function save(PageView $pageView): void
    {
        $this->entityManager->getConnection()->insert('page_views', [
            'occurred_at' => $pageView->occurredAt->setTimezone(new \DateTimeZone('UTC')),
            'visitor_hash' => $pageView->visitorHash,
            'path' => $pageView->path,
            'source' => $pageView->source,
            'referrer_host' => $pageView->referrerHost,
        ], [
            'occurred_at' => Types::DATETIMETZ_IMMUTABLE,
        ]);
    }

    /** @return list<PageView> */
    public function since(\DateTimeImmutable $since): array
    {
        $repository = $this->entityManager->getRepository(PageViewEntity::class);
        $qb = $repository->createQueryBuilder('p');
        $qb->where('p.occurredAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('p.occurredAt', 'ASC');

        /** @var list<PageViewEntity> $entities */
        $entities = $qb->getQuery()->getResult();

        return array_map(
            static fn (PageViewEntity $entity): PageView => new PageView(
                $entity->getOccurredAt(),
                $entity->getVisitorHash(),
                $entity->getPath(),
                $entity->getSource(),
                $entity->getReferrerHost()
            ),
            $entities
        );
    }

    /**
     * @return array{
     *     privacy: string,
     *     last_7_days: array{
     *         page_views: int,
     *         unique_visitors: int,
     *         sources: array<string, int>,
     *         referring_domains: array<string, int>,
     *         top_paths: array<string, int>
     *     },
     *     last_30_days: array{
     *         page_views: int,
     *         unique_visitors: int,
     *         sources: array<string, int>,
     *         referring_domains: array<string, int>,
     *         top_paths: array<string, int>
     *     },
     *     daily: list<array{date: string, page_views: int, unique_visitors: int}>
     * }
     */
    public function summary(\DateTimeImmutable $now): array
    {
        $thirtyDaysAgo = $now->modify('-30 days');
        $sevenDaysAgo = $now->modify('-7 days');

        return [
            'privacy' => 'Cookie-free aggregates. IP addresses, query strings and full referrers are never stored.',
            'last_7_days' => $this->aggregatePeriod($sevenDaysAgo),
            'last_30_days' => $this->aggregatePeriod($thirtyDaysAgo),
            'daily' => $this->aggregateDaily($now, $thirtyDaysAgo),
        ];
    }

    /**
     * @return array{page_views: int, unique_visitors: int, sources: array<string, int>, referring_domains: array<string, int>, top_paths: array<string, int>}
     */
    private function aggregatePeriod(\DateTimeImmutable $since): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        /** @var array{total_views?: mixed, unique_visitors?: mixed} $counts */
        $counts = $qb->select('COUNT(p.id) as total_views', 'COUNT(DISTINCT p.visitorHash) as unique_visitors')
            ->from(PageViewEntity::class, 'p')
            ->where('p.occurredAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        $pageViews = (int) ($counts['total_views'] ?? 0);
        $uniqueVisitors = (int) ($counts['unique_visitors'] ?? 0);

        /** @var list<array{source: string, cnt: mixed}> $sourcesResult */
        $sourcesResult = $this->entityManager->createQueryBuilder()
            ->select('p.source', 'COUNT(p.id) as cnt')
            ->from(PageViewEntity::class, 'p')
            ->where('p.occurredAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('p.source')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $sources = [];
        foreach ($sourcesResult as $row) {
            $src = trim((string) $row['source']);
            if ($src !== '') {
                $sources[$src] = (int) $row['cnt'];
            }
        }

        /** @var list<array{referrerHost: ?string, cnt: mixed}> $referrersResult */
        $referrersResult = $this->entityManager->createQueryBuilder()
            ->select('p.referrerHost', 'COUNT(p.id) as cnt')
            ->from(PageViewEntity::class, 'p')
            ->where('p.occurredAt >= :since')
            ->andWhere('p.referrerHost IS NOT NULL')
            ->andWhere("p.referrerHost != ''")
            ->setParameter('since', $since)
            ->groupBy('p.referrerHost')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $referringDomains = [];
        foreach ($referrersResult as $row) {
            $ref = trim((string) ($row['referrerHost'] ?? ''));
            if ($ref !== '') {
                $referringDomains[$ref] = (int) $row['cnt'];
            }
        }

        /** @var list<array{path: string, cnt: mixed}> $pathsResult */
        $pathsResult = $this->entityManager->createQueryBuilder()
            ->select('p.path', 'COUNT(p.id) as cnt')
            ->from(PageViewEntity::class, 'p')
            ->where('p.occurredAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('p.path')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $topPaths = [];
        foreach ($pathsResult as $row) {
            $pth = trim((string) $row['path']);
            if ($pth !== '') {
                $topPaths[$pth] = (int) $row['cnt'];
            }
        }

        return [
            'page_views' => $pageViews,
            'unique_visitors' => $uniqueVisitors,
            'sources' => $sources,
            'referring_domains' => $referringDomains,
            'top_paths' => $topPaths,
        ];
    }

    /**
     * @return list<array{date: string, page_views: int, unique_visitors: int}>
     */
    private function aggregateDaily(\DateTimeImmutable $now, \DateTimeImmutable $thirtyDaysAgo): array
    {
        /** @var array<string, array{page_views: int, unique_visitors: int}> $days */
        $days = [];
        for ($offset = 29; $offset >= 0; --$offset) {
            $date = $now->modify(sprintf('-%d days', $offset))->format('Y-m-d');
            $days[$date] = ['page_views' => 0, 'unique_visitors' => 0];
        }

        $dateExpression = $this->entityManager->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? "DATE(occurred_at AT TIME ZONE 'UTC')"
            : 'DATE(occurred_at)';

        /** @var list<array{day: mixed, page_views: mixed, unique_visitors: mixed}> $records */
        $records = $this->entityManager->getConnection()->createQueryBuilder()
            ->select($dateExpression . ' AS day', 'COUNT(*) AS page_views', 'COUNT(DISTINCT visitor_hash) AS unique_visitors')
            ->from('page_views')
            ->where('occurred_at >= :since')
            ->setParameter(
                'since',
                $thirtyDaysAgo->setTimezone(new \DateTimeZone('UTC')),
                Types::DATETIMETZ_IMMUTABLE,
            )
            ->groupBy($dateExpression)
            ->orderBy('day', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($records as $record) {
            $date = substr((string) $record['day'], 0, 10);
            if (!isset($days[$date])) {
                continue;
            }
            $days[$date]['page_views'] = (int) $record['page_views'];
            $days[$date]['unique_visitors'] = (int) $record['unique_visitors'];
        }

        $result = [];
        foreach ($days as $date => $data) {
            $result[] = [
                'date' => $date,
                'page_views' => $data['page_views'],
                'unique_visitors' => $data['unique_visitors'],
            ];
        }

        return $result;
    }

    public function prune(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(sprintf('-%d days', max(1, $this->retentionDays)));

        return (int) $this->entityManager->createQueryBuilder()
            ->delete(PageViewEntity::class, 'p')
            ->where('p.occurredAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
