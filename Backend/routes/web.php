<?php

use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\OwedClearedController;
use Illuminate\Support\Facades\Route;

// Cache clear, driven by BookFlowControl. Signed with the shared wallet key, so
// it is server-to-server only — it can no longer be triggered by opening the URL.
// GET is kept because this lives in the `web` group, where a POST would be
// rejected by CSRF before the signature is ever checked.
Route::middleware('wallet-hmac')->group(function () {
    Route::match(['get', 'post'], 'maintenance/refresh-cache', MaintenanceController::class);

    // Control collected an owed amount by hand — close those days locally. The
    // month is part of the PATH because that is what the signature covers
    // ("all" clears every owed day).
    Route::match(['get', 'post'], 'maintenance/owed-cleared/{month}', OwedClearedController::class)
        ->where('month', '^(all|\d{4}-\d{2})$');
});

// There is no web UI — this backend only serves the three SPAs — so anything
// reaching here is a probe or a typo and gets nothing back.
//
// The pattern EXCLUDES api/ on purpose. This catch-all lives in the `web` group,
// so CSRF runs before the route ever executes; without the exclusion a malformed
// API path (an empty route segment, a mistyped id) misses the api routes, lands
// here, and answers 419 "CSRF token mismatch" — sending whoever is debugging it
// off hunting an auth problem that does not exist. Excluded, those paths fall
// through to the JSON fallback in routes/api.php instead.
Route::any('{any}', function () {
    abort(403);
})->where('any', '^(?!api/).*$');
