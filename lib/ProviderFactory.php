<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

/**
 * Factory pro provider podle nastavení elektrárny.
 *
 * V první fázi:  'mock' → MockProvider, 'isolarcloud' → ISolarCloudProvider
 * Připraveno pro: 'solaredge' → SolarEdgeProvider (až budeš implementovat)
 */
class ProviderFactory
{
    public static function forPlant(array $plant): ProviderInterface
    {
        return match ($plant['provider']) {
            'mock'        => new MockProvider((int) $plant['id']),
            'isolarcloud' => new ISolarCloudProvider(),
            'solaredge'   => new SolarEdgeProvider(),
            default => throw new \RuntimeException("Neznámý provider: {$plant['provider']}"),
        };
    }
}
