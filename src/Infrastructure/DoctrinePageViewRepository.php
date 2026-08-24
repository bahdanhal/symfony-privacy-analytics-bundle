<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Infrastructure;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\Entity\PageViewEntity;
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
        $entity = new PageViewEntity(
            $pageView->occurredAt,
            $pageView->visitorHash,
            $pageView->path,
            $pageView->source,
            $pageView->referrerHost
        );

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
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
