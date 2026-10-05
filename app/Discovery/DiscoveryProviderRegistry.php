<?php

namespace App\Discovery;

use App\Discovery\Contracts\BusinessDiscoveryProviderInterface;
use App\Discovery\Exceptions\DiscoveryException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use LogicException;

/**
 * Resolves discovery providers by key from config/discovery.php.
 */
final class DiscoveryProviderRegistry
{
    /**
     * @param  array<string, array{class: class-string, label?: string, enabled?: bool}>  $providers
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $providers,
    ) {}

    /**
     * @throws DiscoveryException when the provider is unknown or disabled
     */
    public function get(string $key): BusinessDiscoveryProviderInterface
    {
        if (! $this->isEnabled($key)) {
            throw DiscoveryException::permanent("The discovery provider [{$key}] is not available.");
        }

        $provider = $this->container->make($this->providers[$key]['class']);

        if (! $provider instanceof BusinessDiscoveryProviderInterface) {
            throw new LogicException("[{$key}] must implement ".BusinessDiscoveryProviderInterface::class.'.');
        }

        return $provider;
    }

    public function isEnabled(string $key): bool
    {
        return isset($this->providers[$key]['class']) && ($this->providers[$key]['enabled'] ?? true);
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

    public function label(string $key): string
    {
        return $this->providers[$key]['label'] ?? Str::headline($key);
    }
}
