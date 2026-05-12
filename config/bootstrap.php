<?php

return [
    'Passkeys' => [
        'enabled' => true,
        'rpId' => 'localhost',
        'rpName' => 'My App',
        'rpIcon' => null,
        'maxPerUser' => 5,
        'challengeTtl' => 300,
        'reauthWindow' => 900,
        'users' => [
            'table' => 'Users',
            'columns' => ['id' => 'id', 'email' => 'email', 'displayName' => 'name'],
            'activeColumn' => null,
        ],
        'tenancy' => ['column' => null, 'sessionKey' => null],
        'ceremony' => [
            'userVerification' => 'required',
            'residentKey' => 'preferred',
            'attestation' => 'none',
            'timeout' => 60_000,
        ],
        'cache' => 'default',
        'mfa' => ['sessionFlag' => 'Passkeys.mfa_satisfied'],
        'session' => ['userIdKey' => 'Auth.id'],
        'rateLimiter' => null,
        'urlPrefix' => '/passkeys',
        'authorize' => null,
        'userAdapter' => null,
        'docsUrl' => null,
        'nudge' => ['enabled' => true, 'afterLogins' => 3, 'redisplayAfterDays' => 14],
    ],
];
