<?php

/**
 * Canonical configuration reference for dereuromark/cakephp-passkeys.
 *
 * Copy values from this file into your host app's `config/passkeys.php`
 * (or merge into `config/app_custom.php`) and override only what differs
 * from the defaults. The plugin reads from the `Passkeys.*` Configure
 * namespace at bootstrap; anything you don't set falls back to the
 * default in `vendor/dereuromark/cakephp-passkeys/config/bootstrap.php`.
 */
return [
    'Passkeys' => [
        // Master switch. Set false to hide all plugin UI surfaces and
        // refuse all ceremonies without removing code / running a migration.
        'enabled' => true,

        // WebAuthn Relying Party identity. rpId MUST match the host the
        // browser sees (no scheme, no port). A passkey registered against
        // one rpId cannot be used against another by spec — the whole
        // phishing-resistance story.
        'rpId' => env('WEBAUTHN_RP_ID', 'localhost'),
        'rpName' => env('WEBAUTHN_RP_NAME', 'My App'),
        'rpIcon' => null, // optional data: URI or absolute URL

        'maxPerUser' => 5,
        'challengeTtl' => 300, // seconds; WebAuthn nonce cache TTL
        'reauthWindow' => 900, // seconds; how long a reauth stays "fresh"

        // Host user mapping — overrides convention, NOT a replacement.
        // For unusual schemas implement Passkeys\Contract\PasskeyUserInterface
        // on your User entity and set `userAdapter` below instead.
        'users' => [
            'table' => 'Users',
            'columns' => [
                'id' => 'id',
                'email' => 'email',
                'displayName' => 'name', // falls back to email if column missing
            ],
            'activeColumn' => null, // e.g. 'is_active'
        ],

        // Multi-tenant SaaS hook. null = single-tenant (no tenant column on
        // passkeys table). Set to the column name (e.g. 'account_id') to
        // scope passkey lookups by tenant.
        'tenancy' => [
            'column' => null,
            'sessionKey' => null, // e.g. 'Auth.account_id'
        ],

        // WebAuthn ceremony tuning — FIDO2-spec defaults are fine for most.
        'ceremony' => [
            'userVerification' => 'required', // required on login by spec
            'residentKey' => 'preferred', // enables iCloud / GPM sync
            'attestation' => 'none', // privacy-respecting default
            'timeout' => 60_000, // ms; passed to OS dialog
        ],

        // Base Cake CacheEngine config to clone for the WebAuthn challenge
        // store. On bootstrap the plugin registers a dedicated
        // `passkeys_challenges` engine that inherits this engine's settings
        // but overrides `duration` with `challengeTtl` above — the only
        // way to honor the configured TTL without changing every write site.
        'cache' => 'default',

        'mfa' => [
            // Session key the plugin writes on a UV-verified login. Your
            // auth middleware reads this to decide whether to skip a 2nd
            // factor prompt.
            'sessionFlag' => 'Passkeys.mfa_satisfied',
        ],

        // Session hand-off path the plugin writes the authenticated user
        // id to on successful passkey login. Match your host's auth shape:
        //   - cakephp/authentication (modern):  'Identity.id'
        //   - legacy AuthComponent:             'Auth.id' (default)
        //   - custom middleware:                whatever your stack reads
        // The plugin also fires a `Passkeys.afterLogin` event — subscribers
        // can ignore the session write entirely and build the identity
        // payload themselves.
        'session' => [
            'userIdKey' => 'Auth.id',
        ],

        // Bind your own RateLimiterInterface implementation. The plugin
        // ships NullRateLimiter (always allows). Recommended: protect
        // registerStart + loginStart paths against abuse.
        'rateLimiter' => null,

        // URL prefix the plugin's 8 actions mount under.
        'urlPrefix' => '/passkeys',

        // Optional callable(ServerRequest): bool for authorize decisions.
        'authorize' => null,

        // Optional FQCN implementing PasskeyUserInterface to replace the
        // convention-based UserResolver.
        'userAdapter' => null,

        // Host-owned "what's a passkey?" deep link. Null hides the link
        // in the Manager Cell's empty state.
        'docsUrl' => null,

        'nudge' => [
            'enabled' => true,
            'afterLogins' => 3,
            'redisplayAfterDays' => 14,
        ],
    ],
];
