<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\AccountResource;
use App\Models\Account;
use App\Support\Cache\AccountCache;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $usage = $request->query('used_for', 'deposit');
        $usage = in_array($usage, ['deposit', 'withdraw'], true) ? $usage : 'deposit';
        $branchId = $request->user()->branch_id;

        if (!$branchId) {
            return AccountResource::collection([]);
        }

        $accounts = AccountCache::rememberForUser($usage, $branchId, function () use ($usage, $branchId) {
            return Account::query()
                ->active()
                ->forUse($usage)
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->get();
        });

        return AccountResource::collection($accounts);
    }
}
