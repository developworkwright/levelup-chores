<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The music library is a folder on a disk, not rows in the database, so
         * unlike everything else in this suite it survives RefreshDatabase and
         * arrives full of whatever songs the developer happens to have locally.
         *
         * The kid header draws that library on every kid page, which quietly
         * made every assertion in the suite depend on it: three tests started
         * failing the moment a song called "Boss Fight Arcade" was added,
         * because a page asserting it draws no boss could suddenly see the word.
         *
         * So the library starts empty everywhere. A test that wants songs puts
         * them on the faked disk itself — see MusicTest.
         */
        Storage::fake('music');
        config(['filesystems.music_disk' => 'music']);
    }
}
