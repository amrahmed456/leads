<?php

namespace App\Sources;

use App\Enums\SourceRole;

/**
 * Static description of a data source (config/sources.php "catalog").
 */
final readonly class SourceDescriptor
{
    /**
     * @param  list<SourceRole>  $roles
     * @param  array<string, string>  $credentials  Required credential field => label
     * @param  array{description: string, daily_quota: ?int, unit: string}  $rateLimit
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $roles,
        public string $tier,
        public array $credentials,
        public bool $credentialsOptional,
        public string $coverage,
        public bool $persistable,
        public string $freshness,
        public string $independenceGroup,
        public array $rateLimit,
        public array $capabilities,
        public ?string $docsUrl,
        public ?string $notes,
        public bool $defaultEnabled,
        public bool $implemented,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            label: (string) ($config['label'] ?? $key),
            roles: array_values(array_filter(array_map(
                fn (mixed $role): ?SourceRole => is_string($role) ? SourceRole::tryFrom($role) : null,
                (array) ($config['roles'] ?? []),
            ))),
            tier: (string) ($config['tier'] ?? 'free'),
            credentials: (array) ($config['credentials'] ?? []),
            credentialsOptional: (bool) ($config['credentials_optional'] ?? false),
            coverage: (string) ($config['coverage'] ?? ''),
            persistable: (bool) ($config['persistable'] ?? true),
            freshness: (string) ($config['freshness'] ?? ''),
            independenceGroup: (string) ($config['independence_group'] ?? $key),
            rateLimit: [
                'description' => (string) ($config['rate_limit']['description'] ?? ''),
                'daily_quota' => isset($config['rate_limit']['daily_quota']) ? (int) $config['rate_limit']['daily_quota'] : null,
                'unit' => (string) ($config['rate_limit']['unit'] ?? 'requests'),
            ],
            capabilities: array_values((array) ($config['capabilities'] ?? [])),
            docsUrl: isset($config['docs_url']) ? (string) $config['docs_url'] : null,
            notes: isset($config['notes']) ? (string) $config['notes'] : null,
            defaultEnabled: (bool) ($config['default_enabled'] ?? false),
            implemented: (bool) ($config['implemented'] ?? false),
        );
    }

    public function isFree(): bool
    {
        return $this->tier === 'free';
    }

    public function hasRole(SourceRole $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function primaryRole(): SourceRole
    {
        return $this->roles[0] ?? SourceRole::Enrichment;
    }

    public function requiresCredentials(): bool
    {
        return $this->credentials !== [] && ! $this->credentialsOptional;
    }

    public function dailyQuota(): ?int
    {
        return $this->rateLimit['daily_quota'];
    }

    public function quotaUnit(): string
    {
        return $this->rateLimit['unit'];
    }
}
