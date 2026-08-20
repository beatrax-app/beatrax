<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | beatrax is single-user and uses the default `web` session guard. No
    | API guard, no Passport bridge.
    */

    'guard' => 'web',

    'middleware' => ['web'],

    'auth_middleware' => 'auth',

    'passwords' => 'users',

    'username' => 'username',

    'email' => 'email',

    // The app owns its auth screens. Left on, Fortify registers
    // /user/confirm-password, which 500s on a ConfirmPasswordViewResponse
    // nothing binds — a dead route whose only behaviour is to crash.
    'views' => false,

    'home' => '/',

    'prefix' => '',

    'domain' => null,

    'lowercase_usernames' => true,

    'limiters' => [
        'login' => null,
        'passkeys' => null,
    ],

    'paths' => [
        'login' => null,
        'logout' => null,
        'password' => [
            'request' => null,
            'reset' => null,
            'email' => null,
            'update' => null,
            'confirm' => null,
            'confirmation' => null,
        ],
        'register' => null,
        'verification' => [
            'notice' => null,
            'verify' => null,
            'send' => null,
        ],
        'user-profile-information' => [
            'update' => null,
        ],
        'user-password' => [
            'update' => null,
        ],
        'two-factor' => [
            'login' => null,
            'enable' => null,
            'confirm' => null,
            'disable' => null,
            'qr-code' => null,
            'secret-key' => null,
            'recovery-codes' => null,
        ],
        'passkey' => [
            'login-options' => null,
            'login' => null,
            'confirm-options' => null,
            'confirm' => null,
            'registration-options' => null,
            'store' => null,
            'destroy' => null,
        ],
    ],

    'redirects' => [
        'login' => null,
        'logout' => null,
        'password-confirmation' => null,
        'register' => null,
        'email-verification' => null,
        'password-reset' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Features Enabled
    |--------------------------------------------------------------------------
    |
    | Features intentionally empty: signup uses a custom route gate;
    | password reset uses recovery codes; there is no email verification
    | or two-factor authentication. The login and logout routes are wired
    | directly by the Auth module rather than by a Fortify feature flag.
    */

    'features' => [],

];
