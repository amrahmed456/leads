<?php

namespace App\Identity\Brands;

use App\Enums\BrandScope;
use App\Enums\EntityType;
use App\Identity\NameSimilarity;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Support\Normalization\Text;

/**
 * The local brand registry: finds brands by Wikidata ID or by any of their
 * names, creates them from source claims and keeps their scope (how widely
 * they operate) up to date.
 */
final class BrandRegistry
{
    /**
     * @param  int  $nationalMinLocations  Locations in one country needed to call a brand national
     */
    public function __construct(private readonly int $nationalMinLocations) {}

    public function findByWikidata(?string $wikidataId): ?Brand
    {
        return $wikidataId === null ? null : Brand::query()->where('wikidata_id', strtoupper($wikidataId))->first();
    }

    /**
     * A brand whose name or alias equals the given name (or its distinctive
     * part, so "KFC - King Fahd Road" finds "KFC").
     */
    public function findByName(string $name, ?string $countryCode = null): ?Brand
    {
        $keys = array_values(array_unique(array_filter([Text::matchKey($name), NameSimilarity::core($name)], fn (string $key): bool => mb_strlen($key) >= 2)));

        if ($keys === []) {
            return null;
        }

        $candidates = Brand::query()
            ->whereIn('id', BrandAlias::query()->select('brand_id')->whereIn('alias_normalized', $keys))
            ->get();

        return $candidates
            ->sortByDesc(fn (Brand $brand): int => (int) $this->operatesIn($brand, $countryCode) * 2 + (int) ($brand->wikidata_id !== null))
            ->first();
    }

    /**
     * Brands with an alias appearing as a whole word or phrase inside the
     * name ("Hardee's" inside "Hardee's Olaya Express"): weak evidence only.
     *
     * @return list<Brand>
     */
    public function findContainedIn(string $name): array
    {
        $tokens = array_values(array_filter(explode(' ', Text::matchKey($name)), fn (string $token): bool => mb_strlen($token) >= 3));
        $phrases = $tokens;

        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $phrases[] = $tokens[$i].' '.$tokens[$i + 1];
        }

        if ($phrases === []) {
            return [];
        }

        return Brand::query()
            ->whereIn('id', BrandAlias::query()->select('brand_id')->whereIn('alias_normalized', array_unique($phrases)))
            ->limit(5)
            ->get()
            ->all();
    }

    public function operatesIn(Brand $brand, ?string $countryCode): bool
    {
        $countries = array_map('strtoupper', $brand->countries ?? []);

        return $countries === [] || in_array('001', $countries, true) || ($countryCode !== null && in_array(strtoupper($countryCode), $countries, true));
    }

    /**
     * Find or create the brand a source claims, filling in what is new.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function resolve(string $name, ?string $wikidataId, string $source, array $attributes = []): Brand
    {
        $brand = $this->findByWikidata($wikidataId)
            ?? ($wikidataId === null ? $this->findByName($name) : null)
            ?? new Brand(['name' => $name, 'source' => $source]);

        if ($wikidataId !== null && $brand->wikidata_id === null) {
            $brand->wikidata_id = strtoupper($wikidataId);
        }

        foreach ($attributes as $key => $value) {
            if ($value !== null && ($brand->getAttribute($key) === null || in_array($key, ['countries', 'categories'], true))) {
                $brand->setAttribute($key, in_array($key, ['countries', 'categories'], true)
                    ? array_values(array_unique([...($brand->getAttribute($key) ?? []), ...(array) $value]))
                    : $value);
            }
        }

        $brand->scope = $this->scopeFor($brand);
        $brand->save();
        $brand->addAlias($name, $source);

        return $brand;
    }

    /**
     * Record how many locations a first-party or open dataset lists for the brand.
     *
     * @param  list<string>  $countries
     */
    public function recordLocations(Brand $brand, int $locations, array $countries): void
    {
        $brand->location_count = max($brand->location_count ?? 0, $locations);
        $brand->country_count = max($brand->country_count ?? 0, count(array_unique($countries)));
        $brand->countries = array_values(array_unique([...($brand->countries ?? []), ...array_map('strtoupper', $countries)]));
        $brand->scope = $this->scopeFor($brand);
        $brand->save();
    }

    /**
     * International when present in several countries (or worldwide in the
     * registry); national when listed nationally or with many locations in
     * one country; regional with a few locations; unknown otherwise.
     */
    public function scopeFor(Brand $brand): BrandScope
    {
        $countries = array_map('strtoupper', $brand->countries ?? []);
        $countryCount = max($brand->country_count ?? 0, count(array_filter($countries, fn (string $code): bool => strlen($code) === 2)));

        return match (true) {
            in_array('001', $countries, true) || $countryCount > 1 => BrandScope::International,
            ($brand->location_count ?? 0) >= $this->nationalMinLocations => BrandScope::National,
            $brand->nsi_id !== null && $countryCount === 1 => BrandScope::National,
            ($brand->location_count ?? 0) >= 3 => BrandScope::Regional,
            default => $brand->scope === BrandScope::Unknown || $brand->scope === null ? BrandScope::Unknown : $brand->scope,
        };
    }

    public function markEnterprise(Brand $brand): void
    {
        if ($brand->entity_type === null) {
            $brand->entity_type = EntityType::Enterprise;
            $brand->save();
        }
    }
}
