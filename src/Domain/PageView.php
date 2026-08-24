<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Domain;

readonly class PageView
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public string $visitorHash,
        public string $path,
        public string $source,
        public ?string $referrerHost,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $referrerHost = isset($data['referrer_host']) && is_string($data['referrer_host']) && $data['referrer_host'] !== ''
            ? $data['referrer_host']
            : null;

        return new static(
            new \DateTimeImmutable((string) ($data['timestamp'] ?? 'now')),
            (string) ($data['visitor_hash'] ?? ''),
            (string) ($data['path'] ?? '/'),
            (string) ($data['source'] ?? 'direct'),
            $referrerHost,
        );
    }

    /** @return array{timestamp: string, visitor_hash: string, path: string, source: string, referrer_host: ?string} */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->occurredAt->format(DATE_ATOM),
            'visitor_hash' => $this->visitorHash,
            'path' => $this->path,
            'source' => $this->source,
            'referrer_host' => $this->referrerHost,
        ];
    }
}
