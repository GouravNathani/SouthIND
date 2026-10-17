<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Adda → SouthIND hand-off (SSO)
    |--------------------------------------------------------------------------
    |
    | After the move, a user logged in to Adda taps "Open SouthIND" and lands
    | here already logged in. Adda signs a short-lived ticket with this shared
    | secret (Adda's SOUTHIND_SSO_SECRET must be the same value); SouthIND checks
    | the signature, the expiry and that the ticket was never used before.
    |
    | Empty secret = the hand-off endpoint is switched off.
    |
    */
    'sso_secret' => env('ADDA_SSO_SECRET', ''),

    // Upper bound on a ticket's lifetime, whatever exp it claims (seconds).
    'sso_max_age' => (int) env('ADDA_SSO_MAX_AGE', 120),
];
