<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Contracts\RouterInterface;
use EzPhp\Health\Health;
use EzPhp\Health\HealthRegistry;
use EzPhp\Health\HealthServiceProvider;
use EzPhp\Health\LivenessController;
use EzPhp\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\FakeConfig;
use Tests\Support\FakeContainer;

/**
 * @package Tests
 */
#[CoversClass(LivenessController::class)]
#[CoversClass(HealthServiceProvider::class)]
#[UsesClass(Health::class)]
#[UsesClass(HealthRegistry::class)]
final class LivenessControllerTest extends TestCase
{
    public function test_it_answers_200_without_running_a_probe(): void
    {
        $response = (new LivenessController())(new Request('GET', '/livez'));

        $this->assertSame(200, $response->status());
        $this->assertSame('{"status":"ok"}', $response->body());
        $this->assertSame('application/json', $response->headers()['Content-Type'] ?? null);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function routesAfterBoot(array $config): array
    {
        $router = new class () implements RouterInterface {
            /** @var list<string> */
            public array $paths = [];

            public function get(string $path, callable|array $handler): object
            {
                $this->paths[] = $path;

                return new \stdClass();
            }

            public function post(string $path, callable|array $handler): object
            {
                return new \stdClass();
            }

            public function put(string $path, callable|array $handler): object
            {
                return new \stdClass();
            }

            public function patch(string $path, callable|array $handler): object
            {
                return new \stdClass();
            }

            public function delete(string $path, callable|array $handler): object
            {
                return new \stdClass();
            }

            public function toCache(): array
            {
                return [];
            }
        };

        $container = new FakeContainer(new FakeConfig($config));
        $container->instance(RouterInterface::class, $router);
        $provider = new HealthServiceProvider($container);
        $provider->register();
        $provider->boot();

        return $router->paths;
    }

    public function test_no_liveness_route_unless_a_path_is_configured(): void
    {
        $this->assertSame(['/health'], $this->routesAfterBoot([]));
    }

    public function test_the_configured_path_gets_a_liveness_route(): void
    {
        $this->assertSame(['/health', '/livez'], $this->routesAfterBoot(['health.liveness_path' => '/livez']));
    }
}
