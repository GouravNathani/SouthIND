<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchResolver
{
    public static function resolve(Request $request): ?Branch
    {
        $baseQuery = Branch::query()->where('is_active', true);

        $branchId = $request->query('branch_id');
        if ($branchId) {
            return $baseQuery->where('id', $branchId)->first();
        }

        $branchCode = trim((string) $request->query('branch_code'));
        if ($branchCode !== '') {
            return $baseQuery->where('code', $branchCode)->first();
        }

        $domain = trim((string) ($request->query('domain') ?? $request->query('branch_domain')));
        if ($domain !== '') {
            return $baseQuery->where('domain', $domain)->first();
        }

        $adminNumber = trim((string) $request->query('admin_unique_number'));
        if ($adminNumber !== '') {
            $admin = Admin::query()->where('unique_number', $adminNumber)->first();
            if ($admin?->branch_id) {
                return $baseQuery->where('id', $admin->branch_id)->first();
            }
        }

        $host = trim((string) $request->getHost());
        if ($host !== '') {
            $branch = $baseQuery->where('domain', $host)->first();
            if ($branch) {
                return $branch;
            }
        }

        return null;
    }
}
