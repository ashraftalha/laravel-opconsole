<?php

namespace AshrafTalha\OpConsole\Console;

use AshrafTalha\OpConsole\Support\SecurityScanner;
use Illuminate\Console\Command;

class SecurityCommand extends Command
{
    protected $signature = 'opconsole:security {--json : Print machine-readable JSON} {--include-dev : Include development dependencies}';

    protected $description = 'Audit host Composer dependencies and Laravel security configuration';

    public function handle(SecurityScanner $scanner): int
    {
        if ($this->option('include-dev')) {
            config(['opconsole.security.include_dev' => true]);
        }
        $report = $scanner->scan();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Package', 'Version', 'Severity', 'Advisory'], array_map(fn ($a) => [$a['package'], $a['version'] ?? 'unknown', $a['severity'] ?? 'not supplied', $a['title']], $report['advisories'] ?? []));
            $this->line('Scan status: '.($report['status'] ?? 'failed'));
            $this->line('Scope: '.($report['scope'] ?? 'unavailable'));
            $this->line('Configuration warnings: '.count($report['config_warnings'] ?? []));
            if (isset($report['last_failure'])) {
                $this->error($report['last_failure']['message']);
            }
        }
        if (($report['status'] ?? '') !== 'complete') {
            return self::FAILURE;
        }

        return ! empty($report['advisories']) || ! empty($report['config_warnings']) ? self::FAILURE : self::SUCCESS;
    }
}
