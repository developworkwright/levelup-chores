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
        | The mp3s the kid header plays, on a disk of their own rather than in
        | the repository: git keeps every version of a binary forever, and a
        | music library gets re-encoded, renamed and replaced. Adding a song is
        | content, not a deploy.
        |
        | Neither disk below is the one production uses. Laravel Cloud builds
        | its own from LARAVEL_CLOUD_DISK_CONFIG — see Illuminate\Foundation\
        | Cloud::configureDisks() — under whatever name the bucket has in the
        | dashboard, and it never reads the AWS_* variables at all. So on Cloud,
        | MUSIC_DISK names *that* disk and neither of these is touched.
        |
        | 'music' is the local folder, which is what lets development run with
        | no bucket at all. 'music_cloud' is for any other S3-compatible setup
        | wired up by hand — Herd's MinIO, or a host that isn't Cloud.
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
            // No 'root' prefix, so this disk holds songs exactly where a
            // Cloud-built disk does — at the top of the bucket. A prefix here
            // and none there would mean the library moved depending on the
            // host, and the listing already ignores everything but mp3s.
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

        /*
        |----------------------------------------------------------------------
        | Finger Drawings
        |----------------------------------------------------------------------
        |
        | The PNGs the kids draw in the family feed. On their own disk for the
        | same reason the mp3s are: they are content a six-year-old makes on a
        | Tuesday, not something that belongs in git forever.
        |
        | Same two-disk shape as the music library, and the same caveat — on
        | Laravel Cloud neither of these is used, because Cloud builds its own
        | disk from LARAVEL_CLOUD_DISK_CONFIG and DRAWINGS_DISK names that one.
        |
        */

        // Private, and outside public/ entirely: a drawing is only ever read
        // back through FeedDrawingController, which checks the viewer can read
        // the room it was posted in. No 'url' — there is no public address for
        // one to have. The `drawings/` folder is part of every path FeedDrawings
        // writes (see FOLDER there), so files land in
        // storage/app/private/drawings/{household}/.
        'drawings' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        'drawings_cloud' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // No 'root' prefix, matching the music disk and the disk Laravel
            // Cloud builds for the bucket. The `drawings/` folder comes from the
            // path FeedDrawings writes, so it is the same on every disk and a
            // root here would file them under drawings/drawings/.
            // Thrown rather than swallowed. This is written to from a screen a
            // kid is looking at, and a failed upload has to say so rather than
            // return false into a room that then shows an empty frame.
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Music Disk
    |--------------------------------------------------------------------------
    |
    | Which disk the music library actually reads and writes. Left alone it is
    | the local folder, which is what keeps development running with no bucket
    | credentials.
    |
    | On Laravel Cloud this is the name of the attached bucket's disk as it
    | appears in the dashboard, *not* 'music_cloud' — Cloud registers that disk
    | itself, fully configured, and pointing this at a hand-rolled s3 disk
    | reading empty AWS_* variables is how every page that draws the kid header
    | ends up throwing.
    |
    */

    'music_disk' => env('MUSIC_DISK', 'music'),

    /*
    |--------------------------------------------------------------------------
    | Drawings Disk
    |--------------------------------------------------------------------------
    |
    | Which disk the family feed's finger drawings land on. Same rules as the
    | music disk above: the local folder by default so development needs no
    | bucket at all, and on Laravel Cloud the name of the attached bucket's
    | disk as it appears in the dashboard rather than 'drawings_cloud'.
    |
    */

    'drawings_disk' => env('DRAWINGS_DISK', 'drawings'),

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
