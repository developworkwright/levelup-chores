<?php

use App\Models\Profile;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This app has no email/password accounts — everyone (kids and the
    | parent) authenticates via the profile-picker + PIN flow against the
    | "profile" guard below.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'profile'),
        'passwords' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    */

    'guards' => [
        'profile' => [
            'driver' => 'session',
            'provider' => 'profiles',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'profiles' => [
            'driver' => 'eloquent',
            'model' => Profile::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | Not applicable — there are no passwords to reset.
    |
    */

    'passwords' => [],

    'password_timeout' => 10800,

];
