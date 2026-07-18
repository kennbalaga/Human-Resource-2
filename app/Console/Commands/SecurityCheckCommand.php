<?php

namespace App\Console\Commands;

use App\Services\Security\ProductionSecurityChecker;
use Illuminate\Console\Command;

class SecurityCheckCommand extends Command
{
    protected $signature = 'security:check';

    protected $description = 'Validate production security configuration before deployment';

    public function handle(ProductionSecurityChecker $checker): int
    {
        $checks = $checker->checks();

        $this->table(
            ['Control', 'Result', 'Severity', 'Required action'],
            array_map(fn (array $check): array => [
                $check['name'],
                $check['passed'] ? 'PASS' : 'FAIL',
                strtoupper($check['severity']),
                $check['passed'] ? '—' : $check['message'],
            ], $checks),
        );

        if ($checker->criticalFailures() !== []) {
            $this->error('Production security check failed. Do not enable enforcement or deploy yet.');

            return self::FAILURE;
        }

        $this->info('All critical production security checks passed.');

        return self::SUCCESS;
    }
}
