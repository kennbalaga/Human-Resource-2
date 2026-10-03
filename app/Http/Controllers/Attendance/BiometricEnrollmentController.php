<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AssignBiometricPinRequest;
use App\Http\Requests\Attendance\BulkAssignBiometricPinsRequest;
use App\Http\Requests\Attendance\UpdateBiometricEnrollmentRequest;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Services\Attendance\BiometricEnrollmentService;
use App\Support\SpreadsheetExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The enrolment roster: who has a PIN reserved on which terminal, and whose
 * fingerprint has actually been captured.
 *
 * The roster is driven from `employees`, not from `biometric_enrollments`, and
 * that is the whole point of the page. The people who matter most here are the
 * ones with no row yet -- a query over the enrolments table cannot show them,
 * and "who still needs enrolling" is the question somebody commissioning a
 * terminal is actually asking.
 */
class BiometricEnrollmentController extends Controller
{
    public function index(Request $request, BiometricEnrollmentService $service): View
    {
        $this->authorizeEnrollmentAccess($request);

        $filters = $this->filters($request);
        $devices = BiometricDevice::query()->with('officeLocation')->orderBy('name')->get();
        $device = $this->selectedDevice($devices, $filters);

        return view('settings.biometric-terminals.index', [
            'devices' => $devices,
            'device' => $device,
            'offices' => OfficeLocation::query()->where('is_active', true)->orderBy('name')->get(),
            'roster' => $device === null
                ? null
                : $this->query($device, $filters)->paginate(25)->withQueryString(),
            'summary' => $device === null ? null : $this->summary($device, $filters),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'templatesToRemove' => $device === null ? collect() : $service->templatesToRemove($device),
            'nonConformingPins' => $device === null ? collect() : $service->nonConformingPins($device),
            'filters' => $filters,
            'activeFilters' => $this->describeActiveFilters($filters),
        ]);
    }

    public function export(Request $request, BiometricEnrollmentService $service): StreamedResponse
    {
        $this->authorizeEnrollmentAccess($request);

        $filters = $this->filters($request);
        $devices = BiometricDevice::query()->orderBy('name')->get();
        $device = $this->selectedDevice($devices, $filters);

        abort_if($device === null, 404, 'No biometric terminal is registered yet.');

        if (($filters['format'] ?? 'roster') === 'zktime') {
            return $this->exportForZkTime($device, $filters, $service);
        }

        // lazy() rather than cursor(), so the eager loads are honoured chunk by
        // chunk instead of costing a query per row.
        $employees = $this->query($device, $filters)->lazy(500);
        $filename = 'biometric-enrolment-roster-'.$device->code.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($employees, $service): void {
            $output = fopen('php://output', 'w');

            SpreadsheetExport::writeCsvRow($output, [
                'Device PIN', 'Employee Number', 'Last Name', 'First Name', 'Department',
                'Position', 'Enrolment State', 'PIN Assigned On', 'Template Captured On',
                'Enrolled By (sign here)',
            ]);

            foreach ($employees as $employee) {
                $enrollment = $employee->biometricEnrollments->first();

                SpreadsheetExport::writeCsvRow($output, [
                    $service->pinFor($employee),
                    $employee->employee_number,
                    $employee->last_name,
                    $employee->first_name,
                    $employee->department?->name,
                    $employee->position?->title,
                    $this->stateLabel($enrollment),
                    $enrollment?->created_at?->toDateString(),
                    $enrollment?->enrolled_at?->toDateString(),
                    // Left blank deliberately. The sheet is the artifact the
                    // enroller signs at the terminal, and with no check digit
                    // in the PIN scheme, reading the number off a printed line
                    // rather than from memory is one of the few controls there
                    // is against a mis-keyed PIN.
                    '',
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The same roster shaped for ZKTime's "Import data wizard".
     *
     * ZKTime is used for one job here: creating the user records on the
     * terminal so that nobody has to key a PIN in by hand while a nurse stands
     * waiting. Typing it is where the mis-keyed PIN comes from, and the PIN
     * scheme has no check digit to catch one, so removing the typing is the
     * cheapest real control available.
     *
     * The columns are named exactly as ZKTime's Employee List names its fields,
     * because the wizard asks you to map source columns onto them and matching
     * names make that mapping obvious rather than a guess:
     *
     *   AC No.  the device user id -- our device PIN, and the one column that
     *           must be right. It is what a punch arrives as, so a wrong value
     *           here files somebody's attendance against a colleague.
     *   Name    one column, not the two the human roster uses: ZKTime holds a
     *           single Name field, and it is what the terminal shows on a scan.
     *   No.     ZKTime's own separate employee-number field, which is where the
     *           DJNRMHS number belongs -- it does not fit AC No.
     *   Department  which node the user is filed under. Not cosmetic: the
     *           upload dialog filters by department, and a user filed under
     *           none is invisible to it.
     *   Title   the position, for recognising somebody in the list.
     *   Privilege always "User". See the note on the column below.
     *
     * ZKTime's Employee List carries far more columns than these -- AccGroup,
     * Verify, TimeZone1-3, ValidTimeBegin/End, IDCardNo, Password and the rest.
     * They are deliberately absent. They are access-control settings belonging
     * to the device, not facts this application holds, and writing a guess into
     * them would be this roster quietly deciding who may pass a door and when.
     * FingerCountV9 and FingerCountV10.0 could not be supplied even in
     * principle: they count templates the device itself holds. The import is a
     * mapping wizard, so columns it is not given are simply left alone.
     *
     * Only people who already hold an active enrolment here are included. A
     * terminal should not know a user this roster has not reserved a PIN for:
     * the two would disagree, and a punch from that user would arrive unmatched
     * with nothing to explain it.
     *
     * The page filters still apply, so one ward can be exported, enrolled and
     * watched before the rest follow.
     */
    private function exportForZkTime(
        BiometricDevice $device,
        array $filters,
        BiometricEnrollmentService $service,
    ): StreamedResponse {
        $employees = $this->query($device, $filters)
            ->whereHas('biometricEnrollments', fn (Builder $query) => $query
                ->where('biometric_device_id', $device->id)
                ->where('is_active', true))
            ->lazy(500);

        $filename = 'zktime-import-'.$device->code.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($employees, $service): void {
            $output = fopen('php://output', 'w');

            SpreadsheetExport::writeCsvRow($output, ['AC No.', 'Name', 'Department', 'No.', 'Title', 'Privilege']);

            foreach ($employees as $employee) {
                SpreadsheetExport::writeCsvRow($output, [
                    $service->pinFor($employee),
                    // "Last, First" rather than the roster's full name: it is
                    // how somebody looks a person up on a list, and it matches
                    // the order this page is sorted in. The terminal may
                    // truncate a long name on its own screen -- that is
                    // cosmetic, since AC No. is what attendance is filed
                    // against, not the label.
                    trim($employee->last_name.', '.$employee->first_name),
                    // ZKTime files users under a department and its dialogs
                    // filter by one, so a row imported without this lands
                    // outside every node: the Employee List still shows it, but
                    // "From PC To Device" finds nobody to send and the upload
                    // looks like it simply has no users. The name has to match
                    // one in ZKTime's own Department List, or the employees
                    // need moving with its `transfer` button afterwards.
                    $employee->department?->name,
                    $employee->employee_number,
                    $employee->position?->title,
                    // Stated rather than left to a default. ZKTime's Employee
                    // List has a Privilege field whose other values make
                    // somebody an administrator *of the terminal* -- able to
                    // enrol, delete users and open its menus. Nobody acquires
                    // that by being imported from an HR roster, and the comm
                    // key on this unit is still the factory default, so the
                    // column is written out explicitly on every row instead of
                    // trusting whatever an unmapped field falls back to.
                    'User',
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function store(AssignBiometricPinRequest $request, BiometricEnrollmentService $service): RedirectResponse
    {
        $device = BiometricDevice::query()->findOrFail($request->validated('biometric_device_id'));
        $employee = Employee::query()->findOrFail($request->validated('employee_id'));

        $enrollment = $service->assign($device, $employee);

        return redirect()
            ->route('settings.biometric-terminals.index', ['device' => $device->id])
            ->with('success', sprintf(
                'PIN %s is reserved for %s. Capture their fingerprint at %s — nothing is recorded as enrolled until a scan arrives.',
                $enrollment->external_user_id,
                $employee->full_name,
                $device->name,
            ));
    }

    public function bulk(BulkAssignBiometricPinsRequest $request, BiometricEnrollmentService $service): RedirectResponse
    {
        $device = BiometricDevice::query()->findOrFail($request->validated('biometric_device_id'));

        $result = $service->assignAllActive($device);

        return redirect()
            ->route('settings.biometric-terminals.index', ['device' => $device->id])
            ->with('success', sprintf(
                '%d PIN(s) newly reserved on %s. %d of %d now have a fingerprint captured.',
                $result['assigned'],
                $device->name,
                $result['captured'],
                $result['total'],
            ));
    }

    public function update(
        UpdateBiometricEnrollmentRequest $request,
        BiometricEnrollment $biometricEnrollment,
        BiometricEnrollmentService $service,
    ): RedirectResponse {
        $name = $biometricEnrollment->loadMissing('employee')->employee?->full_name ?? 'This employee';

        $message = match ($request->validated('action')) {
            'capture' => $this->applyCapture($service, $biometricEnrollment, $name),
            'release' => $this->applyRelease($service, $biometricEnrollment, $name),
            'deactivate' => $this->applyDeactivate($service, $biometricEnrollment, $name),
            'reactivate' => $this->applyReactivate($service, $biometricEnrollment, $name),
        };

        return redirect()
            ->route('settings.biometric-terminals.index', ['device' => $biometricEnrollment->biometric_device_id])
            ->with('success', $message);
    }

    private function applyCapture(BiometricEnrollmentService $service, BiometricEnrollment $enrollment, string $name): string
    {
        $service->markCaptured($enrollment);

        return $name."'s fingerprint is recorded as captured.";
    }

    private function applyRelease(BiometricEnrollmentService $service, BiometricEnrollment $enrollment, string $name): string
    {
        $service->markNotCaptured($enrollment);

        return $name."'s PIN stays reserved, and their fingerprint needs capturing again.";
    }

    private function applyDeactivate(BiometricEnrollmentService $service, BiometricEnrollment $enrollment, string $name): string
    {
        $service->deactivate($enrollment);

        // Said every time, because deactivating here changes nothing on the
        // device: the template sits on the terminal until somebody deletes it.
        return $name."'s enrolment is retired. Delete them from the terminal itself as well — this does not reach the device.";
    }

    private function applyReactivate(BiometricEnrollmentService $service, BiometricEnrollment $enrollment, string $name): string
    {
        $service->reactivate($enrollment);

        return $name."'s enrolment is active again.";
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    private function query(BiometricDevice $device, array $filters): Builder
    {
        return Employee::query()
            ->with(['department:id,name', 'position:id,title'])
            // A filtered hasMany holding at most one row per employee, because
            // of biometric_enrollments_device_employee_unique. The view reads
            // ->first() on it.
            ->with(['biometricEnrollments' => fn (HasMany $query) => $query->where('biometric_device_id', $device->id)])
            // whereHas rather than a join, deliberately: scopeNotArchived() and
            // scopeMatchingSearch() both use unqualified column names, and a
            // join would make archived_at and employee_number ambiguous on
            // MySQL while passing on SQLite under test.
            ->when(
                ($filters['employment'] ?? 'active') === 'active',
                fn (Builder $query) => $query->where('employment_status', 'active')->notArchived(),
                fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                    ->where('employment_status', '!=', 'active')
                    ->orWhereNotNull('archived_at')),
            )
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->matchingSearch($search))
            ->when($filters['department_id'] ?? null, fn (Builder $query, $id) => $query->where('department_id', $id))
            ->when($filters['state'] ?? null, fn (Builder $query, string $state) => $this->scopeState($query, $device, $state))
            ->orderBy('last_name')
            ->orderBy('first_name');
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private function scopeState(Builder $query, BiometricDevice $device, string $state): Builder
    {
        $onThisDevice = fn (Builder $enrollment) => $enrollment->where('biometric_device_id', $device->id);

        return match ($state) {
            'unassigned' => $query->whereDoesntHave(
                'biometricEnrollments',
                fn (Builder $enrollment) => $onThisDevice($enrollment)->where('is_active', true),
            ),
            'assigned' => $query->whereHas(
                'biometricEnrollments',
                fn (Builder $enrollment) => $onThisDevice($enrollment)->where('is_active', true)->whereNull('enrolled_at'),
            ),
            'captured' => $query->whereHas(
                'biometricEnrollments',
                fn (Builder $enrollment) => $onThisDevice($enrollment)->where('is_active', true)->whereNotNull('enrolled_at'),
            ),
            'retired' => $query->whereHas(
                'biometricEnrollments',
                fn (Builder $enrollment) => $onThisDevice($enrollment)->where('is_active', false),
            ),
            default => $query,
        };
    }

    /**
     * Counts over the whole filtered set rather than the visible page, so the
     * cards answer "how much of this terminal is done" instead of "how much of
     * page 1". The state filter is excluded from them: a card reading
     * "0 captured" while the page is narrowed to unassigned staff is noise.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function summary(BiometricDevice $device, array $filters): array
    {
        $base = fn () => $this->query($device, array_diff_key($filters, ['state' => null]));

        return [
            'people' => $base()->count(),
            'assigned' => $base()->whereHas('biometricEnrollments', fn (Builder $query) => $query
                ->where('biometric_device_id', $device->id)->where('is_active', true)->whereNull('enrolled_at'))->count(),
            'captured' => $base()->whereHas('biometricEnrollments', fn (Builder $query) => $query
                ->where('biometric_device_id', $device->id)->where('is_active', true)->whereNotNull('enrolled_at'))->count(),
            'unassigned' => $base()->whereDoesntHave('biometricEnrollments', fn (Builder $query) => $query
                ->where('biometric_device_id', $device->id)->where('is_active', true))->count(),
        ];
    }

    /**
     * @param  Collection<int, BiometricDevice>  $devices
     * @param  array<string, mixed>  $filters
     */
    private function selectedDevice(Collection $devices, array $filters): ?BiometricDevice
    {
        if (($filters['device'] ?? null) !== null) {
            return $devices->firstWhere('id', (int) $filters['device']) ?? $devices->first();
        }

        // A real terminal in service, by preference.
        //
        // The simulator is excluded from this choice deliberately. It is a
        // development fixture that BiometricSimulatorController creates, it
        // holds a handful of fake enrolments, and because the list is sorted by
        // name it otherwise wins the default against a terminal called "Main
        // Entrance Terminal". Somebody exporting the roster for ZKTime would
        // then get the simulator's two rows instead of the ward's three
        // hundred, and the file looks plausible enough to import.
        //
        // It stays selectable by hand, since being able to read what it holds
        // is the point of it existing.
        return $devices->first(fn (BiometricDevice $device) => $device->is_active && $device->provider !== 'simulator')
            ?? $devices->firstWhere('is_active', true)
            ?? $devices->first();
    }

    /**
     * Validated inline rather than through a FormRequest, as the audit log page
     * does for its own GET filters.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'device' => ['nullable', 'integer', 'exists:biometric_devices,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'state' => ['nullable', 'in:unassigned,assigned,captured,retired'],
            'employment' => ['nullable', 'in:active,inactive'],
            // Which shape the export takes. 'roster' is the sheet a person
            // carries to the terminal; 'zktime' is the file ZKTime's import
            // wizard reads. Ignored by the page itself.
            'format' => ['nullable', 'in:roster,zktime'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{label: string, value: string, query: array<string, mixed>}>
     */
    private function describeActiveFilters(array $filters): array
    {
        $applied = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $labels = [
            'search' => 'Search',
            'department_id' => 'Department',
            'state' => 'State',
            'employment' => 'Employment',
        ];

        $chips = [];

        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $applied)) {
                continue;
            }

            $chips[] = [
                'label' => $label,
                'value' => $key === 'department_id'
                    ? (string) (Department::query()->whereKey($applied[$key])->value('name') ?? $applied[$key])
                    : (string) $applied[$key],
                // One filter dropped at a time, so a narrowed page can be
                // widened a step rather than cleared outright.
                'query' => array_diff_key($applied, [$key => null]),
            ];
        }

        return $chips;
    }

    private function stateLabel(?BiometricEnrollment $enrollment): string
    {
        if ($enrollment === null) {
            return 'Not assigned';
        }

        if (! $enrollment->is_active) {
            return 'Retired';
        }

        return $enrollment->enrolled_at === null ? 'Awaiting capture' : 'Captured';
    }

    private function authorizeEnrollmentAccess(Request $request): void
    {
        abort_unless($request->user()->hasRole('system-administrator'), 403);
    }
}
