<?php

namespace App\Models;

use App\Enums\FeedMessageKind;
use App\Enums\FeedStamp;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing said in a room. See the create migration for what each kind is and
 * why none of them pays anything.
 */
class FeedMessage extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'household_id',
        'room_id',
        'profile_id',
        'kind',
        'body',
        'stamp',
        'drawing_path',
        'image_path',
        'image_width',
        'image_height',
        'subject_id',
        'source_type',
        'source_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => FeedMessageKind::class,
            'stamp' => FeedStamp::class,
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(FeedRoom::class, 'room_id');
    }

    /** Who said it. Set on an event too — an event is always about somebody. */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Who a shout-out is about. */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'subject_id');
    }

    /** The score, badge or monster an event came off. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(FeedReaction::class, 'message_id');
    }

    /**
     * The reactions grouped for drawing: one pill per emoji, carrying its count
     * and whether `$viewer` is among them — which is what makes tapping a pill
     * take your own reaction back rather than add a second one.
     *
     * Reads the loaded relation rather than querying, so a room of fifty
     * messages is one reactions query and not fifty.
     *
     * @return array<int, array{emoji: string, count: int, mine: bool}>
     */
    public function reactionPillsFor(Profile $viewer): array
    {
        return $this->reactions
            ->groupBy('emoji')
            ->map(fn ($group, string $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'mine' => $group->contains(fn (FeedReaction $r) => (int) $r->profile_id === (int) $viewer->id),
            ])
            ->values()
            ->all();
    }

    /**
     * Where the browser fetches this message's picture from — a finger drawing
     * or a photograph. Null for every other kind.
     *
     * Always the app's own route, never a bucket URL: the bucket is private,
     * and the route is what checks the viewer can read this message's room.
     * Both names land on the same controller, so there is one copy of that
     * check rather than two — see FeedMediaController.
     */
    public function mediaUrl(): ?string
    {
        return match (true) {
            $this->kind === FeedMessageKind::Drawing && (bool) $this->drawing_path => route('feed.drawing', $this),
            $this->kind === FeedMessageKind::Photo && (bool) $this->image_path => route('feed.photo', $this),
            default => null,
        };
    }

    /**
     * The one line this message contributes to its room's preview in the room
     * list. A body where there is one, and what happened where there isn't —
     * "sent a drawing" reads better than a truncated filename.
     */
    public function preview(): string
    {
        return match ($this->kind) {
            FeedMessageKind::Text, FeedMessageKind::Shoutout, FeedMessageKind::Event => (string) $this->body,
            FeedMessageKind::Quote => '“'.$this->body.'”',
            FeedMessageKind::Stamp => $this->stamp?->label() ?? 'sent a stamp',
            FeedMessageKind::Drawing => 'sent a drawing',
            // The caption where there is one: it is what was actually said.
            FeedMessageKind::Photo => (string) ($this->body ?: 'sent a photo'),
        };
    }
}
