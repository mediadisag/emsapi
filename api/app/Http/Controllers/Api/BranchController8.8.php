<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{

    public function index()
    {
        $authUser = auth()->user();
         if ($authUser->user_types_id == 1) {
            $branches = Branch::with('company')->get();
        } elseif ($authUser->user_types_id == 2) {
            $companyId = DB::table('company_users')->where('users_id', $authUser->id)->value('company_id');
            if (!$companyId) {
                return response()->json(['message' => 'No company assigned to you'], 403);
            }

            $branches = Branch::with('company')->where('company_id', $companyId)->get();
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($branches, 200);
    }
        
    public function show($id)
    {
        $authUser = auth()->user();
        $branch = Branch::with('company')->find($id);

        if (!$branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }
        if ($authUser->user_types_id == 1) {
            return response()->json($branch, 200);
        }

        if ($authUser->user_types_id == 2) {
            $companyId = DB::table('company_users')->where('users_id', $authUser->id)->value('company_id');
            if ($branch->company_id == $companyId) {
                return response()->json($branch, 200);
            }

            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }

        

    public function store(Request $request)
    {
        $authUser = Auth::user();

        if (!in_array($authUser->user_types_id, [1, 2])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:company,id',
            'name'       => 'required|string|max:255',
            'numbers'    => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }
        if ($authUser->user_types_id == 1) {
            // Super admin must provide company_id
            $request->validate(['company_id' => 'required|exists:company,id']);
            $companyId = $request->company_id;
        } else {
            // Company admin's company
            $companyId = DB::table('company_users')->where('users_id', $authUser->id)->value('company_id');
            if (!$companyId) {
                return response()->json(['message' => 'No company assigned to you'], 403);
            }
        }

        $branch = Branch::create($request->only([
            'company_id', 'name', 'numbers'
        ]));

        return response()->json(['message' => 'Branch created', 'branch' => $branch], 201);
    }

    public function update(Request $request, $id)
    {
         $authUser = Auth::user();
        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }
        if ($authUser->user_types_id == 2) {
            $companyId = DB::table('company_users')->where('users_id', $authUser->id)->value('company_id');
            if ($branch->company_id != $companyId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } elseif ($authUser->user_types_id != 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company_id' => 'sometimes|exists:company,id',
            'name'       => 'sometimes|required|string|max:255',
            'numbers'    => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $branch->update($request->only([
            'company_id', 'name', 'numbers'
        ]));

        return response()->json(['message' => 'Branch updated', 'branch' => $branch], 200);
    }

    public function destroy($id)
    {
         $authUser = Auth::user();
        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        if ($authUser->user_types_id == 2) {
            $companyId = DB::table('company_users')->where('users_id', $authUser->id)->value('company_id');
            if ($branch->company_id != $companyId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        } elseif ($authUser->user_types_id != 1) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $branch->delete();

        return response()->json(['message' => 'Branch deleted'], 200);
    }

    public function getByCompany($company_id)
    {
        $authUser = Auth::user();

        if (!Company::find($company_id)) {
            return response()->json(['message' => 'Company not found'], 404);
        }

       if ($authUser->user_types_id == 1) {
            // Super admin can view all
            $branches = Branch::where('company_id', $company_id)->get();
            return response()->json($branches, 200);
        }

        if ($authUser->user_types_id == 2) {
            $myCompanyId = DB::table('company_users')->where('users_id', $authUser->id)->value('company_id');
            if ($company_id != $myCompanyId) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $branches = Branch::where('company_id', $company_id)->get();
            return response()->json($branches, 200);
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }
}
