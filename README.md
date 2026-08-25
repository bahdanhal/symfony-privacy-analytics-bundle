# Symfony Privacy Analytics Bundle

A lightweight, zero-cookie, GDPR-compliant server-side web analytics bundle for Symfony applications.

## Key Features

- **Zero Cookies / No Consent Banner Needed**: Anonymously hashes `IP + User-Agent + secret` with HMAC-SHA256.
- **Bot & Crawler Filtering**: Ignores automated crawlers, spiders, and testing clients automatically.
- **Privacy Header Compliance**: Honors `DNT` (Do Not Track) and `Sec-GPC` (Global Privacy Control) request headers.
- **Referrer Categorization**: Automatically groups incoming traffic into `search`, `social`, `referral`, `internal`, and `direct`.
- **Dual Storage**: Supports Doctrine ORM (PostgreSQL/SQLite/MySQL) and monthly partitioned JSONL storage with streaming retention pruning.

## Installation

```bash
composer require bahdan/symfony-privacy-analytics-bundle
```

Doctrine is optional. Install it only when selecting the Doctrine storage backend:

```bash
composer require doctrine/orm doctrine/dbal
```

The JSONL backend does not register or resolve Doctrine services. Existing `page-views.jsonl` files remain readable while new events are written to monthly `page-views-YYYY-MM.jsonl` partitions.

## Configuration

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Bahdan\PrivacyAnalyticsBundle\PrivacyAnalyticsBundle::class => ['all' => true],
];
```

## License

MIT
