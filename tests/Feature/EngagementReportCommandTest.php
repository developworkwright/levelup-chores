<?php

namespace Tests\Feature;

use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\GratitudeEntry;
use App\Models\Household;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EngagementReportCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Console output with its wrapping flattened, so a long line can be
     * asserted on as the one line it logically is.
     */
    private function flatten(string $output): string
    {
        return preg_replace('/\s+/', ' ', $output);
    }

    /**
     * Just the "Never touched:" line, so a feature name appearing elsewhere in
     * the report doesn't count as being on it.
     */
    private function neverLine(string $flattened): string
    {
        preg_match('/Never touched:.*/', $flattened, $matches);

        return $matches[0] ?? '';
    }

    private function approvedChore(Profile $kid, Carbon $when): void
    {
        $chore = Chore::factory()->for($kid->household)->create();

        ChoreCompletion::create([
            'chore_id' => $chore->id,
            'profile_id' => $kid->id,
            'status' => 'approved',
            'points_awarded' => 100,
            'submitted_at' => $when,
            'decided_at' => $when,
        ]);
    }

    public function test_it_lists_each_kid_with_the_features_they_still_use(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->approvedChore($kid, now()->subDays(2));

        $this->artisan('engagement:report')
            ->expectsOutputToContain('Rowan')
            ->expectsOutputToContain('Chores approved')
            ->assertSuccessful();
    }

    public function test_it_calls_a_kid_gone_when_nothing_happened_this_week(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->approvedChore($kid, now()->subDays(40));

        $this->artisan('engagement:report')
            ->expectsOutputToContain('gone')
            ->assertSuccessful();
    }

    public function test_it_calls_a_kid_fading_when_this_week_is_a_fraction_of_the_first_week(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        // Ten actions in the opening week of the window, one in the current
        // week: the shape the report exists to catch.
        for ($i = 0; $i < 10; $i++) {
            $this->approvedChore($kid, now()->subDays(58));
        }

        $this->approvedChore($kid, now()->subDay());

        $this->artisan('engagement:report')
            ->expectsOutputToContain('fading')
            ->assertSuccessful();
    }

    public function test_it_separates_never_touched_features_from_abandoned_ones(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->approvedChore($kid, now()->subDays(30));

        // Gratitude is never written, so it belongs on the "never touched" line
        // rather than in the table as a row of dashes. Asserted against the
        // whole buffer with its whitespace flattened, because that line lists
        // every untouched feature and the console wraps it.
        $this->assertSame(0, Artisan::call('engagement:report'));

        $output = $this->flatten(Artisan::output());

        $this->assertStringContainsString('Never touched:', $output);
        $this->assertStringContainsString('Gratitude written', $output);
    }

    public function test_a_used_feature_stays_out_of_the_never_touched_line(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        GratitudeEntry::create([
            'household_id' => $household->id,
            'profile_id' => $kid->id,
            'entry_date' => now()->subDays(3)->toDateString(),
            'items' => ['the dog'],
            'created_at' => now()->subDays(3),
        ]);

        $this->assertSame(0, Artisan::call('engagement:report'));

        $output = $this->flatten(Artisan::output());

        $this->assertStringContainsString('Never touched:', $output);
        $this->assertStringNotContainsString('Gratitude written', $this->neverLine($output));
    }

    public function test_it_can_report_a_single_kid(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Rowan']);
        Profile::factory()->for($household)->create(['name' => 'Wren']);

        $this->artisan('engagement:report', ['--kid' => 'Rowan'])
            ->doesntExpectOutputToContain('Wren')
            ->assertSuccessful();
    }

    public function test_it_fails_on_an_unknown_kid(): void
    {
        Profile::factory()->create(['name' => 'Rowan']);

        $this->artisan('engagement:report', ['--kid' => 'Nobody'])
            ->expectsOutputToContain('No kid named')
            ->assertFailed();
    }

    public function test_it_writes_a_csv_when_asked(): void
    {
        $household = Household::factory()->create();
        $kid = Profile::factory()->for($household)->create(['name' => 'Rowan']);

        $this->approvedChore($kid, now()->subDays(2));

        $path = storage_path('app/engagement-test.csv');

        $this->artisan('engagement:report', ['--csv' => $path])->assertSuccessful();

        $this->assertFileExists($path);

        $csv = file_get_contents($path);

        $this->assertStringContainsString('kid,feature', $csv);
        $this->assertStringContainsString('Chores approved', $csv);

        unlink($path);
    }

    /**
     * The whole point of running this against production is that production may
     * be behind this branch. A feature whose table has not been migrated yet
     * must drop out of the report rather than fatal it.
     */
    public function test_it_survives_a_table_that_does_not_exist_yet(): void
    {
        $household = Household::factory()->create();
        Profile::factory()->for($household)->create(['name' => 'Rowan']);

        Schema::drop('playlists');

        $this->artisan('engagement:report')
            ->doesntExpectOutputToContain('Playlists made')
            ->assertSuccessful();
    }
}
