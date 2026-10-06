<?php

namespace App\Identity;

use App\Discovery\BusinessNormalizer;
use App\Discovery\BusinessRecorder;
use App\Models\Business;
use App\Models\BusinessSource;
use App\Models\SourcePlace;
use App\Sources\SourcePlaceMapper;
use App\Support\Geo;
use Illuminate\Support\Collection;

/**
 * Links businesses to matching places in the local Overture / AllThePlaces
 * index, so a business found through OpenStreetMap gains first-party
 * branch evidence when a store locator lists it, even if that source was
 * not part of its campaign. A place is linked to at most one business, and
 * only when the entity matcher is convinced they are the same place.
 */
final class SourcePlaceLinker
{
    private const SOURCES = ['alltheplaces', 'overture'];

    public function __construct(
        private readonly EntityMatcher $matcher,
        private readonly BusinessRecorder $recorder,
        private readonly BusinessNormalizer $normalizer,
        private readonly SourcePlaceMapper $mapper,
    ) {}

    /**
     * Link the business to its best unlinked match in each source.
     *
     * @return int Number of new links
     */
    public function linkBusiness(Business $business): int
    {
        if ($business->latitude === null || $business->longitude === null) {
            return 0;
        }

        $business->loadMissing(['sources', 'brand']);
        $known = MatchCandidate::fromBusiness($business);
        $linked = 0;

        foreach (self::SOURCES as $source) {
            if ($business->sources->contains('provider', $source)) {
                continue;
            }

            $best = null;

            SourcePlace::query()
                ->where('source', $source)
                ->near($business->latitude, $business->longitude, $this->matcher->maxDistance())
                ->limit(100)
                ->get()
                ->each(function (SourcePlace $place) use ($known, &$best): void {
                    $match = $this->matcher->match($known, MatchCandidate::fromPlace($place));

                    if ($match->matched && ($best === null || $match->confidence > $best[1]->confidence
                        || ($match->confidence === $best[1]->confidence && $match->distanceMeters < $best[1]->distanceMeters))) {
                        $best = [$place, $match];
                    }
                });

            if ($best !== null && $this->link($business, $best[0], $best[1])) {
                $linked++;
            }
        }

        if ($linked > 0) {
            $business->unsetRelation('sources');
        }

        return $linked;
    }

    /**
     * Link newly synced places (e.g. a spider's branches) to existing businesses.
     *
     * @param  Collection<int, SourcePlace>  $places
     * @return list<int> IDs of businesses that gained a link
     */
    public function linkPlaces(Collection $places): array
    {
        $businessIds = [];

        foreach ($places as $place) {
            if (BusinessSource::query()->where('provider', $place->source)->where('provider_id', $place->source_record_id)->exists()) {
                continue;
            }

            [$latDelta, $lonDelta] = Geo::boundingDeltas($place->latitude, $this->matcher->maxDistance());
            $candidate = MatchCandidate::fromPlace($place);
            $best = null;

            Business::query()
                ->with(['sources', 'brand'])
                ->whereBetween('latitude', [$place->latitude - $latDelta, $place->latitude + $latDelta])
                ->whereBetween('longitude', [$place->longitude - $lonDelta, $place->longitude + $lonDelta])
                ->limit(100)
                ->get()
                ->reject(fn (Business $business): bool => $business->sources->contains('provider', $place->source))
                ->each(function (Business $business) use ($candidate, &$best): void {
                    $match = $this->matcher->match(MatchCandidate::fromBusiness($business), $candidate);

                    if ($match->matched && ($best === null || $match->confidence > $best[1]->confidence)) {
                        $best = [$business, $match];
                    }
                });

            if ($best !== null && $this->link($best[0], $place, $best[1])) {
                $businessIds[] = $best[0]->getKey();
            }
        }

        return array_values(array_unique($businessIds));
    }

    private function link(Business $business, SourcePlace $place, MatchResult $match): bool
    {
        $data = $this->normalizer->normalize($this->mapper->toDiscovered($place));

        if ($data === null || BusinessSource::query()->where('provider', $data->provider)->where('provider_id', $data->providerId)->exists()) {
            return false;
        }

        $this->recorder->attach($business, $data, $match);

        return true;
    }
}
