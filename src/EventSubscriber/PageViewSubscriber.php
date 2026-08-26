<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\EventSubscriber;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\Message\RecordPageView;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class PageViewSubscriber implements EventSubscriberInterface
{
    private const array SEARCH_HOSTS = ['google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'yandex.', 'ecosia.org'];
    private const array SOCIAL_HOSTS = ['facebook.com', 'instagram.com', 'linkedin.com', 't.co', 'x.com', 'reddit.com'];

    public function __construct(
        private PageViewRepository $pageViews,
        private string $secret,
        private array $customBotPatterns = [],
        private ?MessageBusInterface $messageBus = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onTerminate', -10]];
    }

    public function onTerminate(KernelEvent $event): void
    {
        if (!$event instanceof TerminateEvent && !$event instanceof ResponseEvent) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $contentType = (string) $response->headers->get('Content-Type');

        if (
            !$event->isMainRequest()
            || !$request->isMethod('GET')
            || $response->getStatusCode() < 200
            || $response->getStatusCode() >= 300
            || !str_starts_with($contentType, 'text/html')
            || $this->isExcluded($request)
        ) {
            return;
        }

        [$source, $referrerHost] = $this->source($request);
        $clientIp = $request->getClientIp() ?? 'unknown';
        $userAgent = (string) $request->headers->get('User-Agent');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dateSalt = $now->format('Y-m-d');

        $pageView = new PageView(
            $now,
            hash_hmac('sha256', $dateSalt . '|' . $clientIp . '|' . $userAgent, $this->secret),
            $this->normalizedPath($request->getPathInfo()),
            $source,
            $referrerHost,
        );

        if ($this->messageBus !== null) {
            $this->messageBus->dispatch(RecordPageView::fromPageView($pageView));

            return;
        }

        $this->pageViews->save($pageView);
    }

    private const string BOT_PATTERN = '/bot|crawler|spider|slurp|preview|facebookexternalhit'
        . '|googleother|google-inspectiontool|bahdantoolbox|cms-checker|crt-indexer'
        . '|domainintelcollector|sparixemailscraper|wp-safe-scanner|internetmeasurement'
        . '|curl|wget|python|guzzle|axios|go-http-client|postman|headless|httpclient|java|php'
        . '|headlesschrome|phantomjs|puppeteer|selenium|playwright/i';

    private const string PROBE_PATH_PATTERN = '#(?:^|/)(?:wp-admin|wp-content|wp-includes)(?:/|$)'
        . '|(?:^|/)(?:\.env|\.git)(?:/|$)|\.php(?:/|$)#i';

    private function isExcluded(Request $request): bool
    {
        $path = $request->getPathInfo();
        $requestUriPath = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        $userAgent = strtolower(trim((string) $request->headers->get('User-Agent')));

        return $userAgent === ''
            || str_starts_with($path, '/admin')
            || str_starts_with($path, '/mcp')
            || $path === '/healthz'
            || $request->headers->get('DNT') === '1'
            || $request->headers->get('Sec-GPC') === '1'
            || preg_match(self::PROBE_PATH_PATTERN, $requestUriPath) === 1
            || preg_match(self::BOT_PATTERN, $userAgent) === 1
            || $this->matchesCustomBotPattern($userAgent);
    }

    private function matchesCustomBotPattern(string $userAgent): bool
    {
        foreach ($this->customBotPatterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern !== '' && str_contains($userAgent, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{string, ?string} */
    private function source(Request $request): array
    {
        $referrer = (string) $request->headers->get('Referer');
        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        if ($host === '') {
            return ['direct', null];
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $requestHost = preg_replace('/^www\./', '', strtolower($request->getHost())) ?? strtolower($request->getHost());
        if ($host === $requestHost) {
            return ['internal', null];
        }
        if ($this->matchesHost($host, self::SEARCH_HOSTS)) {
            return ['search', $host];
        }
        if ($this->matchesHost($host, self::SOCIAL_HOSTS)) {
            return ['social', $host];
        }

        return ['referral', $host];
    }

    /** @param list<string> $patterns */
    private function matchesHost(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($host, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedPath(string $path): string
    {
        $clean = '/' . trim($path, '/');
        if ($clean === '//') {
            return '/';
        }

        return preg_replace('#/+#', '/', $clean) ?: '/';
    }
}
