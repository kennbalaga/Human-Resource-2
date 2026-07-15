<?php

namespace Tests\Unit;

use App\Services\GeoFenceService;
use PHPUnit\Framework\TestCase;

class GeoFenceServiceTest extends TestCase
{
    public function test_it_calculates_haversine_distance_in_meters(): void
    {
        $service = new GeoFenceService;

        $sameLocation = $service->distanceInMeters(14.75, 121.05, 14.75, 121.05);
        $approximatelyOneKilometer = $service->distanceInMeters(14.75, 121.05, 14.759, 121.05);

        $this->assertSame(0.0, $sameLocation);
        $this->assertGreaterThan(950, $approximatelyOneKilometer);
        $this->assertLessThan(1050, $approximatelyOneKilometer);
    }
}
