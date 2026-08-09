<?php

namespace Tests\Feature;

use App\Enums\BossSkin;
use App\Enums\BossStage;
use Tests\TestCase;

/**
 * The boss artwork lives in `resources/js/monsters.js`, shipped verbatim from
 * the design bundle; PHP holds only the keys, names and taglines the server has
 * to say out loud. That is a seam, and this is the guard on it.
 *
 * A skin the enum knows about but the artwork doesn't is a blank monster on the
 * kids' Goals page, weeks later, when the rotation finally reaches it. Names
 * drifting apart is worse in a quieter way: a defeat stamps the name it knew at
 * the time, so the trophy shelf would start disagreeing with the screen.
 */
class BossSkinCatalogTest extends TestCase
{
    /**
     * @return array<int, array{key: string, name: string, tagline: string, body: string}>
     */
    private function artworkSkins(): array
    {
        $source = file_get_contents(resource_path('js/monsters.js'));

        preg_match('/const SKIN_LIST = \[(.*?)\n\];/s', $source, $block);

        $this->assertNotEmpty($block, 'Could not find SKIN_LIST in monsters.js.');

        preg_match_all(
            "/key: '([^']+)', name: '([^']+)', tagline: '([^']+)'.*?body: '([^']+)'/",
            $block[1],
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(fn (array $m) => [
            'key' => $m[1],
            'name' => $m[2],
            'tagline' => $m[3],
            'body' => $m[4],
        ], $matches);
    }

    /**
     * @return array<int, array{key: string, label: string, taunt: string}>
     */
    private function artworkStages(): array
    {
        $source = file_get_contents(resource_path('js/monsters.js'));

        preg_match('/const STAGES = \[(.*?)\n\];/s', $source, $block);

        $this->assertNotEmpty($block, 'Could not find STAGES in monsters.js.');

        preg_match_all(
            "/key: '([^']+)', label: '([^']+)'.*?taunt: '(.*?)'\s*\}/",
            $block[1],
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(fn (array $m) => [
            'key' => $m[1],
            'label' => $m[2],
            'taunt' => $m[3],
        ], $matches);
    }

    public function test_every_skin_in_the_enum_has_artwork_and_vice_versa(): void
    {
        $artwork = array_column($this->artworkSkins(), 'key');
        $enum = array_map(fn (BossSkin $skin) => $skin->value, BossSkin::cases());

        // Order matters as well as membership: the enum's order *is* the
        // rotation a family meets the monsters in.
        $this->assertSame($artwork, $enum);
    }

    public function test_names_and_taglines_match_the_artwork(): void
    {
        foreach ($this->artworkSkins() as $skin) {
            $case = BossSkin::from($skin['key']);

            $this->assertSame($skin['name'], $case->label(), "{$skin['key']} has drifted on name.");
            $this->assertSame($skin['tagline'], $case->tagline(), "{$skin['key']} has drifted on tagline.");
        }
    }

    public function test_every_stage_in_the_enum_has_artwork_and_the_same_words(): void
    {
        $artwork = $this->artworkStages();

        $this->assertSame(
            array_column($artwork, 'key'),
            array_map(fn (BossStage $stage) => $stage->value, BossStage::cases()),
        );

        foreach ($artwork as $stage) {
            $case = BossStage::from($stage['key']);

            $this->assertSame($stage['label'], $case->label(), "{$stage['key']} has drifted on label.");
            $this->assertSame($stage['taunt'], $case->taunt(), "{$stage['key']} has drifted on taunt.");
        }
    }

    public function test_no_two_monsters_share_a_name_or_a_body_colour(): void
    {
        $skins = $this->artworkSkins();

        $names = array_column($skins, 'name');
        $bodies = array_column($skins, 'body');

        $this->assertSame($names, array_unique($names), 'Two monsters share a name.');
        $this->assertSame($bodies, array_unique($bodies), 'Two monsters share a body colour.');
    }

    public function test_the_artwork_defines_nothing_but_its_own_global(): void
    {
        // It is shipped verbatim from a design bundle and loaded on every kid
        // page, so a later drop of the file gets the same read the first one
        // did rather than being trusted on the strength of the last one.
        $source = file_get_contents(resource_path('js/monsters.js'));

        foreach (['fetch(', 'XMLHttpRequest', 'eval(', 'new Function', 'document.cookie', 'localStorage', 'import ', 'require('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "monsters.js reaches for {$forbidden}.");
        }

        $this->assertStringContainsString('if (window.FQMonsters) return;', $source, 'monsters.js is no longer idempotent.');
    }

    /**
     * A queued defeat stores the monster's name, because the household will
     * very likely have rotated on by the time a kid reads it. The knockout card
     * has to turn that name back into artwork — so every label has to be
     * unique, and every one has to survive the round trip.
     */
    public function test_every_label_finds_its_way_back_to_its_own_skin(): void
    {
        $labels = array_map(fn (BossSkin $skin) => $skin->label(), BossSkin::cases());

        $this->assertSame(
            count($labels),
            count(array_unique($labels)),
            'Two monsters share a name, so a defeat can no longer name which one it was.',
        );

        foreach (BossSkin::cases() as $skin) {
            $this->assertSame($skin, BossSkin::fromLabel($skin->label()));
        }
    }

    public function test_an_unrecognised_name_loses_the_picture_and_nothing_else(): void
    {
        // Defeats queued before the boss battle existed carry no name at all,
        // and a monster renamed since carries one that matches nothing.
        $this->assertNull(BossSkin::fromLabel(null));
        $this->assertNull(BossSkin::fromLabel('The Sock Moth'));
        $this->assertNull(BossSkin::fromLabel('gnash'));
    }

    public function test_the_stage_boundaries_and_their_inverses_agree(): void
    {
        // fromHealth() and entryDamagePercent() are read by the live state and
        // the replay respectively; if they disagree the replay stops on stages
        // the boss was never in.
        foreach (BossStage::cases() as $stage) {
            $health = 100 - $stage->entryDamagePercent();

            $this->assertSame(
                $stage,
                BossStage::fromHealth($health),
                "{$stage->value} claims to start at {$health}% health but that reads as something else",
            );
        }
    }
}
