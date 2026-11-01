<?php

namespace AshrafTalha\OpConsole\Contracts;

/**
 * A single health check, registered via config('opconsole.checks').
 * Implementations are resolved from the container, so constructor
 * dependencies are injected normally.
 */
interface HealthCheck
{
    /**
     * @return array{group: string, label: string, ok: bool, severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string, id?: string, status?: 'healthy'|'warning'|'critical'|'unknown'}
     */
    public function run(): array;
}
