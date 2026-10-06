<?php

namespace App\Sources;

use App\Enums\SourceHealth;
use App\Enums\SourceRole;
use App\Models\DataSource;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Every data source the system knows (config/sources.php) combined with its
 * runtime state (data_sources table): whether it is enabled, configured,
 * healthy, which independence group it belongs to and its credentials.
 *
 * A source is "available" only when it is implemented, enabled and has its
 * required credentials. Paid and future sources are never available by
 * default, so they cannot be called by accident.
 */
final class SourceRegistry
{
    /** @var array<string, SourceDescriptor> */
    private readonly array $descriptors;

    /**
     * @param  array<string, array<string, mixed>>  $catalog
     */
    public function __construct(private readonly array $catalog)
    {
        $descriptors = [];

        foreach ($catalog as $key => $config) {
            $descriptors[$key] = SourceDescriptor::fromConfig($key, $config);
        }

        $this->descriptors = $descriptors;
    }

    public function has(string $key): bool
    {
        return isset($this->descriptors[$key]);
    }

    public function descriptor(string $key): SourceDescriptor
    {
        return $this->descriptors[$key] ?? throw new InvalidArgumentException("Unknown data source [{$key}].");
    }

    /**
     * @return array<string, SourceDescriptor>
     */
    public function all(): array
    {
        return $this->descriptors;
    }

    /**
     * @return array<string, SourceDescriptor>
     */
    public function withRole(SourceRole $role): array
    {
        return array_filter($this->descriptors, fn (SourceDescriptor $source): bool => $source->hasRole($role));
    }

    public function label(string $key): string
    {
        return $this->descriptors[$key]->label ?? Str::headline($key);
    }

    /**
     * Sources backed by the same dataset share a group and never confirm each other.
     */
    public function independenceGroup(string $key): string
    {
        return $this->descriptors[$key]->independenceGroup ?? $key;
    }

    public function primaryRole(string $key): SourceRole
    {
        return ($this->descriptors[$key] ?? null)?->primaryRole() ?? SourceRole::Enrichment;
    }

    public function state(string $key): DataSource
    {
        return DataSource::query()->firstOrNew(['key' => $key]);
    }

    public function isEnabled(string $key): bool
    {
        $descriptor = $this->descriptors[$key] ?? null;

        if ($descriptor === null || ! $descriptor->implemented) {
            return false;
        }

        return $this->state($key)->enabled ?? $descriptor->defaultEnabled;
    }

    public function isConfigured(string $key): bool
    {
        $descriptor = $this->descriptors[$key] ?? null;

        if ($descriptor === null) {
            return false;
        }

        if (! $descriptor->requiresCredentials()) {
            return true;
        }

        foreach (array_keys($descriptor->credentials) as $field) {
            if ($this->credential($key, $field) === null) {
                return false;
            }
        }

        return true;
    }

    public function isAvailable(string $key): bool
    {
        return $this->isEnabled($key) && $this->isConfigured($key);
    }

    /**
     * A decrypted credential. Never send it to the browser or a log.
     */
    public function credential(string $key, string $field): ?string
    {
        $stored = $this->state($key)->credentials[$field] ?? null;
        $fromEnv = $this->catalog[$key]['env_credentials'][$field] ?? null;
        $value = is_string($stored) && $stored !== '' ? $stored : $fromEnv;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Every credential value of a source, so error messages can be scrubbed.
     *
     * @return list<string>
     */
    public function secrets(string $key): array
    {
        return array_values(array_filter(array_map(
            fn (string $field): ?string => $this->credential($key, $field),
            array_keys($this->descriptors[$key]->credentials ?? []),
        )));
    }

    public function setting(string $key, string $name, mixed $default = null): mixed
    {
        return $this->state($key)->settings[$name] ?? $default;
    }

    public function setEnabled(string $key, bool $enabled): void
    {
        $state = $this->state($key);
        $state->enabled = $enabled;
        $state->save();
    }

    /**
     * Store new credential values (encrypted). Empty values leave the stored ones unchanged.
     *
     * @param  array<string, ?string>  $values
     */
    public function saveCredentials(string $key, array $values): void
    {
        $allowed = array_keys($this->descriptor($key)->credentials);
        $state = $this->state($key);
        $credentials = $state->credentials ?? [];

        foreach ($values as $field => $value) {
            if (in_array($field, $allowed, true) && is_string($value) && trim($value) !== '') {
                $credentials[$field] = trim($value);
            }
        }

        $state->credentials = $credentials === [] ? null : $credentials;
        $state->save();
    }

    public function clearCredentials(string $key): void
    {
        $state = $this->state($key);
        $state->credentials = null;
        $state->save();
    }

    public function recordSuccess(string $key): void
    {
        $state = $this->state($key);
        $state->forceFill(['last_success_at' => now(), 'health_status' => SourceHealth::Healthy])->save();
    }

    public function recordFailure(string $key, string $message): void
    {
        $state = $this->state($key);
        $state->forceFill([
            'last_error_at' => now(),
            'last_error' => Str::limit(SecretScrubber::scrub($message, $this->secrets($key)), 1000),
            'health_status' => SourceHealth::Failing,
        ])->save();
    }

    /**
     * Current health, derived from configuration, quota and recent results.
     */
    public function health(string $key, ?QuotaManager $quotas = null): SourceHealth
    {
        $descriptor = $this->descriptor($key);
        $state = $this->state($key);

        return match (true) {
            ! $descriptor->implemented => SourceHealth::NotImplemented,
            ! $this->isEnabled($key) => SourceHealth::Disabled,
            ! $this->isConfigured($key) => SourceHealth::Unconfigured,
            $quotas?->isExhausted($key) === true => SourceHealth::QuotaExhausted,
            $state->last_error_at !== null && ($state->last_success_at === null || $state->last_error_at->greaterThan($state->last_success_at)) => SourceHealth::Failing,
            $state->last_error_at !== null && $state->last_error_at->greaterThan(now()->subDay()) => SourceHealth::Degraded,
            $state->last_success_at !== null => SourceHealth::Healthy,
            default => SourceHealth::Unknown,
        };
    }
}
