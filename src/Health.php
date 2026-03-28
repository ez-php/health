<?php

declare(strict_types=1);

namespace EzPhp\Health;

use RuntimeException;

/**
 * Static facade for {@see HealthRegistry}.
 *
 * Wired by {@see HealthServiceProvider::boot()} — throws {@see RuntimeException}
 * when called before the provider has been registered.
 */
final class Health
{
    private static ?HealthRegistry $registry = null;

    /**
     * Set the underlying registry instance (called by HealthServiceProvider).
     */
    public static function setRegistry(HealthRegistry $registry): void
    {
        self::$registry = $registry;
    }

    /**
     * Reset the registry (useful in tests).
     */
    public static function resetRegistry(): void
    {
        self::$registry = null;
    }

    /**
     * Execute all probes and return their results keyed by probe name.
     *
     * @return array<string, HealthResult>
     */
    public static function check(): array
    {
        return self::registry()->run();
    }

    /**
     * Return the aggregate status derived from all probe results.
     */
    public static function status(): HealthStatus
    {
        $results = self::registry()->run();

        return self::registry()->aggregate($results);
    }

    /**
     * @throws RuntimeException when the facade has not been initialised
     */
    private static function registry(): HealthRegistry
    {
        if (self::$registry === null) {
            throw new RuntimeException(
                'Health facade is not initialised. Add HealthServiceProvider to your application.'
            );
        }

        return self::$registry;
    }
}
