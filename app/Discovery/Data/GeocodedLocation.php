<?php

namespace App\Discovery\Data;

final readonly class GeocodedLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $displayName,
    ) {}

    /**
     * @return array{latitude: float, longitude: float, display_name: string}
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'display_name' => $this->displayName,
        ];
    }

    /**
     * @param  array{latitude: float, longitude: float, display_name: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((float) $data['latitude'], (float) $data['longitude'], (string) $data['display_name']);
    }
}
