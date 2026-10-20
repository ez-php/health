<?php

declare(strict_types=1);

namespace EzPhp\Health\Probe;

use Closure;
use EzPhp\Health\HealthResult;
use EzPhp\Health\ProbeInterface;
use Throwable;

/**
 * Health probe reporting the free space of the filesystem holding a path.
 *
 * Several modules write to local disk (ez-php/storage's LocalDriver, the cache
 * FileDriver, file sessions, file logs); a full disk fails all of them at once.
 * The probe reports DEGRADED below `$degradedPercent` free and UNHEALTHY below
 * `$unhealthyPercent` free (or when the space cannot be read). Uses the stdlib
 * disk_free_space()/disk_total_space(); no module dependencies.
 */
final class DiskSpaceProbe implements ProbeInterface
{
    /**
     * @param string                                         $path             Any path on the filesystem to check (e.g. the storage directory).
     * @param float                                          $degradedPercent  DEGRADED when the free share drops below this (0–100).
     * @param float                                          $unhealthyPercent UNHEALTHY when the free share drops below this (0–100).
     * @param string                                         $name             Probe identifier (default: 'disk').
     * @param (Closure(string): (array{free: float, total: float}|false))|null $spaceProvider Overrides disk_free_space()/disk_total_space() for testing.
     */
    public function __construct(
        private readonly string $path,
        private readonly float $degradedPercent = 10.0,
        private readonly float $unhealthyPercent = 5.0,
        private readonly string $name = 'disk',
        private readonly ?Closure $spaceProvider = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function check(): HealthResult
    {
        $start = microtime(true);

        try {
            $space = $this->readSpace();
            $latency = (microtime(true) - $start) * 1000;

            if ($space === false || $space['total'] <= 0.0) {
                return HealthResult::unhealthy($this->name, "Cannot read disk space for {$this->path}", $latency);
            }

            $freePct = $space['free'] / $space['total'] * 100;
            $message = sprintf(
                '%.1f%% free (%s of %s) at %s',
                $freePct,
                self::bytes($space['free']),
                self::bytes($space['total']),
                $this->path,
            );

            return match (true) {
                $freePct < $this->unhealthyPercent => HealthResult::unhealthy($this->name, $message, $latency),
                $freePct < $this->degradedPercent => HealthResult::degraded($this->name, $message, $latency),
                default => HealthResult::ok($this->name, $message, $latency),
            };
        } catch (Throwable $e) {
            return HealthResult::unhealthy($this->name, $e->getMessage(), (microtime(true) - $start) * 1000);
        }
    }

    /**
     * @return array{free: float, total: float}|false
     */
    private function readSpace(): array|false
    {
        if ($this->spaceProvider !== null) {
            return ($this->spaceProvider)($this->path);
        }

        $free = @disk_free_space($this->path);
        $total = @disk_total_space($this->path);

        return $free === false || $total === false ? false : ['free' => $free, 'total' => $total];
    }

    /**
     * @param float $bytes
     *
     * @return string
     */
    private static function bytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $bytes, $units[$i]);
    }
}
