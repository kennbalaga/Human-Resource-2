<?php

namespace App\Services;

use App\Models\OfficeLocation;
use Illuminate\Validation\ValidationException;

class GeoFenceService
{
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * @return array{distance_meters: float|null, within_geofence: bool|null}
     */
    public function evaluate(OfficeLocation $office, float $latitude, float $longitude): array
    {
        if (! $office->hasCoordinates()) {
            if ($office->geofence_enabled) {
                throw ValidationException::withMessages([
                    'location' => 'The office geofence coordinates have not been configured.',
                ]);
            }

            return [
                'distance_meters' => null,
                'within_geofence' => null,
            ];
        }

        $distance = $this->distanceInMeters(
            (float) $office->latitude,
            (float) $office->longitude,
            $latitude,
            $longitude,
        );

        return [
            'distance_meters' => round($distance, 2),
            'within_geofence' => $distance <= $office->radius_meters,
        ];
    }

    public function ensureAllowed(OfficeLocation $office, ?bool $withinGeofence): void
    {
        if ($office->geofence_enabled && $withinGeofence !== true) {
            throw ValidationException::withMessages([
                'location' => "You must be within {$office->radius_meters} meters of {$office->name} to record attendance.",
            ]);
        }
    }

    public function distanceInMeters(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude,
    ): float {
        $fromLatitudeRadians = deg2rad($fromLatitude);
        $toLatitudeRadians = deg2rad($toLatitude);
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);

        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitudeRadians)
            * cos($toLatitudeRadians)
            * sin($longitudeDelta / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
    }
}
