<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'page_views')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_page_views_occurred_at')]
#[ORM\Index(columns: ['path'], name: 'idx_page_views_path')]
class PageViewEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $visitorHash;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $path;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $source;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $referrerHost;

    public function __construct(
        \DateTimeImmutable $occurredAt,
        string $visitorHash,
        string $path,
        string $source,
        ?string $referrerHost = null
    ) {
        $this->occurredAt = $occurredAt;
        $this->visitorHash = $visitorHash;
        $this->path = $path;
        $this->source = $source;
        $this->referrerHost = $referrerHost;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getVisitorHash(): string
    {
        return $this->visitorHash;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getReferrerHost(): ?string
    {
        return $this->referrerHost;
    }
}
