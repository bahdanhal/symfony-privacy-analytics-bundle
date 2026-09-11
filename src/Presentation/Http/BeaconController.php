<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Presentation\Http;

use Bahdan\PrivacyAnalyticsBundle\Domain\PageView;
use Bahdan\PrivacyAnalyticsBundle\Domain\PageViewRepository;
use Bahdan\PrivacyAnalyticsBundle\EventSubscriber\PageViewSubscriber;
use Bahdan\PrivacyAnalyticsBundle\Message\RecordPageView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class BeaconController
{
    public function __construct(
        private PageViewRepository $pageViews,
        private string $secret,
        private PageViewSubscriber $subscriber,
        private ?MessageBusInterface $messageBus = null,
    ) {
    }

    #[Route('/api/pa/hit', name: 'privacy_analytics_beacon', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request): Response
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Cache-Control' => 'no-store, private',
        ];

        if ($request->isMethod('OPTIONS')) {
            return new Response('', Response::HTTP_NO_CONTENT, $corsHeaders);
        }

        if ($request->headers->get('DNT') === '1' || $request->headers->get('Sec-GPC') === '1') {
            return new Response('', Response::HTTP_NO_CONTENT, $corsHeaders);
        }

        $content = (string) $request->getContent();
        if ($content === '') {
            return new Response('', Response::HTTP_NO_CONTENT, $corsHeaders);
        }

        try {
            /** @var mixed $payload */
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new Response('', Response::HTTP_NO_CONTENT, $corsHeaders);
        }

        if (!is_array($payload)) {
            return new Response('', Response::HTTP_NO_CONTENT, $corsHeaders);
        }

        $rawPath = isset($payload['p']) && is_string($payload['p']) ? trim($payload['p']) : '';
        if ($rawPath === '' || !str_starts_with($rawPath, '/')) {
            return new Response('', Response::HTTP_NO_CONTENT, $corsHeaders);
        }

        // Drop query strings from path if client supplied them
        $pathOnly = (string) parse_url($rawPath, PHP_URL_PATH);
        if ($pathOnly === '') {
            $pathOnly = '/';
        }

        if ($this->subscriber->isExcluded($request, $pathOnly, isBeacon: true)) {
            return new Response('', Response::HTTP_NO_CONTENT, $corsHeaders);
        }

        $referrer = isset($payload['r']) && is_string($payload['r']) && trim($payload['r']) !== ''
            ? trim($payload['r'])
            : null;
        [$source, $referrerHost] = $this->subscriber->source($request, $referrer);

        $clientIp = $request->getClientIp() ?? 'unknown';
        $userAgent = (string) $request->headers->get('User-Agent');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dateSalt = $now->format('Y-m-d');

        $pageView = new PageView(
            $now,
            hash_hmac('sha256', $dateSalt . '|' . $clientIp . '|' . $userAgent, $this->secret),
            $this->subscriber->normalizedPath($pathOnly),
            $source,
            $referrerHost,
        );

        if ($this->messageBus !== null) {
            $this->messageBus->dispatch(RecordPageView::fromPageView($pageView));
        } else {
            $this->pageViews->save($pageView);
        }

        return new Response('', Response::HTTP_NO_CONTENT, $corsHeaders);
    }
}
