<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | diederik is single-user and uses the default `web` session guard. No
    | API guard, no Passport bridge.
    */

    'guard' => 'web',

    'middleware' => ['web'],

    'auth_middleware' => 'auth',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'views' => true,

    'home' => '/',

    'prefix' => '',

    'domain' => null,

    'lowercase_usernames' => true,

    'limiters' => [
        'login' => 'login',
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
    | Single-user app: registration / password reset / email verification /
    | 2FA / passkeys are all out of scope for now. The only enabled feature
    | is `updatePasswords` so a future operational-hardening phase can ship
    | a settings-page password change without a config change here.
    */

    'features' => [
        Features::updatePasswords(),
    ],

];
