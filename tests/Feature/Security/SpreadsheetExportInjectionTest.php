<?php

namespace Tests\Feature\Security;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Names reach the workforce exports exactly as HR typed them, and a
 * spreadsheet reads a leading "=" as an instruction rather than a value. The
 * reviewer who opens the report is the one who would run it, so the exports
 * are asserted to hand back inert cells.
 */
class SpreadsheetExportInjectionTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '=cmd|\'/c calc\'!A1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function employeeNamedWithAPayload(): Employee
    {
        $employee = Employee::query()->firstOrFail();
        $employee->update(['first_name' => self::PAYLOAD, 'last_name' => 'Probe']);

        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-10',
            'check_in_at' => '2026-08-10 08:00:00',
            'check_out_at' => '2026-08-10 17:00:00',
            'status' => 'present',
            'worked_minutes' => 480,
        ]);

        return $employee;
    }

    private function reviewer(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    private function reportUrl(string $path): string
    {
        return $path.'?'.http_build_query(['date_from' => '2026-08-01', 'date_to' => '2026-08-20']);
    }

    public function test_the_attendance_csv_never_hands_back_a_live_formula(): void
    {
        $this->employeeNamedWithAPayload();

        $csv = $this->actingAs($this->reviewer())
            ->get($this->reportUrl('/attendance/reports/export'))
            ->assertOk()
            ->streamedContent();

        foreach (explode("\n", $csv) as $line) {
            foreach (str_getcsv($line) as $cell) {
                $this->assertNotContains(
                    $cell[0] ?? '',
                    ['=', '+', '@', "\t", "\r"],
                    'A cell would be parsed as a formula: '.$cell,
                );
            }
        }

        // The name still has to survive the trip — quoted, not discarded.
        $this->assertStringContainsString(self::PAYLOAD, $csv);
    }

    public function test_the_attendance_workbook_stores_the_name_as_text(): void
    {
        $this->employeeNamedWithAPayload();

        $xlsx = $this->actingAs($this->reviewer())
            ->get($this->reportUrl('/attendance/reports/export-excel'))
            ->assertOk()
            ->streamedContent();

        $file = tempnam(sys_get_temp_dir(), 'hrms-export-').'.xlsx';
        file_put_contents($file, $xlsx);

        try {
            $sheet = IOFactory::load($file)->getActiveSheet();

            $names = [];
            foreach ($sheet->getRowIterator(2) as $row) {
                $cell = $sheet->getCell([3, $row->getRowIndex()]); // "Employee" column
                $names[] = $cell->getValue();
                $this->assertNotSame(
                    DataType::TYPE_FORMULA,
                    $cell->getDataType(),
                    'The employee name was written as a live formula.',
                );
            }

            $this->assertContains(self::PAYLOAD.' Probe', $names);
        } finally {
            @unlink($file);
        }
    }

    public function test_worked_minutes_stay_numeric_in_the_workbook(): void
    {
        $this->employeeNamedWithAPayload();

        $xlsx = $this->actingAs($this->reviewer())
            ->get($this->reportUrl('/attendance/reports/export-excel'))
            ->assertOk()
            ->streamedContent();

        $file = tempnam(sys_get_temp_dir(), 'hrms-export-').'.xlsx';
        file_put_contents($file, $xlsx);

        try {
            $sheet = IOFactory::load($file)->getActiveSheet();
            // "Worked Minutes" is the 14th column -- it was the 13th until the
            // export gained the "Approval" column the screen had always shown.
            $this->assertSame(480, $sheet->getCell([14, 2])->getValue());
        } finally {
            @unlink($file);
        }
    }
}
