<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Background Music
        |----------------------------------------------------------------------
        |
        | The mp3s the kid header plays. Two disks for one job: locally the
        | files sit in public/ and are served straight off disk, and in
        | production they live in the attached bucket. 'music_disk' below picks
        | between them, so nothing in the app names a driver.
        |
        | They are on a disk of their own rather than in the repository because
        | git keeps every version of a binary forever, and a music library gets
        | re-encoded, renamed and replaced. Adding a song is content, not a
        | deploy.
        |
        */

        'music' => [
            'driver' => 'local',
            'root' => public_path('music'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/music',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

        'music_cloud' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // Its own prefix, so the bucket stays usable for anything else.
            'root' => 'music',
            // Deliberately no 'visibility' => 'public'. The bucket itself stays
            // private and reads go through the domain the platform puts in
            // front of it, which arrives here as AWS_URL. Asking for a public
            // ACL on top of that is at best redundant and at worst a rejected
            // upload: a bucket with ACLs disabled — the default on S3 for years
            // now — answers an ACL request with a 400 rather than ignoring it.
            // Unlike the stock disks: this one is written to from a screen a
            // parent is looking at, and a failed upload has to say so rather
            // than return false into a page that then claims it worked.
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Music Disk
    |--------------------------------------------------------------------------
    |
    | Which of the two disks above the music library actually uses. Left alone
    | it is the local folder, which is what keeps `npm run dev` working with no
    | bucket credentials; production sets MUSIC_DISK=music_cloud.
    |
    */

    'music_disk' => env('MUSIC_DISK', 'music'),

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
