<?php

namespace Tests\Feature;

use App\Enums\BossSkin;
use App\Enums\BossStage;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * A skin is an enum case plus a blade component, and the two have to arrive
 * together. Adding the case without the artwork is a 500 on the kids' Goal
 * page the next time the rotation lands on it — which could be weeks later.
 */
class BossSkinCatalogTest extends TestCase
{
    public function test_every_skin_has_artwork(): void
    {
        foreach (BossSkin::cases() as $skin) {
            $this->assertTrue(
                View::exists('components.boss.'.$skin->value),
                "{$skin->value} has no component at resources/views/components/boss/{$skin->value}.blade.php",
            );
        }
    }

    public function test_every_skin_renders_at_every_stage(): void
    {
        foreach (BossSkin::cases() as $skin) {
            foreach (BossStage::cases() as $stage) {
                $svg = Blade::render(
                    '<x-dynamic-component :component="$skin->component()" :skin="$skin" :stage="$stage" />',
                    ['skin' => $skin, 'stage' => $stage],
                );

                $this->assertStringContainsString('<svg', $svg, "{$skin->value} did not draw at {$stage->value}");
                $this->assertStringContainsString($skin->palette()['body'], $svg);
            }
        }
    }

    public function test_every_skin_carries_its_own_name_and_colours(): void
    {
        $labels = array_map(fn (BossSkin $skin) => $skin->label(), BossSkin::cases());
        $bodies = array_map(fn (BossSkin $skin) => $skin->palette()['body'], BossSkin::cases());

        $this->assertSame($labels, array_unique($labels), 'Two monsters share a name.');
        $this->assertSame($bodies, array_unique($bodies), 'Two monsters share a body colour.');
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
