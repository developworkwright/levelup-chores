<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppIconTest extends TestCase
{
    /**
     * Every icon the layout head and the manifest point at has to exist on
     * disk — a missing file is a silent 404 that only shows up as a blank
     * favicon or a generic glyph on an installed home screen.
     */
    public function test_every_icon_the_manifest_advertises_exists(): void
    {
        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();

        $this->assertNotEmpty($manifest['icons']);

        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));

            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path), "{$icon['src']} is empty");
        }
    }

    public function test_every_icon_the_layout_links_exists(): void
    {
        $head = file_get_contents(resource_path('views/layouts/app.blade.php'));

        preg_match_all('#href="(/icons/[^"]+)"#', $head, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $src) {
            $path = public_path(ltrim($src, '/'));

            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path), "{$src} is empty");
        }
    }

    public function test_the_bare_favicon_is_a_real_ico_file(): void
    {
        $path = public_path('favicon.ico');

        $this->assertFileExists($path);

        /** Browsers ask for /favicon.ico regardless of the link tags; a zero-byte
         * file there renders as a broken glyph in the tab. */
        $header = unpack('vreserved/vtype/vcount', file_get_contents($path, false, null, 0, 6));

        $this->assertSame(0, $header['reserved']);
        $this->assertSame(1, $header['type']);
        $this->assertGreaterThan(0, $header['count']);
    }

    public function test_the_scalable_icon_is_the_chest_from_the_design_handoff(): void
    {
        $svg = file_get_contents(public_path('icons/icon.svg'));

        $this->assertStringContainsString('viewBox="0 0 64 64"', $svg);
        $this->assertStringContainsString('#2a1150', $svg);
        $this->assertStringContainsString('#ffc93d', $svg);
        $this->assertStringContainsString('#ffe14d', $svg);
    }
}
