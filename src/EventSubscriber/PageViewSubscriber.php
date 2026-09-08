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
    private const array SEARCH_HOSTS = ['google.', 'bing.com', 'duckduckgo.com', 'baidu.', 'yahoo.', 'yandex.', 'ecosia.org', 'qwant.com', 'brave.com', 'seznam.cz', 'naver.com', 'sogou.com'];
    private const array SOCIAL_HOSTS = ['facebook.com', 'instagram.com', 'linkedin.com', 't.co', 'x.com', 'reddit.com', 'youtube.com', 'tiktok.com', 'threads.net', 'bsky.app'];

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
        . '|headlesschrome|phantomjs|puppeteer|selenium|playwright'
        . '|cl0q|palo alto networks|dalvik|wordpress|forestengine|leakix|l9scan|databot'
        . '|seranking|semrush|amazonbot|claudebot|chatgpt-user|ct-wp-scanner|publicwww'
        . '|iphone os 13_2_3 like mac os x|iphone os 26_3_0 like mac os x'
        . '|android 7\.0; sm-g892a|android 16; sm-s931b/i';

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
            || $this->matchesCustomBotPattern($userAgent)
            || $this->hasSpoofedBrowserHeaders($request, $userAgent);
    }

    private function hasSpoofedBrowserHeaders(Request $request, string $userAgent): bool
    {
        $isClaimingBrowser = str_contains($userAgent, 'mozilla/')
            || str_contains($userAgent, 'chrome/')
            || str_contains($userAgent, 'safari/')
            || str_contains($userAgent, 'firefox/')
            || str_contains($userAgent, 'edg/');

        if (!$isClaimingBrowser) {
            return false;
        }

        // 1. Every authentic browser navigation provides an Accept-Language header.
        $acceptLanguage = trim((string) $request->headers->get('Accept-Language'));
        if ($acceptLanguage === '') {
            return true;
        }

        // 2. Automated scanners claiming to be a browser often send "Accept: */*" or omit text/html.
        $accept = strtolower(trim((string) $request->headers->get('Accept')));
        if ($accept === '*/*') {
            return true;
        }

        // 3. Signed-Exchange mismatch: only Chromium browsers support SXG (application/signed-exchange).
        // Automated scrapers often send Chromium default Accept header while setting User-Agent to Firefox or Safari.
        if (str_contains($accept, 'application/signed-exchange')) {
            $isChromium = str_contains($userAgent, 'chrome/')
                || str_contains($userAgent, 'chromium/')
                || str_contains($userAgent, 'edg/');
            if (!$isChromium) {
                return true;
            }
        }

        // 4. Safari header integrity: authentic Safari requests never ask for image/apng or signed-exchange.
        $isPureSafari = str_contains($userAgent, 'safari/')
            && !str_contains($userAgent, 'chrome/')
            && !str_contains($userAgent, 'chromium/')
            && !str_contains($userAgent, 'edg/');
        if ($isPureSafari && (str_contains($accept, 'image/apng') || str_contains($accept, 'signed-exchange'))) {
            return true;
        }

        // 5. Modern Chromium (Chrome/Edge >= 80) sends fetch metadata headers on top-level document navigations.
        // Automated scripts spoofing modern Chrome User-Agent without modern fetch metadata or Sec-CH headers are dropped.
        if (
            preg_match('/(?:chrome|chromium|edg)\/([0-9]+)/', $userAgent, $matches) === 1
            && (int) $matches[1] >= 80
            && !$request->headers->has('Sec-Fetch-Mode')
            && !$request->headers->has('Sec-Fetch-Site')
            && !$request->headers->has('Sec-CH-UA')
        ) {
            return true;
        }

        // 6. Impossible Safari or iOS version ceiling: authentic Apple releases track macOS/iOS major releases (<= 20).
        // Scrapers generate random impossible future versions such as "Version/26.0 Safari".
        if (
            preg_match('/version\/([0-9]+)/', $userAgent, $matches) === 1
            && (int) $matches[1] > 20
            && str_contains($userAgent, 'safari')
        ) {
            return true;
        }

        if (
            preg_match('/(?:iphone|ipad|ipod).*?os\s+([0-9]+)/', $userAgent, $matches) === 1
            && (int) $matches[1] > 20
        ) {
            return true;
        }

        // 7. Impersonation library fingerprint: curl_cffi and tls-client hardcode invalid GREASE brands
        // such as "Not:A-Brand";v="8" containing an illegal colon punctuation character never produced by Chromium.
        $secChUa = strtolower((string) $request->headers->get('Sec-CH-UA'));
        if ($secChUa !== '' && str_contains($secChUa, 'not:a-brand')) {
            return true;
        }

        return false;
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
