<?php

return [
    'CakePasskeys' => [
        'enabled' => true,
        'rpId' => 'localhost',
        'rpName' => 'My App',
        'maxPerUser' => 5,
        'challengeTtl' => 300,
        'reauthWindow' => 900,
        'users' => [
            'table' => 'Users',
            'columns' => ['id' => 'id', 'email' => 'email', 'displayName' => 'name'],
            'activeColumn' => null,
            'idType' => 'integer',
        ],
        'allowedOrigins' => null,
        'login' => ['emailHint' => false],
        'afterLoginRedirect' => '/',
        'ceremony' => [
            'userVerification' => 'required',
            'residentKey' => 'preferred',
            'attestation' => 'none',
            'timeout' => 60_000,
        ],
        'cache' => 'default',
        'mfa' => ['sessionFlag' => 'CakePasskeys.mfa_satisfied'],
        'session' => ['userIdKey' => 'Auth.id'],
        'rateLimiter' => null,
        'urlPrefix' => '/passkeys',
        'identityResolver' => null,
        'docsUrl' => null,
        'nudge' => ['enabled' => true, 'redisplayAfterDays' => 14],
    ],
];
