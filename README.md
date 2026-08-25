# Symfony Privacy Analytics Bundle

A lightweight, zero-cookie, GDPR-compliant server-side web analytics bundle for Symfony applications.

[Packagist](https://packagist.org/packages/bahdan/symfony-privacy-analytics-bundle) · [GitHub](https://github.com/bahdanhal/symfony-privacy-analytics-bundle)

## Key Features

- **Zero Cookies / No Consent Banner Needed**: Anonymously hashes `IP + User-Agent + secret` with HMAC-SHA256.
- **Bot & Crawler Filtering**: Ignores automated crawlers, spiders, and testing clients automatically.
- **Privacy Header Compliance**: Honors `DNT` (Do Not Track) and `Sec-GPC` (Global Privacy Control) request headers.
- **Referrer Categorization**: Automatically groups incoming traffic into `search`, `social`, `referral`, `internal`, and `direct`.
- **Dual Storage**: Supports Doctrine ORM (PostgreSQL/SQLite/MySQL) and monthly partitioned JSONL storage with streaming retention pruning.
- **SQL Aggregation**: Doctrine summaries aggregate counts, unique visitors, sources, referrers, paths, and daily metrics in the database rather than hydrating raw page views.
- **Cached Summaries**: Dashboard aggregates use the application's PSR-6-compatible Symfony cache for a configurable short TTL.
- **Optional Async Ingestion**: Symfony Messenger can move writes off the request worker for traffic spikes.
- **Extensible Bot Filtering**: Add project-specific user-agent substrings without forking the bundle.

## Installation

```bash
composer require bahdan/symfony-privacy-analytics-bundle
```

Doctrine is optional. Install it only when selecting the Doctrine storage backend:

```bash
composer require doctrine/orm doctrine/dbal
```

The JSONL backend does not register or resolve Doctrine services. Existing `page-views.jsonl` files remain readable while new events are written to monthly `page-views-YYYY-MM.jsonl` partitions.

Doctrine entity mappings are registered automatically. Your application still owns schema migrations; generate and review a migration after enabling Doctrine storage.

## Configuration

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Bahdan\PrivacyAnalyticsBundle\PrivacyAnalyticsBundle::class => ['all' => true],
];
```

Configure the storage and privacy secret:

```yaml
# config/packages/privacy_analytics.yaml
privacy_analytics:
  secret: '%kernel.secret%'
  storage: doctrine
  retention_days: 90
  summary_cache_ttl: 60
  custom_bot_patterns:
    - your-internal-monitor
```

## High-traffic ingestion with Messenger

Install Messenger, enable async ingestion, and route the bundle message to your asynchronous transport:

```bash
composer require symfony/messenger
```

```yaml
# config/packages/privacy_analytics.yaml
privacy_analytics:
  async: true

# config/packages/messenger.yaml
framework:
  messenger:
    transports:
      analytics: '%env(MESSENGER_TRANSPORT_DSN)%'
    routing:
      Bahdan\PrivacyAnalyticsBundle\Message\RecordPageView: analytics
```

Run a normal Messenger worker for the `analytics` transport. With Doctrine storage, dashboard summaries remain database-side aggregate queries. JSONL summaries stream only partitions that can overlap the requested period; Doctrine is recommended for sustained high traffic.

## Dashboard data

Inject `Bahdan\PrivacyAnalyticsBundle\Application\TrafficAnalytics` into a controller and return `summary()` as JSON or render it in Twig. The stable result contains seven-day and thirty-day totals, sources, referring domains, top paths, and a 30-day daily series, so applications can use Chart.js or their existing design system without the bundle imposing frontend dependencies.

```php
#[Route('/admin/analytics', methods: ['GET'])]
public function analytics(TrafficAnalytics $analytics): JsonResponse
{
    return $this->json($analytics->summary(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
}
```

## License

MIT
