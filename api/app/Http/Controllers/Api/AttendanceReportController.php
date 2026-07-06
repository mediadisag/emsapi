<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class AttendanceReportController extends Controller
{
   
    public function attendanceStatus(Request $request)
    {
        try {
            // ✅ Sanctum automatically validates token
            $user = Auth::guard('sanctum')->user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'error' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED'
                ], 401);
            }

            // ── Validate request parameters ──
            $validated = $request->validate([
                'date'           => 'required|date_format:Y-m-d',
                'page'           => 'integer|min:1',
                'limit'          => 'integer|min:1|max:100',
                'search'         => 'string|nullable',
                'company_id'     => 'integer|nullable',
                'branch_id'      => 'integer|nullable',
                'department_id'  => 'integer|nullable',
                'fingerprint_id' => 'integer|nullable',
            ]);

            $date   = $validated['date'];
            $page   = $request->get('page', 1);
            $limit  = min($request->get('limit', 15), 100);
            $search = $validated['search'] ?? '';
            $offset = ($page - 1) * $limit;

            // ── Extract scope parameters ──
            $scopeCompany  = $validated['company_id'] ?? null;
            $scopeBranch   = $validated['branch_id'] ?? null;
            $scopeDept     = $validated['department_id'] ?? null;
            $scopeFpId     = $validated['fingerprint_id'] ?? null;

            // ── ROLE-BASED ACCESS CONTROL ──
            $isSuperAdmin = $user->user_types_id == 1;

            // Auto-restrict based on user role if no explicit scope provided
            if (!$isSuperAdmin && !$scopeCompany && !$scopeBranch && !$scopeDept && !$scopeFpId) {
                switch ($user->user_types_id) {
                    case 2: // Company Admin
                        $scopeCompany = $user->company_id;
                        break;
                    case 3: // Branch Admin
                        $scopeBranch = $user->branch_id;
                        break;
                    case 4: // Department Admin
                        $scopeDept = $user->department_id;
                        break;
                    case 5: // Employee
                        $scopeFpId = $user->fingerprint_id;
                        break;
                }
            }

            // Verify permissions for requested scope
            if (!$isSuperAdmin) {
                if ($scopeCompany && $user->user_types_id == 2 && $user->company_id != $scopeCompany) {
                    return response()->json([
                        'status' => false,
                        'error' => 'You do not have permission to view this company',
                        'code' => 'ACCESS_DENIED'
                    ], 403);
                }
                if ($scopeBranch && $user->user_types_id == 3 && $user->branch_id != $scopeBranch) {
                    return response()->json([
                        'status' => false,
                        'error' => 'You do not have permission to view this branch',
                        'code' => 'ACCESS_DENIED'
                    ], 403);
                }
                if ($scopeDept && $user->user_types_id == 4 && $user->department_id != $scopeDept) {
                    return response()->json([
                        'status' => false,
                        'error' => 'You do not have permission to view this department',
                        'code' => 'ACCESS_DENIED'
                    ], 403);
                }
            }

            // ── Build WHERE clause ──
            $whereParts = ["ul.checkindate = ?"];
            $bindValues = [$date];

            if ($search !== '') {
                $whereParts[] = "ul.username LIKE ?";
                $bindValues[] = '%' . $search . '%';
            }

            $needJoin = false;

            if ($scopeFpId !== null) {
                $whereParts[] = "ul.fingerprint_id = ?";
                $bindValues[] = $scopeFpId;
            } elseif ($scopeDept !== null) {
                $needJoin = true;
                $whereParts[] = "u.department_id = ?";
                $bindValues[] = $scopeDept;
            } elseif ($scopeBranch !== null) {
                $needJoin = true;
                $whereParts[] = "u.branch_id = ?";
                $bindValues[] = $scopeBranch;
            } elseif ($scopeCompany !== null) {
                $needJoin = true;
                $whereParts[] = "u.company_id = ?";
                $bindValues[] = $scopeCompany;
            }

            $whereSQL = "WHERE " . implode(" AND ", $whereParts);
            $joinSQL = $needJoin
                ? "JOIN users u ON u.fingerprint_id = ul.fingerprint_id AND u.del_fingerid = 0"
                : "";

            // ── Count total employees ──
            $countSQL = "
                SELECT COUNT(DISTINCT ul.fingerprint_id) AS total
                FROM users_logs ul
                $joinSQL
                $whereSQL
            ";

            $countResult = DB::select(DB::raw($countSQL), $bindValues);
            $totalEmployees = $countResult[0]->total ?? 0;

            // ── Count total punches ──
            $punchSQL = "
                SELECT COUNT(*) AS total
                FROM users_logs ul
                $joinSQL
                $whereSQL
            ";

            $punchResult = DB::select(DB::raw($punchSQL), $bindValues);
            $totalPunches = $punchResult[0]->total ?? 0;

            // ── Fetch paginated data ──
            $extraSelect = $needJoin
                ? ", u.department_id, u.branch_id, u.company_id"
                : "";

            $dataSQL = "
                SELECT
                    ul.fingerprint_id,
                    ul.username,
                    ul.serialnumber,
                    ul.device_branch,
                    ul.device_dep,
                    ul.checkindate,
                    MIN(ul.timein)  AS first_in,
                    MAX(CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN ul.timeout END) AS last_out,
                    COUNT(*)        AS punch_count,
                    MAX(CASE WHEN ul.timeout != '00:00:00' AND ul.timeout IS NOT NULL THEN 1 ELSE 0 END) AS has_out
                    $extraSelect
                FROM users_logs ul
                $joinSQL
                $whereSQL
                GROUP BY ul.fingerprint_id, ul.checkindate
                ORDER BY MIN(ul.timein) ASC
                LIMIT ? OFFSET ?
            ";

            $bindValues[] = $limit;
            $bindValues[] = $offset;

            $rows = DB::select(DB::raw($dataSQL), $bindValues);

            $formattedRows = [];
            foreach ($rows as $row) {
                $formattedRows[] = [
                    'fingerprint_id' => $row->fingerprint_id,
                    'name'           => $row->username,
                    'company'        => $row->device_branch ?? '',
                    'department'     => $row->device_dep ?? '',
                    'serial'         => $row->serialnumber ?? '',
                    'date'           => $row->checkindate,
                    'first_in'       => $row->first_in,
                    'last_out'       => $row->last_out,
                    'punch_count'    => intval($row->punch_count),
                    'is_complete'    => intval($row->has_out) === 1,
                    'department_id'  => isset($row->department_id) ? intval($row->department_id) : null,
                    'branch_id'      => isset($row->branch_id) ? intval($row->branch_id) : null,
                    'company_id'     => isset($row->company_id) ? intval($row->company_id) : null,
                ];
            }

            // ── Calculate stats ──
            $complete = collect($formattedRows)->filter(fn($r) => $r['is_complete'])->count();
            $stillIn = collect($formattedRows)->filter(fn($r) => !$r['is_complete'])->count();
            $hasMore = ($offset + count($formattedRows)) < $totalEmployees;
            $remaining = max(0, $totalEmployees - ($offset + count($formattedRows)));

            // ── For Employee (Role 5) stats ──
            $empFirstIn = null;
            $empLastOut = null;
            $empDuration = null;

            if ($scopeFpId !== null && count($formattedRows) === 1) {
                $empFirstIn = $formattedRows[0]['first_in'];
                $empLastOut = $formattedRows[0]['last_out'];
                if ($empFirstIn && $empLastOut) {
                    $ts = strtotime(date('Y-m-d') . ' ' . $empFirstIn);
                    $te = strtotime(date('Y-m-d') . ' ' . $empLastOut);
                    $sec = $te - $ts;
                    if ($sec > 0) {
                        $h = floor($sec / 3600);
                        $m = floor(($sec % 3600) / 60);
                        $empDuration = $h > 0 ? "{$h}h {$m}m" : "{$m}m";
                    }
                }
            }

            return response()->json([
                'status'    => true,
                'date'      => $date,
                'page'      => $page,
                'limit'     => $limit,
                'rows'      => $formattedRows,
                'has_more'  => $hasMore,
                'remaining' => $remaining,
                'stats'     => [
                    'total_punches'   => intval($totalPunches),
                    'total_employees' => intval($totalEmployees),
                    'complete'        => $complete,
                    'still_inside'    => $stillIn,
                    'first_in'        => $empFirstIn,
                    'last_out'        => $empLastOut,
                    'duration'        => $empDuration,
                ],
                '_scope' => [
                    'company_id'    => $scopeCompany,
                    'branch_id'     => $scopeBranch,
                    'department_id' => $scopeDept,
                    'fingerprint_id'=> $scopeFpId,
                ],
                '_user' => [
                    'id'            => $user->id,
                    'username'      => $user->username,
                    'role'          => $user->user_types_id,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'error' => 'Validation error',
                'code' => 'VALIDATION_ERROR',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('AttendanceReportController attendanceStatus error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'error' => 'Internal server error',
                'code' => 'SERVER_ERROR',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}