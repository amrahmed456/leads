<?php

namespace App\Discovery;

use App\Discovery\Contracts\BusinessDiscoveryProviderInterface;
use App\Discovery\Exceptions\DiscoveryException;
use App\Sources\SourceRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use LogicException;

/**
 * Resolves discovery providers by key from config/discovery.php.
 *
 * A provider is usable only when it is registered here and its data source
 * is available (enabled and configured, see SourceRegistry).
 */
final class DiscoveryProviderRegistry
{
    /**
     * @param  array<string, array{class: class-string, label?: string, enabled?: bool}>  $providers
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $providers,
        private readonly ?SourceRegistry $sources = null,
    ) {}

    /**
     * @throws DiscoveryException when the provider is unknown or unavailable
     */
    public function get(string $key): BusinessDiscoveryProviderInterface
    {
        if (! $this->isEnabled($key)) {
            throw DiscoveryException::permanent($this->unavailableReason($key));
        }

        $provider = $this->container->make($this->providers[$key]['class']);

        if (! $provider instanceof BusinessDiscoveryProviderInterface) {
            throw new LogicException("[{$key}] must implement ".BusinessDiscoveryProviderInterface::class.'.');
        }

        return $provider;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]['class']);
    }

    public function isEnabled(string $key): bool
    {
        return $this->has($key)
            && ($this->providers[$key]['enabled'] ?? true)
            && ($this->sources === null || ! $this->sources->has($key) || $this->sources->isAvailable($key));
    }

    /**
     * Why a provider cannot be used, in plain language.
     */
    public function unavailableReason(string $key): string
    {
        $label = $this->label($key);

        return match (true) {
            ! $this->has($key) => "The discovery provider [{$key}] is not available.",
            ! ($this->providers[$key]['enabled'] ?? true) => "{$label} is switched off in the configuration.",
            $this->sources !== null && $this->sources->has($key) && ! $this->sources->isEnabled($key) => "{$label} is disabled in Settings → Data Sources.",
            $this->sources !== null && $this->sources->has($key) && ! $this->sources->isConfigured($key) => "{$label} needs credentials (Settings → Data Sources).",
            default => "{$label} is available.",
        };
    }

    /**
     * Enabled providers for select inputs.
     *
     * @return array<string, string> key => label
     */
    public function options(): array
    {
        $options = [];

        foreach (array_keys($this->providers) as $key) {
            if ($this->isEnabled($key)) {
                $options[$key] = $this->label($key);
            }
        }

        return $options;
    }

    /**
     * Every registered discovery provider, available or not.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(array_filter($this->providers, fn (array $provider): bool => isset($provider['class'])));
    }

    public function label(string $key): string
    {
        return $this->providers[$key]['label'] ?? ($this->sources?->has($key) ? $this->sources->label($key) : Str::headline($key));
    }
}
