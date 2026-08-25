<?php

use App\Enums\ChoreIcon;
use App\Enums\LootCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the Loot Shop something a child can look at rather than read.
 *
 * The catalogue had grown past the point where a wall of name-and-paragraph
 * cards works: the kids stopped shopping, and new rewards were never found
 * because finding anything meant reading everything. Three columns fix the
 * three halves of that — a picture, a pile to be in, and a marker for what has
 * arrived since you last looked.
 *
 * `icon` is a Font Awesome class string and *not* cast to an enum, exactly as
 * `chores.icon` is: the presets are a shortlist the picker offers, not the
 * limit of what a parent may type.
 *
 * Existing rows are filed and given faces from their own text, so a shop that
 * already has thirty things in it arrives sorted rather than needing an
 * evening of tidying before any of this is worth anything. Anything the
 * keyword pass can't place is left null — an item in the wrong pile is worse
 * than one under "Everything else", because a kid hunting for it searches the
 * pile it should be in and concludes it isn't there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_items', function (Blueprint $table) {
            $table->string('icon', 64)->nullable()->after('description');
            $table->string('category', 32)->nullable()->after('icon');
        });

        Schema::table('profiles', function (Blueprint $table) {
            // Null means "never looked", which the shop reads as *everything*
            // being new — the right answer for a kid opening a restocked shop
            // for the first time, and the same convention badges_seen_at uses.
            $table->timestamp('loot_seen_at')->nullable()->after('badges_seen_at');
        });

        foreach (DB::table('store_items')->select('id', 'name', 'description')->get() as $item) {
            $text = $item->name.' '.$item->description;

            DB::table('store_items')->where('id', $item->id)->update([
                'category' => LootCategory::forText($text)?->value,
                'icon' => ChoreIcon::normalizeClass(self::guessIcon($text)),
            ]);
        }
    }

    /**
     * A face for a reward, from the words in it.
     *
     * Deliberately a short list of the things families actually put in a
     * rewards shop, and deliberately separate from ChoreIcon's keyword pass —
     * that one is about work, this one is about prizes, and "wash" meaning
     * laundry has no business here.
     */
    private static function guessIcon(string $text): ?string
    {
        $map = [
            'fa-solid fa-ice-cream' => ['ice cream', 'sundae'],
            'fa-solid fa-cookie-bite' => ['sweets', 'candy', 'chocolate', 'biscuit', 'cake', 'donut'],
            'fa-solid fa-pizza-slice' => ['pizza', 'takeaway', 'mcdonalds', 'burger'],
            'fa-solid fa-gamepad' => ['xbox', 'playstation', 'switch', 'game', 'games', 'gaming', 'roblox', 'minecraft', 'controller'],
            'fa-solid fa-tv' => ['tv', 'telly', 'screen', 'movie night', 'youtube'],
            'fa-solid fa-film' => ['cinema', 'movies'],
            'fa-solid fa-hippo' => ['zoo'],
            'fa-solid fa-person-swimming' => ['swimming', 'pool', 'beach'],
            'fa-solid fa-bowling-ball' => ['bowling'],
            'fa-solid fa-money-bill' => ['money', 'cash', 'pounds', 'dollars'],
            'fa-solid fa-cubes' => ['lego', 'blocks'],
            'fa-solid fa-book' => ['book', 'books', 'story', 'stories'],
            'fa-solid fa-moon' => ['stay up', 'bedtime', 'late', 'sleepover'],
            'fa-solid fa-heart' => ['together', 'one on one', 'date'],
            'fa-solid fa-gift' => ['toy', 'toys', 'present'],
        ];

        $haystack = mb_strtolower($text);

        foreach ($map as $class => $words) {
            foreach ($words as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $haystack) === 1) {
                    return $class;
                }
            }
        }

        return null;
    }

    public function down(): void
    {
        Schema::table('store_items', function (Blueprint $table) {
            $table->dropColumn(['icon', 'category']);
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('loot_seen_at');
        });
    }
};
