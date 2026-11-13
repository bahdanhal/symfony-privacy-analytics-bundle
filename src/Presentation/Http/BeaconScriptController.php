<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Presentation\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BeaconScriptController
{
    #[Route('/api/pa/beacon.js', name: 'privacy_analytics_script', methods: ['GET'])]
    public function __invoke(): Response
    {
        $response = new Response((string) file_get_contents(dirname(__DIR__, 3) . '/public/beacon.js'));
        $response->headers->set('Content-Type', 'application/javascript; charset=UTF-8');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
