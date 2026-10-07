<?php

/**
 * Every configuration key of dereuromark/cakephp-passkeys, with its default.
 *
 * Copy the keys you want to change into your application's config, under the
 * `CakePasskeys` key. Anything you leave out keeps the default from
 * `config/bootstrap.php` of the plugin.
 */
return [
    'CakePasskeys' => [
        // Master switch. When false, the endpoints answer 404 and the cells
        // and helper render nothing.
        'enabled' => true,

        // Relying party id: the domain your users see, without scheme and
        // port. A passkey is bound to it for good.
        'rpId' => env('WEBAUTHN_RP_ID', 'localhost'),

        // Name the authenticator shows next to the passkey.
        'rpName' => env('WEBAUTHN_RP_NAME', 'My App'),

        // Origins a response may come from, e.g. ['https://example.com'].
        // With null, any HTTPS origin on the rpId or a subdomain of it is
        // accepted, on any port. http://localhost always works.
        'allowedOrigins' => null,

        // Passkeys per user. 0 for no limit.
        'maxPerUser' => 5,

        // Seconds a challenge stays valid between the start and the finish
        // request.
        'challengeTtl' => 300,

        // Seconds a re-confirmation counts as fresh. See Reauth::isFresh().
        'reauthWindow' => 900,

        'users' => [
            // Table your users live in.
            'table' => 'Users',
            // Column names for the id, the email and the display name. The
            // display name falls back to the email when empty.
            'columns' => [
                'id' => 'id',
                'email' => 'email',
                'displayName' => 'name',
            ],
            // Boolean column, e.g. 'is_active'. Users where it is false
            // cannot register or sign in.
            'activeColumn' => null,
            // Type of `passkeys.user_id`: integer, biginteger, uuid or
            // string. Read by the migration, so set it before migrating.
            'idType' => 'integer',
        ],

        // Closure that returns the id of the signed-in user for a request,
        // or null for a guest: fn (ServerRequest $request) => ...
        // With null, the plugin reads the `identity` request attribute and
        // then the session key below.
        'identityResolver' => null,

        'session' => [
            // Session key the user id is written to after a passkey sign-in,
            // and read from when no identity attribute is present. Empty
            // string to write nothing and rely on the afterLogin event.
            'userIdKey' => 'Auth.id',
        ],

        // URL the JavaScript navigates to after a sign-in.
        'afterLoginRedirect' => '/',

        'login' => [
            // Accept an email address on login/start and narrow the sign-in
            // to that account's passkeys. Reveals whether an address has
            // passkeys, so it is off by default.
            'emailHint' => false,
        ],

        'ceremony' => [
            // required | preferred | discouraged. Only `required` proves a
            // PIN or biometric check.
            'userVerification' => 'required',
            // required | preferred | discouraged. `preferred` gives passkeys
            // that sync and can be offered without a username.
            'residentKey' => 'preferred',
            // none | indirect | direct | enterprise.
            'attestation' => 'none',
            // Milliseconds the browser dialog waits.
            'timeout' => 60_000,
        ],

        // Cache config the challenge store is derived from. The plugin
        // registers its own config `passkeys_challenges` with the same engine
        // and `challengeTtl` as duration. The engine must persist between
        // requests.
        'cache' => 'default',

        'mfa' => [
            // Session flag set after a sign-in with required user
            // verification.
            'sessionFlag' => 'CakePasskeys.mfa_satisfied',
        ],

        // Class name or instance implementing
        // CakePasskeys\Contract\RateLimiterInterface. With null, nothing is
        // limited.
        'rateLimiter' => null,

        // Path the endpoints are mounted under.
        'urlPrefix' => '/passkeys',

        // Link shown in the empty state of the Manager cell.
        'docsUrl' => null,

        'nudge' => [
            // Show the RegisterNudge cell to users without a passkey.
            'enabled' => true,
            // Days until a dismissed nudge is shown again.
            'redisplayAfterDays' => 14,
        ],
    ],
];
