<?php

namespace App\Http\Requests\Analytics;

use App\Models\BurnoutRiskSnapshot;
use App\Services\Burnout\BurnoutRiskCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The Burnout Risk tab of Workforce Analytics.
 *
 * Narrower than the rest of the module: HR managers and department heads only.
 * A system administrator can open the overview but not this list; like every
 * other employee, they see only their own burnout risk, on their dashboard.
 * The rows are then narrowed to what the viewer supervises.
 */
class BurnoutRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('burnout.view-workforce');
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'level' => ['nullable', Rule::in(BurnoutRiskSnapshot::LEVELS)],
            'trend' => ['nullable', Rule::in([
                BurnoutRiskCalculator::TREND_RISING,
                BurnoutRiskCalculator::TREND_STEADY,
                BurnoutRiskCalculator::TREND_EASING,
                BurnoutRiskCalculator::TREND_NEW,
            ])],
        ];
    }
}
