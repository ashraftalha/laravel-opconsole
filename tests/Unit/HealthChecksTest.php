<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Contracts\HealthCheck;
use AshrafTalha\OpConsole\Support\HealthChecks;
use AshrafTalha\OpConsole\Tests\TestCase;

class HealthChecksTest extends TestCase
{
    private array $database = ['ok' => true, 'driver' => 'sqlite', 'name' => ':memory:', 'ran' => 0, 'pending' => [], 'error' => null];

    public function test_built_in_checks_are_returned_grouped(): void
    {
        $checks = $this->app->make(HealthChecks::class)->run($this->database, 'localhost');

        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            $this->assertArrayHasKey('group', $check);
            $this->assertArrayHasKey('severity', $check);
        }
    }

    public function test_loopback_hosts_are_treated_as_equivalent(): void
    {
        config(['app.url' => 'http://127.0.0.1']);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, 'localhost'));
        $hostCheck = $checks->firstWhere('label', 'Application URL matches current host');

        $this->assertTrue($hostCheck['ok']);
    }

    public function test_custom_check_from_config_is_included(): void
    {
        config(['opconsole.checks' => [PassingCheck::class]]);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, null));

        $this->assertTrue($checks->contains('label', 'Custom check'));
    }

    public function test_malformed_custom_check_result_is_rejected_without_breaking_the_page(): void
    {
        config(['opconsole.checks' => [MalformedCheck::class]]);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, null));

        $this->assertFalse($checks->contains('label', 'Malformed'));
    }
}

class PassingCheck implements HealthCheck
{
    public function run(): array
    {
        return ['group' => 'Custom', 'label' => 'Custom check', 'ok' => true, 'severity' => 'info', 'hint' => 'hint', 'value' => null, 'fix' => null];
    }
}

class MalformedCheck implements HealthCheck
{
    public function run(): array
    {
        return ['label' => 'Malformed']; // missing required keys
    }
}
