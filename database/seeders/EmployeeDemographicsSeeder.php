<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * Fills in the two employee attributes the statutory leave gates read: gender,
 * and a solo parent ID. Both are demo values for the seeded workforce -- these
 * are fictional personas, not staff records -- and both exist so the leave
 * rules can actually be seen working.
 *
 * Gender is resolved from an explicit table rather than guessed at runtime. A
 * heuristic would be wrong for a handful of the names here and there would be
 * no record of which ones, whereas a table can be read, corrected, and argued
 * with. It decides who may claim maternity, paternity, and special leave for
 * women, so it is worth being able to audit.
 *
 * Anything the table does not know is reported and left null. Null is the
 * honest answer for an unrecorded attribute, and the leave check already reads
 * it as "ask HR" rather than as a refusal.
 */
class EmployeeDemographicsSeeder extends Seeder
{
    /**
     * Role placeholders rather than people -- the seeded login accounts for HR,
     * nursing, and the administrator. Skipped deliberately: a service account
     * has no business being eligible for maternity leave.
     *
     * @var array<int, string>
     */
    private const SERVICE_ACCOUNT_NAMES = ['hr', 'nursing', 'system'];

    /**
     * Demo solo parent IDs, keyed by employee number so a re-seed lands on the
     * same people. Deliberately a small minority of the workforce: the whole
     * point of the designation is that most staff do not hold it, and marking
     * everybody would hide the very behaviour it gates.
     *
     * NUR-STAFF-SURG-2026-0009 carries a lapsed ID on purpose, so the expiry
     * path has something to demonstrate alongside the valid ones.
     *
     * @var array<string, array{number: string, expires_on: string}>
     */
    private const SOLO_PARENT_IDS = [
        'HR-MGR-2026-0012' => ['number' => 'SP-2026-0001', 'expires_on' => '+1 year'],
        'MEDSOC-WORKER-2026-0005' => ['number' => 'SP-2026-0002', 'expires_on' => '+1 year'],
        'NUR-STAFF-DERM-2026-0010' => ['number' => 'SP-2026-0003', 'expires_on' => '+1 year'],
        'PHARM-CHIEF-2026-0013' => ['number' => 'SP-2026-0004', 'expires_on' => '+2 years'],
        'HR-OFFICER-2026-0007' => ['number' => 'SP-2026-0005', 'expires_on' => '+1 year'],
        'NUR-HEAD-SURG-2026-0010' => ['number' => 'SP-2026-0006', 'expires_on' => '+1 year'],
        'ER-PHYSICIAN-2026-0010' => ['number' => 'SP-2026-0007', 'expires_on' => '+2 years'],
        'PROC-MGR-2026-0011' => ['number' => 'SP-2026-0008', 'expires_on' => '+1 year'],
        'NUR-STAFF-SURG-2026-0009' => ['number' => 'SP-2024-0009', 'expires_on' => '-6 months'],
    ];

    public function run(): void
    {
        $this->seedGender();
        $this->seedSoloParentIds();
    }

    private function seedGender(): void
    {
        $table = self::genderByFirstName();
        $unmapped = [];

        foreach (Employee::query()->get() as $employee) {
            $key = mb_strtolower(trim((string) $employee->first_name));

            if (in_array($key, self::SERVICE_ACCOUNT_NAMES, true)) {
                $employee->update(['gender' => null]);

                continue;
            }

            if (! isset($table[$key])) {
                $unmapped[$key] = ($unmapped[$key] ?? 0) + 1;

                continue;
            }

            $employee->update(['gender' => $table[$key]]);
        }

        if ($unmapped !== []) {
            // Loud rather than silent. A name nobody mapped leaves an employee
            // unable to claim the leave they may be entitled to, and that is
            // worth someone's attention rather than a null nobody notices.
            $this->command?->warn('Gender left unset for unmapped first names: '.collect($unmapped)
                ->map(fn (int $count, string $name) => "{$name} ({$count})")->implode(', '));
        }
    }

    private function seedSoloParentIds(): void
    {
        foreach (self::SOLO_PARENT_IDS as $employeeNumber => $id) {
            $employee = Employee::query()->where('employee_number', $employeeNumber)->first();

            if ($employee === null) {
                $this->command?->warn("Solo parent ID skipped, no employee {$employeeNumber}.");

                continue;
            }

            $employee->update([
                'solo_parent_id_number' => $id['number'],
                'solo_parent_id_expires_on' => now()->modify($id['expires_on'])->toDateString(),
            ]);
        }
    }

    /**
     * The seeded workforce's first names, keyed lowercase.
     *
     * @return array<string, string>
     */
    private static function genderByFirstName(): array
    {
        $female = [
            'alyssa', 'ana', 'andrea', 'angelica', 'angeline', 'beatriz', 'belinda', 'bianca',
            'camille', 'carmen', 'cecilia', 'charmaine', 'cherry', 'consuelo', 'corazon',
            'cristina', 'diana', 'divina', 'elena', 'estrella', 'fe', 'grace', 'isabel',
            'jasmine', 'josefina', 'josephine', 'karen', 'katrina', 'kimberly', 'ligaya',
            'loida', 'luz', 'maria', 'marilou', 'marites', 'michelle', 'milagros', 'patricia',
            'perpetua', 'precious', 'queency', 'regina', 'remedios', 'roberta', 'rosa',
            'rosario', 'rowena', 'sofia', 'soledad', 'susan', 'teresa', 'vanessa',
        ];

        // Aquino, Bartolome and Salazar reach the first-name column as an
        // artefact of the staff seeders' name pool, which mixed surnames in.
        // Read as given names they are male, which is how they are recorded.
        $male = [
            'adrian', 'alexander', 'antonio', 'aquino', 'arnold', 'bartolome', 'benjamin',
            'bryan', 'carlos', 'christian', 'daniel', 'dennis', 'domingo', 'edgar', 'eduardo',
            'emmanuel', 'ernesto', 'felipe', 'ferdinand', 'francis', 'francisco', 'gerald',
            'gilbert', 'gregorio', 'john', 'jose', 'juan', 'julius', 'kevin', 'kwensi', 'leo',
            'louie', 'manuel', 'mark', 'miguel', 'nathaniel', 'noel', 'oscar', 'pedro',
            'rafael', 'raffy', 'ramon', 'reynaldo', 'ricardo', 'roberto', 'rodrigo', 'ronald',
            'salazar', 'samuel', 'timothy', 'vergel', 'victor', 'walter', 'wilfredo',
        ];

        return array_merge(
            array_fill_keys($female, LeaveType::GENDER_FEMALE),
            array_fill_keys($male, LeaveType::GENDER_MALE),
        );
    }
}
