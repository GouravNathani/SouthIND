<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesReferral;
use App\Http\Controllers\Controller;
use App\Support\ResolvesBranch;
use Illuminate\Http\Request;

/**
 * Referral & commission for a branch admin. The branch is the acting admin's
 * own — a branch admin never sees another branch's agents or ledger.
 */
class ReferralController extends Controller
{
    use ResolvesBranch;
    use HandlesReferral;

    protected function branchIdFor(Request $request): int
    {
        return $this->resolveBranchId($request->user());
    }
}
