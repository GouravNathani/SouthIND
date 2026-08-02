<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Developer payout percentage
    |--------------------------------------------------------------------------
    |
    | The bill an admin pays the developer, expressed as a PERCENT of the
    | approved-deposit amount. 0.001 means 0.001% (i.e. amount * 0.001 / 100).
    | Example: 2,00,000 approved => 2,00,000 * 0.001 / 100 = 2.00 payout.
    |
    */
    'developer_percent' => (float) env('DEVELOPER_PAYOUT_PERCENT', 0.001),

    /*
    |--------------------------------------------------------------------------
    | Payout start month
    |--------------------------------------------------------------------------
    |
    | Monthly Payout billing began in this month (YYYY-MM). Anything before it
    | is hidden from the Super Admin payout views, even if older approved
    | deposits exist. Empty (the default) shows from the first approved deposit.
    |
    | Deliberately EMPTY here. The panel this was ported from hardcodes its own
    | billing start date, which is meaningless for a new deployment — carried
    | over unchanged it would silently hide SouthIND's own first months of data.
    | Set PAYOUT_START_MONTH on the server if and when billing actually starts
    | mid-life.
    |
    */
    'start_month' => env('PAYOUT_START_MONTH', ''),
];
