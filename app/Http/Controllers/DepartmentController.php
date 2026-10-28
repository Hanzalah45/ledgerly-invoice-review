<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Departments and cost centres, each with an optional manager who approves the "department manager" step. */
class DepartmentController extends Controller
{
    public function index(): View
    {
        return view('departments.index', [
            'departments' => Department::with('manager')->withCount('documents')->orderByDesc('active')->orderBy('code')->get(),
            'managers' => User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_REVIEWER])->orderBy('name')->get(['id', 'name', 'role']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $department = Department::create($this->validated($request) + ['active' => true]);

        return redirect()->route('departments.index')->with('success', "{$department->name} added.");
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $department->update($this->validated($request, $department) + ['active' => $request->boolean('active')]);

        return redirect()->route('departments.index')->with('success', "{$department->name} updated.");
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?Department $department = null): array
    {
        // Codes are stored upper-case, so compare them upper-case or "it" would slip past the unique check for "IT".
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._\\-]+$/', Rule::unique('departments', 'code')->ignore($department?->id)],
            'cost_center' => ['nullable', 'string', 'max:40'],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereIn('role', [User::ROLE_ADMIN, User::ROLE_REVIEWER])],
        ], ['code.regex' => 'A code may contain letters, digits, dots, dashes and underscores.', 'manager_id.exists' => 'The manager must be an admin or a reviewer.']);

        $data['manager_id'] = $data['manager_id'] ?? null;

        return $data;
    }
}
