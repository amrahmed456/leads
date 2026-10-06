<?php

namespace App\Sources\GeoNames;

use App\Enums\Country;
use App\Models\GeoPlace;
use App\Sources\QuotaExceededException;
use App\Sources\QuotaManager;
use App\Sources\SourceRegistry;
use App\Support\Normalization\Text;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves user-entered city names in any language ("الرياض", "Riyadh",
 * "Ar Riyad") to one canonical GeoNames city, from the imported cities
 * table first and the GeoNames web service only when a username is
 * configured. Every web result is stored, so a city is looked up once.
 *
 * Geographic context only: GeoNames never discovers businesses.
 */
final class CityResolver
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly QuotaManager $quotas,
        private readonly string $apiUrl,
    ) {}

    public function resolve(string $place, Country $country): ?GeoPlace
    {
        $key = Text::matchKey($place);

        if ($key === '') {
            return null;
        }

        return $this->local($key, $country) ?? $this->remote($place, $country);
    }

    /**
     * The canonical (English) name of a city, or null when unknown.
     */
    public function canonicalName(string $place, Country $country): ?string
    {
        return $this->resolve($place, $country)?->name;
    }

    private function local(string $key, Country $country): ?GeoPlace
    {
        return GeoPlace::query()
            ->where('country_code', $country->value)
            ->where('search_names', 'like', '%|'.str_replace(['%', '_'], ['\\%', '\\_'], $key).'|%')
            ->orderByDesc('population')
            ->first();
    }

    private function remote(string $place, Country $country): ?GeoPlace
    {
        $username = $this->sources->credential('geonames', 'username');

        if ($username === null || ! $this->sources->isEnabled('geonames')) {
            return null;
        }

        try {
            $this->quotas->consume('geonames');

            $response = Http::acceptJson()->connectTimeout(10)->timeout(20)->get(rtrim($this->apiUrl, '/').'/searchJSON', [
                'q' => $place,
                'country' => $country->value,
                'featureClass' => 'P',
                'maxRows' => 1,
                'style' => 'FULL',
                'username' => $username,
            ]);

            $result = $response->json('geonames.0');

            if ($response->failed() || ! is_array($result) || ! isset($result['geonameId'], $result['lat'], $result['lng'])) {
                if ($response->failed() || is_array($response->json('status'))) {
                    $this->sources->recordFailure('geonames', 'GeoNames lookup failed: '.($response->json('status.message') ?? "HTTP {$response->status()}"));
                }

                return null;
            }
        } catch (QuotaExceededException) {
            return null;
        } catch (Throwable $e) {
            $this->sources->recordFailure('geonames', $e->getMessage());

            return null;
        }

        $this->sources->recordSuccess('geonames');

        $names = [(string) ($result['name'] ?? $place), (string) ($result['asciiName'] ?? ''), $place];
        foreach ((array) ($result['alternateNames'] ?? []) as $alternate) {
            if (is_array($alternate) && in_array($alternate['lang'] ?? null, ['ar', 'en'], true)) {
                $names[] = (string) $alternate['name'];
            }
        }

        return GeoPlace::query()->updateOrCreate(['geonames_id' => (int) $result['geonameId']], [
            'name' => (string) ($result['name'] ?? $place),
            'ascii_name' => (string) ($result['asciiName'] ?? $result['name'] ?? $place),
            'name_ar' => self::arabicName($names),
            'search_names' => self::searchNames($names),
            'country_code' => $country->value,
            'admin1' => isset($result['adminCode1']) ? (string) $result['adminCode1'] : null,
            'feature_code' => (string) ($result['fcode'] ?? 'PPL'),
            'latitude' => (float) $result['lat'],
            'longitude' => (float) $result['lng'],
            'population' => (int) ($result['population'] ?? 0),
        ]);
    }

    /**
     * @param  list<string>  $names
     */
    public static function searchNames(array $names): string
    {
        $keys = array_values(array_unique(array_filter(array_map(fn (string $name): string => Text::matchKey($name), $names))));

        return '|'.implode('|', array_slice($keys, 0, 60)).'|';
    }

    /**
     * @param  list<string>  $names
     */
    public static function arabicName(array $names): ?string
    {
        foreach ($names as $name) {
            if (preg_match('/\p{Arabic}/u', $name)) {
                return mb_substr($name, 0, 200);
            }
        }

        return null;
    }
}
