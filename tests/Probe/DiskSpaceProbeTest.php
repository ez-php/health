<?php

declare(strict_types=1);

namespace Tests\Probe;

use EzPhp\Health\HealthResult;
use EzPhp\Health\HealthStatus;
use EzPhp\Health\Probe\DiskSpaceProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(DiskSpaceProbe::class)]
#[UsesClass(HealthResult::class)]
#[UsesClass(HealthStatus::class)]
final class DiskSpaceProbeTest extends TestCase
{
    private static function probe(float $free, float $total = 100.0): DiskSpaceProbe
    {
        return new DiskSpaceProbe('/data', 10.0, 5.0, spaceProvider: static fn (): array => ['free' => $free, 'total' => $total]);
    }

    public function test_ok_degraded_and_unhealthy_thresholds(): void
    {
        $this->assertSame(HealthStatus::OK, self::probe(50.0)->check()->status);
        $this->assertSame(HealthStatus::OK, self::probe(10.0)->check()->status, 'exactly at the threshold is still ok');
        $this->assertSame(HealthStatus::DEGRADED, self::probe(9.9)->check()->status);
        $this->assertSame(HealthStatus::UNHEALTHY, self::probe(4.9)->check()->status);
    }

    public function test_message_reports_share_and_sizes(): void
    {
        $result = self::probe(1024.0 ** 3 * 2, 1024.0 ** 3 * 8)->check();

        $this->assertSame('disk', $result->name);
        $this->assertSame('25.0% free (2.0 GB of 8.0 GB) at /data', $result->message);
    }

    public function test_unreadable_space_or_a_throwing_provider_is_unhealthy(): void
    {
        $unreadable = new DiskSpaceProbe('/nope', spaceProvider: static fn (): false => false);
        $throwing = new DiskSpaceProbe('/nope', spaceProvider: static fn (): array => throw new RuntimeException('io'));

        $this->assertSame(HealthStatus::UNHEALTHY, $unreadable->check()->status);
        $this->assertStringContainsString('/nope', $unreadable->check()->message);
        $this->assertSame('io', $throwing->check()->message);
    }

    public function test_missing_real_path_is_unhealthy_with_the_warning_text(): void
    {
        $result = (new DiskSpaceProbe('/nonexistent-ez-health-path'))->check();

        $this->assertSame(HealthStatus::UNHEALTHY, $result->status);
        $this->assertStringStartsWith('Cannot read disk space for /nonexistent-ez-health-path: ', $result->message);
        $this->assertStringContainsString('No such file or directory', $result->message);
    }

    public function test_real_filesystem_is_readable(): void
    {
        $result = (new DiskSpaceProbe(sys_get_temp_dir(), 0.0, 0.0, 'tmp'))->check();

        $this->assertSame(HealthStatus::OK, $result->status);
        $this->assertSame('tmp', $result->name);
    }
}
