<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\WorkloadRiskService;
use Tests\TestCase;

class WorkloadRiskServiceTest extends TestCase
{
    public function test_it_returns_configurable_non_medical_risk_levels(): void
    {
        config([
            'ai_workforce_scheduling.workload_risk.moderate_score' => 35,
            'ai_workforce_scheduling.workload_risk.high_score' => 65,
        ]);
        $service = app(WorkloadRiskService::class);

        $low = $service->assess($this->metrics());
        $high = $service->assess($this->metrics([
            'weekly_workload_minutes' => 2400,
            'overtime_minutes' => 480,
            'recent_assignments' => 20,
            'overnight_assignments' => 20,
            'consecutive_duties' => 5,
            'rest_hours' => 0,
        ]));

        $this->assertSame('low', $low['level']);
        $this->assertSame('high', $high['level']);
        $this->assertSame(100.0, $high['score']);
        $this->assertStringContainsString('non-medical', $high['disclaimer']);
        $this->assertArrayHasKey('rest_interval', $high['factors']);
    }

    /** @param array<string, int|float> $overrides @return array<string, int|float> */
    private function metrics(array $overrides = []): array
    {
        return array_merge([
            'weekly_workload_minutes' => 0,
            'overtime_minutes' => 0,
            'recent_assignments' => 0,
            'overnight_assignments' => 0,
            'consecutive_duties' => 0,
            'rest_hours' => 72,
        ], $overrides);
    }
}
