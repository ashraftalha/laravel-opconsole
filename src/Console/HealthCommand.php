<?php

namespace AshrafTalha\OpConsole\Console;

use AshrafTalha\OpConsole\Support\ConsoleStatus;
use Illuminate\Console\Command;

class HealthCommand extends Command
{
    protected $signature = 'opconsole:health
                            {--json : Print machine-readable JSON}
                            {--fail-on=critical : Exit 1 when any check is at or above this level (warning or critical)}';

    protected $description = 'Run the OpConsole health checks';

    public function handle(ConsoleStatus $status): int
    {
        $failOn = (string) $this->option('fail-on');
        if (! in_array($failOn, ['warning', 'critical'], true)) {
            $this->components->error('The --fail-on option must be "warning" or "critical".');

            return self::INVALID;
        }

        $report = $status->health();
        $threshold = ConsoleStatus::rank($failOn);
        $failed = array_filter($report['checks'], fn (array $check) => ConsoleStatus::rank($check['status']) >= $threshold);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $report['status'],
                'checked_at' => $report['checked_at'],
                'checks' => array_map(fn (array $check) => [
                    'id' => $check['id'],
                    'group' => $check['group'],
                    'label' => $check['label'],
                    'status' => $check['status'],
                    'value' => $check['value'],
                    'hint' => $check['hint'],
                    'fix' => $check['status'] === 'healthy' ? null : $check['fix'],
                ], $report['checks']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['ID', 'Status', 'Label', 'Value', 'Fix'],
                array_map(fn (array $check) => [
                    $check['id'],
                    $check['status'],
                    $check['label'],
                    $check['value'] ?? '',
                    $check['status'] === 'healthy' ? '' : ($check['fix'] ?? ''),
                ], $report['checks']),
            );
            $this->line('Overall: '.$report['status']);
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
