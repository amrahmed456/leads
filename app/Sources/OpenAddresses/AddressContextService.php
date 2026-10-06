<?php

namespace App\Sources\OpenAddresses;

use App\Enums\EvidenceFact;
use App\Enums\SourceRole;
use App\Identity\Evidence\EvidenceItem;
use App\Identity\Evidence\EvidenceProvenance;
use App\Identity\Evidence\EvidenceRecorder;
use App\Models\AddressPoint;
use App\Models\Business;
use App\Support\Geo;

/**
 * Gives a business without an address the nearest open address point, as
 * address-context evidence. Address context never confirms that a business
 * exists and never raises a lead score.
 */
final class AddressContextService
{
    public function __construct(
        private readonly EvidenceRecorder $evidence,
        private readonly int $matchDistanceMeters,
    ) {}

    public function enrich(Business $business): ?AddressPoint
    {
        if ($business->address !== null || $business->latitude === null || $business->longitude === null) {
            return null;
        }

        [$latDelta, $lonDelta] = Geo::boundingDeltas($business->latitude, $this->matchDistanceMeters);

        $nearest = AddressPoint::query()
            ->whereBetween('latitude', [$business->latitude - $latDelta, $business->latitude + $latDelta])
            ->whereBetween('longitude', [$business->longitude - $lonDelta, $business->longitude + $lonDelta])
            ->limit(50)
            ->get()
            ->map(fn (AddressPoint $point): array => [$point, Geo::distanceMeters($business->latitude, $business->longitude, $point->latitude, $point->longitude)])
            ->filter(fn (array $candidate): bool => $candidate[1] <= $this->matchDistanceMeters && $candidate[0]->formatted() !== '')
            ->sortBy(fn (array $candidate): float => $candidate[1])
            ->first()[0] ?? null;

        if ($nearest === null) {
            return null;
        }

        $business->forceFill(['address' => mb_substr($nearest->formatted(), 0, 500)])->save();

        $this->evidence->recordMany(
            $business,
            new EvidenceProvenance('openaddresses', SourceRole::AddressContext, 'address_data', "{$nearest->dataset}/{$nearest->source_id}"),
            [new EvidenceItem(EvidenceFact::Address, $nearest->formatted(), null, ['nearest_point' => true])],
        );

        return $nearest;
    }
}
