<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveManagementSeeder extends Seeder
{
    public function run(): void
    {
        // Every attribute is written on each pass, defaults included. Letting an
        // unlisted key fall through to whatever the row already held would leave
        // a type reclassified by hand quietly disagreeing with the catalogue.
        $catalogue = array_merge(
            array_map(fn (array $data) => $data + ['category' => LeaveType::CATEGORY_STATUTORY], $this->statutoryTypes()),
            array_map(fn (array $data) => $data + ['category' => LeaveType::CATEGORY_COMPANY], $this->companyTypes()),
        );

        foreach ($catalogue as $data) {
            $type = LeaveType::query()->updateOrCreate(['code' => $data['code']], $data + [
                'description' => null,
                'accrual_method' => LeaveType::ACCRUAL_ANNUAL,
                'day_basis' => LeaveType::BASIS_WORKING,
                'max_carry_over' => 0,
                'requires_attachment' => false,
                'eligible_gender' => null,
                'min_service_months' => 0,
                'requires_designation' => null,
                'satisfies_sil' => false,
                'is_active' => true,
            ]);

            // Only a yearly allowance opens the year with credits. Seeding a
            // per-occasion type from its entitlement is what would grant every
            // employee a fresh 105 days of maternity leave each January.
            $entitled = $type->isBalanceBacked() ? $type->annual_entitlement : 0;

            Employee::query()->where('employment_status', 'active')->each(function (Employee $employee) use ($type, $entitled): void {
                LeaveBalance::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => now()->year],
                    ['entitled_days' => $entitled],
                );
            });
        }
    }

    /**
     * Required by law, so the entitlements are not the company's to set. The
     * service thresholds and the working/calendar split are seeded as data
     * because they are the details most likely to move with the implementing
     * rules -- HR can correct any of them without a deployment.
     *
     * @return array<int, array<string, mixed>>
     */
    private function statutoryTypes(): array
    {
        return [
            ['code' => 'MATERNITY', 'name' => 'Maternity Leave', 'description' => 'Para sa babaeng empleyadong manganganak. Saklaw ng mga umiiral na batas at SSS benefits.', 'color' => '#DB2777', 'annual_entitlement' => 105, 'accrual_method' => LeaveType::ACCRUAL_PER_EVENT, 'day_basis' => LeaveType::BASIS_CALENDAR, 'eligible_gender' => LeaveType::GENDER_FEMALE, 'requires_attachment' => true],
            ['code' => 'MATERNITY-ETP', 'name' => 'Maternity Leave (Miscarriage / Emergency Termination)', 'description' => 'Para sa miscarriage o emergency termination of pregnancy. Kailangan ng medical certificate.', 'color' => '#BE185D', 'annual_entitlement' => 60, 'accrual_method' => LeaveType::ACCRUAL_PER_EVENT, 'day_basis' => LeaveType::BASIS_CALENDAR, 'eligible_gender' => LeaveType::GENDER_FEMALE, 'requires_attachment' => true],
            ['code' => 'PATERNITY', 'name' => 'Paternity Leave', 'description' => 'Para sa lalaking empleyado na may asawang nanganak. Saklaw ng Philippine law kung qualified.', 'color' => '#2563EB', 'annual_entitlement' => 7, 'accrual_method' => LeaveType::ACCRUAL_PER_EVENT, 'eligible_gender' => LeaveType::GENDER_MALE, 'requires_attachment' => true],
            ['code' => 'SOLO-PARENT', 'name' => 'Parental Leave for Solo Parents', 'description' => 'Para sa empleyadong may valid Solo Parent ID. Pito (7) araw kada taon, hiwalay sa ibang leave credits.', 'color' => '#C2410C', 'annual_entitlement' => 7, 'min_service_months' => 6, 'requires_designation' => LeaveType::DESIGNATION_SOLO_PARENT, 'requires_attachment' => true],
            ['code' => 'VAWC', 'name' => 'VAWC Leave', 'description' => 'Para sa empleyadong biktima ng violence against women and their children. Sampung (10) araw kada insidente.', 'color' => '#9D174D', 'annual_entitlement' => 10, 'accrual_method' => LeaveType::ACCRUAL_PER_EVENT, 'requires_attachment' => true],
            ['code' => 'SPECIAL-WOMEN', 'name' => 'Special Leave for Women (Magna Carta)', 'description' => 'Para sa gynecological surgery. Hanggang animnapung (60) araw kada operasyon, batay sa medical certificate.', 'color' => '#A21CAF', 'annual_entitlement' => 60, 'accrual_method' => LeaveType::ACCRUAL_PER_EVENT, 'day_basis' => LeaveType::BASIS_CALENDAR, 'eligible_gender' => LeaveType::GENDER_FEMALE, 'min_service_months' => 6, 'requires_attachment' => true],
        ];
    }

    /**
     * Offered by the handbook, and the company's to change. No statutory
     * service incentive leave type appears here on purpose: the Labour Code
     * excludes employees already enjoying at least five days of paid vacation
     * from that provision, so the 15-day allowance below discharges it. A
     * separate five-day SIL type would hand covered employees days they are
     * not owed, which is what satisfies_sil records instead.
     *
     * @return array<int, array<string, mixed>>
     */
    private function companyTypes(): array
    {
        return [
            ['code' => 'VAC', 'name' => 'Vacation Leave', 'color' => '#2F80ED', 'annual_entitlement' => 15, 'max_carry_over' => 5, 'satisfies_sil' => true, 'requires_attachment' => false],
            ['code' => 'SICK', 'name' => 'Sick Leave', 'color' => '#D6455D', 'annual_entitlement' => 15, 'max_carry_over' => 5, 'requires_attachment' => true],
            ['code' => 'EMER', 'name' => 'Emergency Leave', 'color' => '#D98B14', 'annual_entitlement' => 5, 'max_carry_over' => 0, 'requires_attachment' => false],
            ['code' => 'SPECIAL-PRIVILEGE', 'name' => 'Special Leave (Special Privilege Leave)', 'description' => 'Para sa personal matters, birthdays, relocation, at iba pang pinapayagan ng kumpanya.', 'color' => '#7C3AED', 'annual_entitlement' => 3, 'max_carry_over' => 0, 'requires_attachment' => false],
            ['code' => 'BEREAVEMENT', 'name' => 'Bereavement Leave', 'description' => 'Kapag namatay ang immediate family member.', 'color' => '#475569', 'annual_entitlement' => 3, 'max_carry_over' => 0, 'requires_attachment' => false],
            ['code' => 'STUDY', 'name' => 'Study Leave', 'description' => 'Para sa trainings, seminars, board exams, o postgraduate studies. Karaniwang kailangan ng HR approval.', 'color' => '#0891B2', 'annual_entitlement' => 5, 'max_carry_over' => 0, 'requires_attachment' => true],
            ['code' => 'UNPAID', 'name' => 'Unpaid Leave (Leave Without Pay)', 'description' => 'Kapag wala nang available leave credits o hindi covered ng ibang leave types.', 'color' => '#6B7280', 'annual_entitlement' => 0, 'accrual_method' => LeaveType::ACCRUAL_UNLIMITED, 'max_carry_over' => 0, 'requires_attachment' => false],
            ['code' => 'COMP-OFF', 'name' => 'Compensatory Leave (Comp-Off)', 'description' => 'Kapalit ng approved overtime o work during holidays/rest days, kung pinapayagan ng policy.', 'color' => '#059669', 'annual_entitlement' => 0, 'accrual_method' => LeaveType::ACCRUAL_UNLIMITED, 'max_carry_over' => 0, 'requires_attachment' => true],
        ];
    }
}
