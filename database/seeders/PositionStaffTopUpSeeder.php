<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Tops up every position to at least 10 sample employees. The earlier
 * top-up seeders (SamplePositionStaffSeeder, EmptyDepartmentStaffSeeder,
 * NursingStaffSeeder) hand-typed 3-5 rows per position; getting every one of
 * the ~32 positions to 10 needs roughly 200 more rows, too many to hand-type
 * individually, so names are drawn from fixed pools and combined
 * deterministically (position code + running index) rather than sourced from
 * a random Faker seed. That keeps a re-run idempotent: the same position and
 * index always produce the same employee_number, so updateOrCreate lands on
 * the same row instead of appending duplicates, and the deficit is 0 (a
 * no-op) once a position already has 10.
 */
class PositionStaffTopUpSeeder extends Seeder
{
    private const TARGET_PER_POSITION = 10;

    private const FIRST_NAMES = [
        'Maria', 'Jose', 'Juan', 'Ana', 'Pedro', 'Rosa', 'Antonio', 'Carmen', 'Francisco', 'Elena',
        'Ramon', 'Grace', 'Patricia', 'Daniel', 'Angelica', 'Mark', 'Jasmine', 'Sofia', 'Benjamin', 'Camille',
        'Nathaniel', 'Bianca', 'Julius', 'Kevin', 'Andrea', 'Francis', 'Isabel', 'Victor', 'Diana', 'Alyssa',
        'Christian', 'Kimberly', 'Alexander', 'Regina', 'Miguel', 'Cecilia', 'Rodrigo', 'Vanessa', 'Edgar', 'Marites',
        'Samuel', 'Precious', 'Bryan', 'Loida', 'Rowena', 'Emmanuel', 'Divina', 'Gerald', 'Fe', 'Noel',
        'Arnold', 'Ligaya', 'Wilfredo', 'Charmaine', 'Reynaldo', 'Michelle', 'Ferdinand', 'Susan', 'Leo', 'Katrina',
        'Dennis', 'Marilou', 'Oscar', 'Belinda', 'Ronald', 'Cherry', 'Timothy', 'Josephine', 'Adrian', 'Estrella',
        'Gilbert', 'Angeline', 'Bartolome', 'Karen', 'Roberto', 'Josefina', 'Cristina', 'Corazon', 'Ricardo', 'Teresa',
        'Rosario', 'Salazar', 'Domingo', 'Aquino', 'Manuel', 'Carlos', 'Luz', 'Roberta', 'Eduardo', 'Beatriz',
        'Felipe', 'Consuelo', 'Alfredo', 'Remedios', 'Rafael', 'Milagros', 'Gregorio', 'Perpetua', 'Ernesto', 'Soledad',
    ];

    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Gonzales', 'Ramos',
        'Villanueva', 'Fernandez', 'Villareal', 'Navarro', 'Ramirez', 'Ortiz', 'Aguilar', 'Manalo', 'Lopez', 'Dizon',
        'Mercado', 'Aquino', 'Santiago', 'Morales', 'Padilla', 'Marquez', 'Concepcion', 'Bonifacio', 'Espino', 'Tolentino',
        'Rivera', 'Gutierrez', 'Yap', 'Umali', 'Ferrer', 'Sarmiento', 'Castro', 'Marasigan', 'Salonga', 'Villaflor',
        'Abellana', 'Peralta', 'Rosales', 'Nepomuceno', 'Dungca', 'Gatchalian', 'Buenaventura', 'Lacson', 'Manalastas', 'Valderrama',
        'Cabrera', 'Ilagan', 'Panganiban', 'Trinidad', 'Macatangay', 'Sison', 'Malabanan', 'Ocampo', 'Aranda', 'Corpuz',
        'Villagomez', 'Nazareno', 'Roque', 'Ilustre', 'Mangubat', 'Domingo', 'Flores', 'Castillo', 'Del Rosario', 'Cruz',
        'Custodio', 'Enriquez', 'Herrera', 'Ignacio', 'Jimenez', 'Katigbak', 'Lazaro', 'Magsino', 'Nieves', 'Olegario',
        'Pineda', 'Quiambao', 'Roldan', 'Sotto', 'Tuazon', 'Uy', 'Vergara', 'Wenceslao', 'Ynares', 'Zamora',
    ];

    public function run(): void
    {
        $employeeRole = Role::query()->where('slug', 'employee')->firstOrFail();
        $defaultPassword = app()->environment(['local', 'testing'])
            ? (string) config('workforce.local_employee_default_password')
            : Str::password(40);

        $usedNames = User::query()->pluck('name')->flip()->all();
        $usedEmails = User::query()->pluck('email')->flip()->all();

        $positions = Position::query()->withCount('employees')->with('department')->orderBy('id')->get();

        foreach ($positions as $position) {
            $deficit = self::TARGET_PER_POSITION - $position->employees_count;
            if ($deficit <= 0) {
                continue;
            }

            $department = $position->department;
            if ($department === null) {
                continue;
            }

            for ($n = $position->employees_count + 1; $n <= self::TARGET_PER_POSITION; $n++) {
                $this->createStaffMember($position, $department, $n, $employeeRole, $defaultPassword, $usedNames, $usedEmails);
            }
        }
    }

    /** @param array<string, int> $usedNames @param array<string, int> $usedEmails */
    private function createStaffMember(
        Position $position,
        Department $department,
        int $index,
        Role $employeeRole,
        string $defaultPassword,
        array &$usedNames,
        array &$usedEmails,
    ): void {
        $seed = crc32($position->code.'-'.$index);
        [$firstName, $lastName] = $this->pickUniqueName($seed, $usedNames);
        $fullName = $firstName.' '.$lastName;
        $usedNames[$fullName] = 1;

        $email = $this->uniqueEmail($firstName, $lastName, $seed, $usedEmails);
        $usedEmails[$email] = 1;

        $employeeNumber = strtoupper($position->code).'-2026-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $status = $seed % 11 === 0 ? 'on_leave' : 'active';
        $hireDate = sprintf('2026-%02d-%02d', 1 + ($seed % 8), 1 + (($seed >> 3) % 27));
        $contactNumber = '0917'.str_pad((string) (1235000 + ($seed % 8000000)), 7, '0', STR_PAD_LEFT);

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $fullName,
                'password' => $defaultPassword,
                'is_active' => in_array($status, ['active', 'on_leave'], true),
            ],
        );
        $user->roles()->syncWithoutDetaching([$employeeRole->id]);

        Employee::query()->updateOrCreate(
            ['employee_number' => $employeeNumber],
            [
                'user_id' => $user->id,
                'department_id' => $department->id,
                'position_id' => $position->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'employment_status' => $status,
                'hire_date' => $hireDate,
                'contact_number' => $contactNumber,
                'address' => 'Manila, Philippines',
            ],
        );
    }

    /** @param array<string, int> $usedNames @return array{0: string, 1: string} */
    private function pickUniqueName(int $seed, array $usedNames): array
    {
        $firstCount = count(self::FIRST_NAMES);
        $lastCount = count(self::LAST_NAMES);

        for ($attempt = 0; $attempt < $firstCount * $lastCount; $attempt++) {
            $offset = $seed + $attempt;
            $firstName = self::FIRST_NAMES[$offset % $firstCount];
            $lastName = self::LAST_NAMES[intdiv($offset, $firstCount) % $lastCount];

            if (! isset($usedNames[$firstName.' '.$lastName])) {
                return [$firstName, $lastName];
            }
        }

        // Pools exhausted (should not happen at this sample size): fall back
        // to a name that is unique by construction.
        return [self::FIRST_NAMES[$seed % $firstCount], self::LAST_NAMES[$seed % $lastCount].' '.$seed];
    }

    /** @param array<string, int> $usedEmails */
    private function uniqueEmail(string $firstName, string $lastName, int $seed, array $usedEmails): string
    {
        $base = str_replace(' ', '', strtolower($firstName.'.'.$lastName));
        $email = $base.'@hrms.local';

        $suffix = 2;
        while (isset($usedEmails[$email])) {
            $email = $base.$suffix.'@hrms.local';
            $suffix++;
        }

        return $email;
    }
}
