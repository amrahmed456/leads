<?php

namespace App\Discovery\Providers\LocalPlaces;

use App\Discovery\Data\DiscoveryQuery;
use App\Discovery\Data\DiscoveryResult;
use App\Models\SourcePlace;
use App\Sources\SourcePlaceMapper;
use App\Support\Normalization\Text;
use Illuminate\Database\Eloquent\Builder;

/**
 * Searches the local source_places index (Overture extracts, AllThePlaces
 * spiders) like a discovery provider: by radius, category and keywords.
 * No external request is made.
 */
final class LocalPlaceSearch
{
    public function __construct(private readonly SourcePlaceMapper $mapper) {}

    public function hasCoverage(string $source, DiscoveryQuery $query): bool
    {
        return SourcePlace::query()
            ->where('source', $source)
            ->near($query->latitude, $query->longitude, $query->radiusMeters)
            ->exists();
    }

    public function search(string $source, DiscoveryQuery $query, int $maxResults): DiscoveryResult
    {
        $keywords = array_values(array_filter(array_map(Text::matchKey(...), $query->keywords)));

        $places = SourcePlace::query()
            ->where('source', $source)
            ->near($query->latitude, $query->longitude, $query->radiusMeters)
            ->where(function (Builder $where) use ($query, $keywords): void {
                $where->where('mapped_category', $query->category->value);

                foreach ($keywords as $keyword) {
                    $where->orWhere('name_normalized', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword).'%');
                }
            })
            ->where(fn (Builder $status) => $status->whereNull('operating_status')->orWhere('operating_status', '!=', 'permanently_closed'))
            ->orderBy('id')
            ->limit($maxResults + 1)
            ->get();

        $businesses = [];

        foreach ($places->take($maxResults) as $place) {
            // The query selects a bounding box; keep the circle.
            if ($place->distanceTo($query->latitude, $query->longitude) <= $query->radiusMeters) {
                $businesses[] = $this->mapper->toDiscovered($place);
            }
        }

        return new DiscoveryResult($businesses, skipped: 0, truncated: $places->count() > $maxResults);
    }
}
