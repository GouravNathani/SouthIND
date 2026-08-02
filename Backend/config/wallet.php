<?php

return [
    /*
    |--------------------------------------------------------------------------
    | BookFlowControl wallet API (outbound, server-to-server)
    |--------------------------------------------------------------------------
    |
    | Our backend calls the external wallet service to read the balance/rates and
    | to deduct coins. Requests are HMAC-SHA256 signed (see BookFlowWalletClient).
    | The secret only signs locally and is never transmitted.
    |
    */
    'base_url' => rtrim((string) env('WALLET_API_BASE_URL', 'https://control.bookflow.tech'), '/'),
    'wallet_id' => env('WALLET_ID'),
    'api_key' => env('WALLET_API_KEY'),
    'api_secret' => env('WALLET_API_SECRET'),

    // Every charge reference this panel sends to Control is prefixed with this.
    // All BookFlow panels share ONE wallet account, so the prefix is what keeps
    // their references from colliding — Control dedupes on the reference, and a
    // collision would silently swallow another panel's charge as a replay.
    // Adda = adda-, ShreeJII = shreeji-, BC = bc-, SouthIND = sind-.
    // Config-driven on purpose: it used to be hardcoded in WalletService, which
    // is exactly how a copied panel ends up billing under another one's prefix.
    'reference_prefix' => (string) env('WALLET_REFERENCE_PREFIX', 'sind'),

    // HTTP timeout (seconds) for each wallet API call. Kept short so a slow
    // wallet service never stalls a support/WhatsApp message send.
    'timeout' => (float) env('WALLET_API_TIMEOUT', 4),

    // Clock skew (seconds) tolerated on INBOUND signed requests from Control
    // (see VerifyWalletHmac). Mirrors Control's own timestamp tolerance.
    'inbound_tolerance' => (int) env('WALLET_API_TIMESTAMP_TOLERANCE', 300),

    // How long (seconds) to cache a balance/rates read, to avoid hammering the
    // API on every payout-page poll. Deducts are never cached.
    'balance_cache_ttl' => (int) env('WALLET_BALANCE_CACHE_TTL', 30),

    // After a failed read, how long (seconds) to serve the fallback without
    // re-calling the API. Without this every read during an outage pays the full
    // `timeout` again, and the panel crawls. Kept short so recovery is quick.
    'down_cache_ttl' => (int) env('WALLET_API_DOWN_CACHE_TTL', 10),

    /*
    | Coins per money unit. 1 coin = ₹1 (coins and rupees are the same, no
    | multiplier), so coins_to_deduct = round(cost * 1, 2) = the cost itself.
    | BookFlow now debits exact money to paise precision, so a per-message cost
    | of ₹0.01 deducts exactly 0.01 coins — nothing is rounded away or skipped.
    */
    'coins_per_unit' => 1,

    // Fallback developer payout percent when the wallet API (and the cached
    // last-known value) are both unavailable.
    'fallback_payout_percent' => (float) env('DEVELOPER_PAYOUT_PERCENT', 0.001),

    /*
    |--------------------------------------------------------------------------
    | Self wallet (fallback only)
    |--------------------------------------------------------------------------
    |
    | Used ONLY when the panel cannot get rates from BookFlowControl — either no
    | wallet is connected yet, or the API and the cached last-known state are
    | both cold. Control stays the authority whenever it answers.
    |
    | Without this the panel had no rates, so a message was logged as "cost
    | unavailable" and silently NOT charged — the usage simply vanished. With it
    | every message is still priced and recorded, the self balance goes negative
    | by exactly what is owed, and `wallet:settle` deducts the backlog for real
    | once Control is reachable again.
    |
    | Defaults mirror the rates Control ships on its wallet page.
    |
    */
    'self' => [
        // Self wallet holds no funds — it starts at zero and goes minus, so the
        // negative balance always reads as "this much is owed to Control".
        'opening_balance' => (float) env('WALLET_SELF_OPENING_BALANCE', 0),

        'payout_percent' => (float) env('WALLET_SELF_PAYOUT_PERCENT', 0.01),

        'costs' => [
            'whatsapp_in_cost' => (float) env('WALLET_SELF_WHATSAPP_IN_COST', 0.1),
            'whatsapp_out_cost' => (float) env('WALLET_SELF_WHATSAPP_OUT_COST', 0.3),
            'support_in_cost' => (float) env('WALLET_SELF_SUPPORT_IN_COST', 0.1),
            'support_out_cost' => (float) env('WALLET_SELF_SUPPORT_OUT_COST', 0.3),
            'media_in_cost' => (float) env('WALLET_SELF_MEDIA_IN_COST', 0.1),
            'media_out_cost' => (float) env('WALLET_SELF_MEDIA_OUT_COST', 0.5),
        ],
    ],
];
