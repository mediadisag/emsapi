<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class HolidayController extends Controller
{
    /*
    |==========================================================================
    | Role IDs (match your user_types table):
    | 1 = Super Admin      -> full access, any company/branch/department
    | 2 = Company Admin    -> scoped to own company_id
    | 3 = Branch Admin     -> scoped to own branch_id
    | 4 = Department Admin -> scoped to own department_id
    | 5 = Employee         -> read-only, sees own department's holidays
    |==========================================================================
    */

    /**
     * Apply server-side role scoping to a holidays query.
     * ✅ Never trust client-sent company/branch/department for restriction —
     *    only use them as optional narrowing filters ON TOP OF the role scope.
     */
    private function applyRoleScope($query, $user)
    {
        $role = (int) $user->user_types_id;

        if ($role === 2) {
            $query->where('h.company_id', $user->company_id);
        } elseif ($role === 3) {
            $query->where('h.branch_id', $user->branch_id);
        } elseif ($role === 4 || $role === 5) {
            $query->where('h.department_id', $user->department_id);
        }
        // role 1 (Super Admin) => no restriction

        return $query;
    }

    /**
     * Check whether the logged-in user is allowed to create/edit/delete
     * a holiday for the given company/branch/department combination.
     */
    private function canManageScope($user, int $companyId, int $branchId, int $departmentId): bool
    {
        $role = (int) $user->user_types_id;

        if ($role === 1) return true; // Super Admin
        if ($role === 2) return (int) $user->company_id === $companyId;
        if ($role === 3) return (int) $user->branch_id === $branchId;
        if ($role === 4) return (int) $user->department_id === $departmentId;

        return false; // Employee (5) and anything else -> no write access
    }

    /*
    |==========================================================================
    | GET /holidays
    | List holidays, role-scoped, with optional company/branch/department/date
    | filters layered on top.
    |==========================================================================
    */
    public function index(Request $request)
    {
        $user = $request->user();

        $page   = max(1, (int) $request->query('page', 1));
        $limit  = max(1, (int) $request->query('limit', 25));
        $offset = ($page - 1) * $limit;

        $scopeCompany = $request->query('company_id')    !== null ? (int) $request->query('company_id')    : null;
        $scopeBranch  = $request->query('branch_id')     !== null ? (int) $request->query('branch_id')     : null;
        $scopeDept    = $request->query('department_id') !== null ? (int) $request->query('department_id') : null;
        $fromDate     = $request->query('from_date');
        $toDate       = $request->query('to_date');
        $search       = trim((string) $request->query('search', ''));

        $query = DB::table('holidays as h')
            ->leftJoin('company as c', 'c.id', '=', 'h.company_id')
            ->leftJoin('branch as b', 'b.id', '=', 'h.branch_id')
            ->leftJoin('department as d', 'd.id', '=', 'h.department_id');

        $this->applyRoleScope($query, $user);

        if ($scopeCompany !== null) $query->where('h.company_id', $scopeCompany);
        if ($scopeBranch  !== null) $query->where('h.branch_id', $scopeBranch);
        if ($scopeDept    !== null) $query->where('h.department_id', $scopeDept);
        if ($fromDate) $query->where('h.holiday_date', '>=', $fromDate);
        if ($toDate)   $query->where('h.holiday_date', '<=', $toDate);
        if ($search !== '') {
            $query->where('h.holiday_name', 'LIKE', '%' . $search . '%');
        }

        $total = (clone $query)->count('h.id');

        $rows = (clone $query)
            ->select([
                'h.id',
                'h.company_id',
                'h.branch_id',
                'h.department_id',
                'h.holiday_name',
                'h.holiday_date',
                'h.description',
                'h.is_recurring',
                'h.is_edited',
                'h.edited_by',
                'h.edited_at',
                DB::raw('c.name as company_name'),
                DB::raw('b.branch_name as branch_name'),
                DB::raw('d.department_name as department_name'),
            ])
            ->orderBy('h.holiday_date', 'asc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return response()->json([
            'status'    => true,
            'page'      => $page,
            'limit'     => $limit,
            'total'     => $total,
            'has_more'  => ($offset + $rows->count()) < $total,
            'rows'      => $rows,
        ]);
    }

    /*
    |==========================================================================
    | POST /holidays
    | Add a holiday for a specific department (company_id/branch_id required
    | for scoping + fast filtering, department_id is the actual target).
    |==========================================================================
    */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'company_id'    => 'required|integer|exists:company,id',
            'branch_id'     => 'required|integer|exists:branch,id',
            'department_id' => 'required|integer|exists:department,id',
            'holiday_name'  => 'required|string|max:150',
            'holiday_date'  => 'required|date_format:Y-m-d',
            'description'   => 'nullable|string',
            'is_recurring'  => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();

        if (!$this->canManageScope($user, (int) $data['company_id'], (int) $data['branch_id'], (int) $data['department_id'])) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to add a holiday for this department.',
            ], 403);
        }

        // ✅ department yathartha branch/company-il thanne belong cheyyunnundo enn verify
        $deptValid = DB::table('department')
            ->where('id', $data['department_id'])
            ->where('branch_id', $data['branch_id'])
            ->where('company_id', $data['company_id'])
            ->exists();

        if (!$deptValid) {
            return response()->json([
                'status'  => false,
                'message' => 'Selected department does not belong to the given branch/company.',
            ], 422);
        }

        $duplicate = DB::table('holidays')
            ->where('department_id', $data['department_id'])
            ->where('holiday_date', $data['holiday_date'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status'  => false,
                'message' => 'A holiday already exists for this department on this date.',
            ], 409);
        }

        $createdBy = $user->username ?? $user->name ?? 'Admin';

        $holiday = Holiday::create([
            'company_id'    => $data['company_id'],
            'branch_id'     => $data['branch_id'],
            'department_id' => $data['department_id'],
            'holiday_name'  => $data['holiday_name'],
            'holiday_date'  => $data['holiday_date'],
            'description'   => $data['description'] ?? null,
            'is_recurring'  => $data['is_recurring'] ?? false,
            'created_by'    => $createdBy,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Holiday added successfully.',
            'data'    => $holiday,
        ]);
    }

    /*
    |==========================================================================
    | PUT /holidays/{id}
    |==========================================================================
    */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $holiday = Holiday::find($id);

        if (!$holiday) {
            return response()->json(['status' => false, 'message' => 'Holiday not found.'], 404);
        }

        if (!$this->canManageScope($user, (int) $holiday->company_id, (int) $holiday->branch_id, (int) $holiday->department_id)) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to edit this holiday.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'holiday_name' => 'sometimes|required|string|max:150',
            'holiday_date' => 'sometimes|required|date_format:Y-m-d',
            'description'  => 'nullable|string',
            'is_recurring' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();

        if (!empty($data['holiday_date']) && $data['holiday_date'] !== $holiday->holiday_date->format('Y-m-d')) {
            $duplicate = DB::table('holidays')
                ->where('department_id', $holiday->department_id)
                ->where('holiday_date', $data['holiday_date'])
                ->where('id', '!=', $holiday->id)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'status'  => false,
                    'message' => 'A holiday already exists for this department on this date.',
                ], 409);
            }
        }

        $editedBy = $user->username ?? $user->name ?? 'Admin';

        $holiday->update(array_merge($data, [
            'is_edited' => 1,
            'edited_by' => $editedBy,
            'edited_at' => now(),
        ]));

        return response()->json([
            'status'  => true,
            'message' => 'Holiday updated successfully.',
            'data'    => $holiday,
        ]);
    }

    /*
    |==========================================================================
    | DELETE /holidays/{id}
    |==========================================================================
    */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $holiday = Holiday::find($id);

        if (!$holiday) {
            return response()->json(['status' => false, 'message' => 'Holiday not found.'], 404);
        }

        if (!$this->canManageScope($user, (int) $holiday->company_id, (int) $holiday->branch_id, (int) $holiday->department_id)) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to delete this holiday.',
            ], 403);
        }

        $holiday->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Holiday deleted successfully.',
        ]);
    }

    /*
    |==========================================================================
    | POST /holidays/generate-sundays
    | Bulk-add "Sunday" holidays for every Sunday in a given year, for a
    | specific company/branch/department. Skips dates that already have
    | a holiday entry for that department.
    |==========================================================================
    */
    public function generateSundays(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'company_id'    => 'required|integer|exists:company,id',
            'branch_id'     => 'required|integer|exists:branch,id',
            'department_id' => 'required|integer|exists:department,id',
            'year'          => 'required|integer|min:2000|max:2100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();

        if (!$this->canManageScope($user, (int) $data['company_id'], (int) $data['branch_id'], (int) $data['department_id'])) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to add holidays for this department.',
            ], 403);
        }

        // ✅ department yathartha branch/company-il thanne belong cheyyunnundo enn verify
        $deptValid = DB::table('department')
            ->where('id', $data['department_id'])
            ->where('branch_id', $data['branch_id'])
            ->where('company_id', $data['company_id'])
            ->exists();

        if (!$deptValid) {
            return response()->json([
                'status'  => false,
                'message' => 'Selected department does not belong to the given branch/company.',
            ], 422);
        }

        $year = (int) $data['year'];

        // ── find all Sundays in the given year ──
        $firstDay = new \DateTime("$year-01-01");
        $dow = (int) $firstDay->format('w'); // 0 = Sunday ... 6 = Saturday
        $daysUntilSunday = (7 - $dow) % 7;
        $cursor = clone $firstDay;
        $cursor->modify("+{$daysUntilSunday} days");

        $sundays = [];
        while ((int) $cursor->format('Y') === $year) {
            $sundays[] = $cursor->format('Y-m-d');
            $cursor->modify('+7 days');
        }

        // ── skip dates that already have a holiday for this department ──
        $existing = DB::table('holidays')
            ->where('department_id', $data['department_id'])
            ->whereBetween('holiday_date', ["$year-01-01", "$year-12-31"])
            ->pluck('holiday_date')
            ->map(fn ($d) => (new \DateTime($d))->format('Y-m-d'))
            ->toArray();

        $createdBy = $user->username ?? $user->name ?? 'Admin';
        $now = now();

        $toInsert = [];
        $skipped  = 0;

        foreach ($sundays as $sunday) {
            if (in_array($sunday, $existing, true)) {
                $skipped++;
                continue;
            }
            $toInsert[] = [
                'company_id'    => $data['company_id'],
                'branch_id'     => $data['branch_id'],
                'department_id' => $data['department_id'],
                'holiday_name'  => 'Sunday',
                'holiday_date'  => $sunday,
                'description'   => null,
                'is_recurring'  => false,
                'created_by'    => $createdBy,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (!empty($toInsert)) {
            DB::table('holidays')->insert($toInsert);
        }

        return response()->json([
            'status'  => true,
            'message' => count($toInsert) . ' Sunday holiday(s) added for ' . $year . '. ' . $skipped . ' date(s) already existed and were skipped.',
            'added'   => count($toInsert),
            'skipped' => $skipped,
            'year'    => $year,
        ]);
    }
}