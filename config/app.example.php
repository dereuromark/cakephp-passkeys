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

        // AAGUIDs (authenticator models) a new passkey may come from, e.g.
        // ['cb69481e-8ff7-4039-93ec-0a2729a154a8']. Case-insensitive. With
        // null or [], any authenticator is accepted. When set, passkeys
        // without an AAGUID are refused too. Existing passkeys are not
        // affected. The AAGUID is reported by the authenticator and not
        // verified, so this is a policy filter, not proof of the make.
        'allowedAaguids' => null,

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
            // Closure (EntityInterface $user): string for the WebAuthn user
            // handle, at most 64 bytes. Only for passkeys registered before
            // the plugin was used; null uses an HMAC of the id.
            'userHandle' => null,
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
            // With cakephp/authentication loaded, hand the signed-in user row
            // to its persistIdentity() instead of writing userIdKey.
            'persistIdentity' => true,
            // Session key the user id is written to after a passkey sign-in
            // (skipped when the persisted identity sits under the same root
            // key), and read from when no identity attribute is present.
            // Empty string to write nothing and rely on the afterLogin event.
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

        // Cache config the plugin copies for its own configs:
        // `passkeys_challenges` (duration `challengeTtl`) and
        // `passkeys_rate_limit` (one hour). The engine must persist between
        // requests; Redis, Memcached or APCu make challenge use and rate
        // counting atomic.
        'cache' => 'default',

        'mfa' => [
            // Session flag set after a sign-in with required user
            // verification.
            'sessionFlag' => 'CakePasskeys.mfa_satisfied',
        ],

        // Class name or instance implementing
        // CakePasskeys\Contract\RateLimiterInterface. With null, the built-in
        // CacheRateLimiter counts in the `cache` engine; false turns limiting
        // off.
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

        // Answer GET /.well-known/passkey-endpoints, so password managers can
        // link users to where they add and manage passkeys. Each value is a
        // URL string or a Cake URL array; null leaves the key out. With both
        // null, the URL answers 404.
        'wellKnown' => [
            'enroll' => null,
            'manage' => null,
        ],
    ],
];
