<?php

declare(strict_types=1);

namespace Bahdan\PrivacyAnalyticsBundle\Tests;

use Bahdan\PrivacyAnalyticsBundle\Presentation\Http\BeaconScriptController;
use PHPUnit\Framework\TestCase;

final class BeaconScriptControllerTest extends TestCase
{
    public function testServesPackagedScript(): void
    {
        $response = (new BeaconScriptController())();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/javascript; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame(file_get_contents(__DIR__ . '/../public/beacon.js'), $response->getContent());
        self::assertTrue($response->isCacheable());
    }
}
