<?php

namespace App\Support;

use App\Models\Admin;
use Symfony\Component\HttpFoundation\Response;

trait ResolvesBranch
{
    protected function resolveBranchId(Admin $actor): int
    {
        $branchId = $actor->resolveBranchId();

        if (!$branchId) {
            abort(Response::HTTP_FORBIDDEN, 'Branch assignment required.');
        }

        return $branchId;
    }
}
