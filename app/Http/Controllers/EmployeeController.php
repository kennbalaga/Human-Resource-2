<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Requests\Organization\SaveEmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\Attendance\AttendanceQrService;
use App\Services\Organization\EmployeeNumberGenerator;
use App\Services\Organization\EmployeeNumberSettings;
use App\Support\Qr\QrEncoder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class EmployeeController extends Controller
{
    use ScopesWorkforceAccess;

    public function __construct(
        private readonly EmployeeNumberGenerator $employeeNumberGenerator,
        private readonly EmployeeNumberSettings $employeeNumberSettings,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
        ]);
        $query = Employee::query()->with(['user', 'department', 'position', 'supervisor']);

        $query
            ->when($filters['search'] ?? null, function (Builder $builder, string $search) use ($request): void {
                // Matching on the email column is itself a disclosure: it lets
                // any signed-in account confirm a colleague's address by
                // probing for it, one guess at a time. Name and employee number
                // are what the directory is for and stay open to everyone.
                $canSearchEmail = Gate::forUser($request->user())->allows('workforce.view');

                $builder->where(function (Builder $searchQuery) use ($search, $canSearchEmail): void {
                    $searchQuery
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->when($canSearchEmail, fn (Builder $query) => $query
                            ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('email', 'like', "%{$search}%")));
                });
            })
            ->when($filters['department_id'] ?? null, fn (Builder $builder, int $departmentId) => $builder->where('department_id', $departmentId))
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('employment_status', $status));

        $canManage = $this->canWrite($request);
        $employees = $query->orderBy('last_name')->orderBy('first_name')->paginate(15)->withQueryString();

        if ($request->ajax()) {
            return view('employees._table', [
                'employees' => $employees,
                'canManage' => $canManage,
            ]);
        }

        return view('employees.index', [
            'employees' => $employees,
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(),
            'filters' => $filters,
            'canManage' => $canManage,
            'canSearchEmail' => Gate::forUser($request->user())->allows('workforce.view'),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
            // The add-employee modal lives on this page, so the directory needs
            // the same option lists the create page builds — but only for the
            // roles that actually get the modal.
        ] + ($canManage ? $this->formData($request) : []));
    }

    public function show(Request $request, Employee $employee): View
    {
        $employee->load(['user', 'department', 'position', 'supervisor', 'directReports']);
        $canViewPrivate = $this->canManage($request) || $request->user()->employee?->is($employee);
        $canReissueAttendanceQr = $this->canWrite($request);

        return view('employees.show', [
            'employee' => $employee,
            'canManage' => $this->canWrite($request),
            'canViewPrivate' => $canViewPrivate,
            // The badge panel is the only thing that renders this, and it is
            // already gated on the same flag. Building the payload regardless
            // meant every viewer of this page was handed a working credential
            // in the view data whether or not the markup showed it.
            'attendanceQrSvg' => $canReissueAttendanceQr
                ? QrEncoder::svg(app(AttendanceQrService::class)->payloadFor($employee))
                : null,
            'canReissueAttendanceQr' => $canReissueAttendanceQr,
            'canResetTwoFactor' => $request->user()->hasRole('system-administrator')
                && $employee->user !== null
                && ! $employee->user->is($request->user())
                && $employee->user->two_factor_secret !== null,
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    /**
     * Retire the badge this employee has been carrying and issue a new one.
     * The recall for a badge that has been lost, shared, or photographed —
     * every printed copy of the old code stops scanning the moment this runs,
     * which is why it sits with HR rather than with the badge holder.
     */
    public function reissueAttendanceQr(
        Request $request,
        Employee $employee,
        AttendanceQrService $codes,
    ): RedirectResponse {
        $this->requireManager($request);
        $codes->regenerate($employee);

        return redirect()
            ->route('employees.show', $employee)
            ->with('success', "A new attendance badge was issued for {$employee->full_name}. Their previous code no longer scans — ask them to download the new one.");
    }

    public function create(Request $request): View
    {
        $this->requireManager($request);

        return view('employees.create', $this->formData($request));
    }

    public function store(SaveEmployeeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $temporaryPassword = $this->initialEmployeePassword();
        $autoGenerateEmployeeNumber = $this->employeeNumberSettings->autoGenerateEnabled();

        $employee = DB::transaction(function () use ($data, $temporaryPassword, $autoGenerateEmployeeNumber): Employee {
            if ($autoGenerateEmployeeNumber) {
                $data['employee_number'] = $this->employeeNumberGenerator->generateForPosition(
                    (int) $data['position_id'],
                    (string) $data['hire_date'],
                );
            }

            $user = User::query()->create([
                'name' => $this->displayName($data),
                'email' => $data['email'],
                'password' => $temporaryPassword,
                'is_active' => $this->accountIsActive($data['employment_status']),
            ]);
            $this->syncRoleForPosition($user, (int) $data['position_id']);

            return Employee::query()->create($this->employeeData($data) + ['user_id' => $user->id]);
        });

        $setupEmailSent = $this->sendPasswordSetupLink($employee->user);
        $message = 'Employee account created successfully.';

        if ($setupEmailSent) {
            $message .= ' A secure password setup link was sent to the work email.';
        } else {
            $message .= ' The password setup email could not be sent; the employee may use Forgot Password later.';
        }
        if ($this->usesLocalEmployeeDefaultPassword()) {
            $message .= ' This local/testing environment uses the configured development default password.';
        }

        return redirect()->route('employees.show', $employee)->with('success', $message);
    }

    public function edit(Request $request, Employee $employee): View
    {
        $this->requireManager($request);

        return view('employees.edit', $this->formData($request, $employee) + [
            'employee' => $employee->load('user'),
        ]);
    }

    public function update(SaveEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $employee): void {
            $user = $employee->user;

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $this->displayName($data),
                    'email' => $data['email'],
                    'password' => $this->initialEmployeePassword(),
                    'is_active' => $this->accountIsActive($data['employment_status']),
                ]);
                $employee->user()->associate($user);
            } else {
                $emailChanged = $user->email !== $data['email'];
                $user->update([
                    'name' => $this->displayName($data),
                    'email' => $data['email'],
                    'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
                    'is_active' => $this->accountIsActive($data['employment_status']),
                ]);
            }

            $employee->fill($this->employeeData($data))->save();
            $this->syncRoleForPosition($user, (int) $data['position_id']);
        });

        return redirect()->route('employees.show', $employee)->with('success', 'Employee profile updated successfully.');
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, ?Employee $employee = null): array
    {
        return [
            'departments' => Department::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)->when($employee, fn (Builder $nested) => $nested->orWhere('id', $employee->department_id)))
                ->orderBy('name')
                ->get(),
            'positions' => Position::query()
                ->with('department')
                ->where(fn (Builder $query) => $query->where('is_active', true)->when($employee, fn (Builder $nested) => $nested->orWhere('id', $employee->position_id)))
                ->orderBy('title')
                ->get(),
            'supervisors' => Employee::query()->where('employment_status', 'active')->orderBy('last_name')->get(),
            'employeeNumberAutoGenerate' => $this->employeeNumberSettings->autoGenerateEnabled(),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ];
    }

    /** @param array<string, mixed> $data */
    private function employeeData(array $data): array
    {
        return collect($data)->only([
            'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix',
            'department_id', 'position_id', 'supervisor_id', 'employment_status',
            'hire_date', 'contact_number', 'address',
        ])->all();
    }

    /** @param array<string, mixed> $data */
    private function displayName(array $data): string
    {
        return collect([$data['first_name'], $data['middle_name'], $data['last_name'], $data['suffix']])->filter()->implode(' ');
    }

    private function accountIsActive(string $status): bool
    {
        return in_array($status, ['active', 'on_leave'], true);
    }

    private function syncRoleForPosition(User $user, int $positionId): void
    {
        $positionCode = Position::query()->whereKey($positionId)->value('code');
        $roleSlug = match ($positionCode) {
            'SYS-ADMIN' => 'system-administrator',
            'HR-MGR' => 'hr-manager',
            'NUR-HEAD' => 'department-head',
            default => 'employee',
        };

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id]);
    }

    private function sendPasswordSetupLink(User $user): bool
    {
        try {
            return Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
        } catch (Throwable $exception) {
            Log::warning('The employee onboarding email could not be sent.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function initialEmployeePassword(): string
    {
        if (! $this->usesLocalEmployeeDefaultPassword()) {
            return Str::password(40);
        }

        $password = (string) config('workforce.local_employee_default_password');
        if (strlen($password) < 12) {
            throw new RuntimeException('LOCAL_EMPLOYEE_DEFAULT_PASSWORD must contain at least 12 characters.');
        }

        return $password;
    }

    private function usesLocalEmployeeDefaultPassword(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * Who may see the whole directory, including private fields. Read-only
     * administrators are included: they keep the visibility, not the pen.
     */
    private function canManage(Request $request): bool
    {
        return $request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty();
    }

    private function canWrite(Request $request): bool
    {
        return $this->canManage($request) && $request->user()->canManageData();
    }

    private function requireManager(Request $request): void
    {
        abort_unless($this->canWrite($request), 403);
    }
}
