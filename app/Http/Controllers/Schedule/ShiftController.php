<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ShiftRequest;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager'])->exists(), 403);

        return view('shifts.index', [
            'shifts' => Shift::query()->withCount('assignments')->orderBy('start_time')->get(),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
            'notifications' => collect(),
        ]);
    }

    public function store(ShiftRequest $request): RedirectResponse
    {
        Shift::query()->create($this->shiftData($request) + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Shift template created.');
    }

    public function update(ShiftRequest $request, Shift $shift): RedirectResponse
    {
        $shift->update($this->shiftData($request));

        return back()->with('success', 'Shift template updated.');
    }

    public function destroy(Request $request, Shift $shift): RedirectResponse
    {
        abort_unless($request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager'])->exists(), 403);

        if ($shift->assignments()->exists()) {
            throw ValidationException::withMessages([
                'shift' => 'This shift has schedule history and cannot be deleted. Mark it inactive instead.',
            ]);
        }

        $shift->delete();

        return back()->with('success', 'Shift template deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftData(ShiftRequest $request): array
    {
        return [
            'code' => strtoupper($request->string('code')->trim()->toString()),
            'name' => $request->string('name')->trim()->toString(),
            'start_time' => $request->string('start_time')->toString(),
            'end_time' => $request->string('end_time')->toString(),
            'break_minutes' => $request->integer('break_minutes'),
            'color' => strtoupper($request->string('color')->toString()),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
