<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MyDocumentsController extends Controller
{
    // ✅ Files are stored directly under the public/ folder (not storage/app/public)
    // so no `php artisan storage:link` symlink is required. Adjust if you've since
    // fixed the document-root/symlink setup and want to switch back to Storage disk.
    private const UPLOAD_SUBDIR = 'uploads/documents';

    // GET /api/my-documents
    // Scoped to auth()->user()->id — no user_id param needed/accepted.
    public function index(Request $request)
    {
        $rows = EmployeeDocument::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($doc) {
                return [
                    'id'           => $doc->id,
                    'type'         => $doc->type,
                    'file_name'    => $doc->file_name,
                    'file_url'     => $doc->file_path ? asset($doc->file_path) : null,
                    'uploaded_at'  => $doc->created_at->format('d M Y, h:i A'),
                    'status'       => $doc->status,
                    'admin_reason' => $doc->admin_reason,
                ];
            });

        return response()->json([
            'status' => true,
            'rows'   => $rows,
        ], 200);
    }

    // POST /api/my-documents  (multipart/form-data: type, file)
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:100',
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx', // 10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $file = $request->file('file');

        $originalName = $file->getClientOriginalName();
        $extension    = $file->getClientOriginalExtension();
        $safeName     = Str::uuid()->toString() . '.' . $extension;

        $destDir = public_path(self::UPLOAD_SUBDIR . '/' . $user->id);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $file->move($destDir, $safeName);

        $relativePath = self::UPLOAD_SUBDIR . '/' . $user->id . '/' . $safeName;

        $doc = EmployeeDocument::create([
            'user_id'   => $user->id,
            'type'      => $request->type,
            'file_name' => $originalName,
            'file_path' => $relativePath,
            'status'    => 'Pending',
        ]);

        // ✅ Notify the admins who are responsible for reviewing this employee's
        // documents (super admin + their company admin + their branch manager +
        // their dept admin), so it shows up on the admin dashboard's bell icon.
        $this->notifyAdminsOfUpload($user, $doc);

        return response()->json([
            'status'  => true,
            'message' => 'Document uploaded successfully.',
            'data'    => [
                'id'          => $doc->id,
                'type'        => $doc->type,
                'file_name'   => $doc->file_name,
                'file_url'    => asset($doc->file_path),
                'uploaded_at' => $doc->created_at->format('d M Y, h:i A'),
                'status'      => $doc->status,
            ],
        ], 201);
    }

    // DELETE /api/my-documents/{id}
    // Scoped to the owner — a user can only delete their own document.
    public function destroy(Request $request, $id)
    {
        $doc = EmployeeDocument::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        if (!$doc) {
            return response()->json([
                'status'  => false,
                'message' => 'Document not found.',
            ], 404);
        }

        if ($doc->file_path) {
            $fullPath = public_path($doc->file_path);
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
        $doc->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Document deleted successfully.',
        ], 200);
    }

    // ────────────────────────────────────────────────────────────────────────
    // HELPER: notify every admin who has authority over this employee.
    // Matches notifications table columns: user_id, type, title, message,
    // reference_type, reference_id, is_read, created_at, updated_at.
    // ────────────────────────────────────────────────────────────────────────
    private function notifyAdminsOfUpload($user, EmployeeDocument $doc): void
    {
        $adminIds = collect();

        // Super Admins (type 1) — always notified, regardless of scope
        $adminIds = $adminIds->merge(
            DB::table('users')->where('user_types_id', 1)->pluck('id')
        );

        // Company Admin (type 2) — via company_users pivot
        if ($user->company_id) {
            $adminIds = $adminIds->merge(
                DB::table('company_users')
                    ->where('company_id', $user->company_id)
                    ->pluck('users_id')
            );
        }

        // Branch Manager (type 3) — direct branch_id match
        if ($user->branch_id) {
            $adminIds = $adminIds->merge(
                DB::table('users')
                    ->where('user_types_id', 3)
                    ->where('branch_id', $user->branch_id)
                    ->pluck('id')
            );
        }

        // Department Admin (type 4) — direct department_id match
        if ($user->department_id) {
            $adminIds = $adminIds->merge(
                DB::table('users')
                    ->where('user_types_id', 4)
                    ->where('department_id', $user->department_id)
                    ->pluck('id')
            );
        }

        $adminIds = $adminIds->filter()->unique()->values();

        if ($adminIds->isEmpty()) {
            return;
        }

        $now  = now();
        $rows = $adminIds->map(function ($adminId) use ($user, $doc, $now) {
            return [
                'user_id'        => $adminId,
                'type'           => 'document',
                'title'          => 'New document uploaded',
                'message'        => "{$user->username} uploaded a \"{$doc->type}\" document for review.",
                'reference_type' => 'employee_document',
                'reference_id'   => $doc->id,
                'is_read'        => 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        })->toArray();

        DB::table('notifications')->insert($rows);
    }
}