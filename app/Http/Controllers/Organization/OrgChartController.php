<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Services\Organization\OrgChartService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The reporting-line chart. Guarded by `auth` only, deliberately matching the
 * employee directory at /organization — the chart shows the same people the
 * directory already lists to every signed-in user, so restricting it further
 * would only make the two screens disagree about who may see what.
 */
class OrgChartController extends Controller
{
    public function index(Request $request, OrgChartService $chart): View
    {
        return view('organization.chart', [
            'chart' => $chart->tree(),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
            'notifications' => collect(),
        ]);
    }
}
