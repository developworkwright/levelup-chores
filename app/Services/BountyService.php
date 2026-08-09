<?php

namespace App\Services;

use App\Enums\BountyKind;
use App\Enums\BountyStatus;
use App\Enums\ChoreCadence;
use App\Enums\CompletionStatus;
use App\Enums\LedgerKind;
use App\Enums\ProfileRole;
use App\Enums\TicketKind;
use App\Enums\TradeAsset;
use App\Exceptions\BountyUnavailableException;
use App\Exceptions\InsufficientPointsException;
use App\Exceptions\InsufficientTicketsException;
use App\Models\Bounty;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Household;
use App\Models\Profile;
use App\Notifications\BountyUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Every deal in which somebody does something and somebody pays for it.
 *
 * That includes jobs aimed at one sibling, which used to be a sibling trade
 * with a favour on one side. The split was by audience, and it meant the same
 * deal behaved two different ways: aimed at a sibling it paid the moment it was
 * accepted, before the dishes were touched, while posted openly it went through
 * claim, done and confirm. Now the line is drawn by *kind of deal* instead —
 * work lives here and always runs the full cycle, and
 * {@see SiblingOfferService} is left with straight currency swaps, which
 * genuinely have nothing to wait for.
 *
 * A `target_profile_id` is therefore the only difference between "pay Nova to
 * do the dishes" and "pay anyone to do the dishes". Set, it is a deal between
 * two kids and nobody else can see or take it — including a parent, who would
 * otherwise be hijacking it. Null, it is a race.
 *
 * ## Worker and payer
 *
 * Every bounty has exactly two roles and neither is stored — both are read off
 * {@see BountyKind}, so a row can't disagree with itself about who owes whom.
 * On a wanted bounty the poster pays and the taker works; on an offered one it
 * is the other way round. Every rule below is written against worker and payer
 * rather than poster and taker, which is what lets one state machine serve both
 * directions.
 *
 * ## Escrow
 *
 * The payer's reward is held the moment they commit, exactly as
 * {@see StoreService::redeem()} deducts up front — so three 100-point bounties
 * can't be run off a 100-point balance. That moment differs by kind: on a
 * wanted bounty the payer is the poster, so it is held at post; on an offered
 * one the payer is whoever takes it, so it is held at claim, and their balance
 * is checked then rather than at post — they never agreed to hold anything
 * earlier.
 *
 * Every path that ends a bounty without a payout routes through
 * {@see self::refund()}, so held points can't be stranded.
 *
 * ## A parent doesn't pay, they hire
 *
 * Kid-to-kid bounties move points sideways and the household total is
 * unchanged. A parent taking one would *mint* points, and points are backed by
 * `points_per_dollar` — real money. So {@see self::hire()} does not pay
 * anything: it creates a one-time chore at the agreed price, already claimed by
 * the kid who offered. From there it is an ordinary pending completion and runs
 * the one path every chore runs — ledger, XP, family goal, badges, the boss
 * taking damage. There is deliberately no second way to earn.
 */
class BountyService
{
    public function __construct(
        private LedgerService $ledger,
        private TicketService $tickets,
        private BadgeService $badges,
    ) {}

    /**
     * @param  Profile|null  $target  one sibling to aim it at, or null for the open board
     */
    public function post(
        Profile $poster,
        BountyKind $kind,
        TradeAsset $asset,
        int $amount,
        string $description,
        ?Profile $target = null,
    ): Bounty {
        if (! $poster->isKid()) {
            throw new InvalidArgumentException('Bounties are posted by kids.');
        }

        if ($target !== null) {
            if (! $target->isKid() || $target->household_id !== $poster->household_id) {
                throw new InvalidArgumentException('Pick a sibling to aim this at.');
            }

            if ($target->is($poster)) {
                throw new InvalidArgumentException('You cannot aim a job at yourself.');
            }
        }

        if (! $asset->isCurrency()) {
            throw new InvalidArgumentException('Price the job in points or tickets.');
        }

        if ($amount < $asset->minAmount() || $amount > $asset->maxAmount()) {
            throw new InvalidArgumentException(
                'Price it between '.$asset->minAmount().' and '.$asset->maxAmount().' '.strtolower($asset->label()).'.'
            );
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException('Say what the job is first.');
        }

        if (mb_strlen($description) > Bounty::MAX_DESCRIPTION) {
            throw new InvalidArgumentException('That is too long — keep it to one line.');
        }

        // Only a wanted bounty has a payer yet. An offered one is a promise of
        // work, and there is nothing to hold until somebody agrees to pay.
        if ($kind->posterPays()) {
            $this->assertCanAfford($poster, $asset, $amount);
        }

        $bounty = DB::transaction(function () use ($poster, $kind, $asset, $amount, $description, $target) {
            $bounty = Bounty::create([
                'household_id' => $poster->household_id,
                'poster_profile_id' => $poster->id,
                'target_profile_id' => $target?->id,
                'kind' => $kind,
                'reward_asset' => $asset,
                'reward_amount' => $amount,
                'description' => $description,
                'status' => BountyStatus::Open,
                'expires_at' => now()->addHours(Bounty::OPEN_HOURS),
            ]);

            if ($kind->posterPays()) {
                $this->hold($bounty, $poster, 'posted');
            }

            return $bounty;
        });

        $this->announce($bounty);

        return $bounty;
    }

    /**
     * A sibling takes the job on — doing it on a wanted bounty, paying for it
     * on an offered one.
     *
     * @throws BountyUnavailableException
     * @throws InsufficientPointsException|InsufficientTicketsException
     */
    public function claim(Bounty $bounty, Profile $taker): void
    {
        $bounty->loadMissing('poster', 'household');

        if (! $bounty->isTakeable()) {
            throw new BountyUnavailableException('That job is no longer up for grabs.');
        }

        if (! $taker->isKid() || $taker->household_id !== $bounty->household_id) {
            throw new BountyUnavailableException('That job belongs to another family.');
        }

        if ($taker->is($bounty->poster)) {
            throw new BountyUnavailableException('You posted this one.');
        }

        // A targeted job is a deal between two people. Everything else is a
        // race, and the first to tap it wins.
        if (! $bounty->isOpenTo($taker)) {
            throw new BountyUnavailableException('That one is meant for somebody else.');
        }

        // On an offered bounty the taker is the one paying, so this is the
        // moment their side is checked and held.
        if (! $bounty->kind->posterPays()) {
            $this->assertCanAfford($taker, $bounty->reward_asset, $bounty->reward_amount);
        }

        DB::transaction(function () use ($bounty, $taker) {
            $bounty->status = BountyStatus::Claimed;
            $bounty->claimed_by_profile_id = $taker->id;
            $bounty->claimed_at = now();
            $bounty->claim_expires_at = now()->addHours(Bounty::CLAIM_HOURS);
            $bounty->save();

            if (! $bounty->kind->posterPays()) {
                $this->hold($bounty->refresh(), $taker, 'taken');
            }
        });

        $this->notify(
            $bounty->poster,
            'Someone took your job',
            "{$taker->name} took \"{$bounty->description}\".",
        );
    }

    /**
     * The worker reports the job finished. Starts the clock the payer has to
     * answer within.
     *
     * @throws BountyUnavailableException
     */
    public function markDone(Bounty $bounty, Profile $worker): void
    {
        $bounty->loadMissing('poster', 'claimant');

        if ($bounty->status !== BountyStatus::Claimed) {
            throw new BountyUnavailableException('That job is not waiting on the work.');
        }

        if (! $bounty->isWorker($worker)) {
            throw new BountyUnavailableException('That job is not yours to finish.');
        }

        $bounty->status = BountyStatus::Done;
        $bounty->done_at = now();
        $bounty->auto_release_at = now()->addHours(Bounty::CONFIRM_HOURS);
        $bounty->claim_expires_at = null;
        $bounty->save();

        $payer = $bounty->payer();

        if ($payer) {
            $this->notify(
                $payer,
                'A job is finished',
                "{$worker->name} says \"{$bounty->description}\" is done.",
            );
        }
    }

    /**
     * The payer agrees the work is done, and the reward goes across.
     *
     * @throws BountyUnavailableException
     */
    public function confirm(Bounty $bounty, Profile $payer): void
    {
        $bounty->loadMissing('poster', 'claimant', 'household');

        if ($bounty->status !== BountyStatus::Done) {
            throw new BountyUnavailableException('That job is not waiting on you.');
        }

        if (! $bounty->isPayer($payer)) {
            throw new BountyUnavailableException('That job is not yours to settle.');
        }

        $this->settleUp($bounty);
    }

    /**
     * The payer says it isn't done after all. The taker is released and the job
     * goes back on the board.
     *
     * This is what stops the auto-release being a way to get paid for nothing:
     * the clock only runs while the payer says nothing at all. Deliberately not
     * a dispute for a parent to rule on — the two of them put it back up and
     * carry on.
     *
     * @throws BountyUnavailableException
     */
    public function sendBack(Bounty $bounty, Profile $payer): void
    {
        $bounty->loadMissing('poster', 'claimant', 'household');

        if ($bounty->status !== BountyStatus::Done) {
            throw new BountyUnavailableException('That job is not waiting on you.');
        }

        if (! $bounty->isPayer($payer)) {
            throw new BountyUnavailableException('That job is not yours to settle.');
        }

        $worker = $bounty->worker();

        $this->reopen($bounty, 'sent back');

        if ($worker) {
            $this->notify(
                $worker,
                'A job went back on the board',
                "\"{$bounty->description}\" wasn't signed off. It's up for grabs again.",
            );
        }
    }

    /**
     * The poster withdrawing a job nobody has taken. Once it is taken it can
     * only be settled or sent back — pulling it then would be a way to walk
     * away from work already under way.
     *
     * @throws BountyUnavailableException
     */
    public function cancel(Bounty $bounty, Profile $poster): void
    {
        $bounty->loadMissing('poster', 'household');

        if (! $bounty->poster->is($poster)) {
            throw new BountyUnavailableException('That job is not yours to take back.');
        }

        if ($bounty->status !== BountyStatus::Open) {
            throw new BountyUnavailableException('Somebody has already taken that one.');
        }

        DB::transaction(function () use ($bounty) {
            $this->refund($bounty, 'taken back');

            $bounty->status = BountyStatus::Cancelled;
            $bounty->settled_at = now();
            $bounty->save();
        });
    }

    /**
     * A parent takes on an offer of work, optionally at a price of their own.
     *
     * Creates a one-time chore already claimed by the kid who offered, rather
     * than paying anything here — see the class docblock. The chore is barred
     * from the quest pool and the wheel: it is a deal struck with one kid, not
     * a job the household can hand to whoever logs in next.
     *
     * @param  int|null  $points  what the parent will actually pay, or null to take the asking price
     *
     * @throws BountyUnavailableException
     */
    public function hire(Bounty $bounty, Profile $parent, ?int $points = null): Chore
    {
        $bounty->loadMissing('poster', 'household');

        if ($parent->role !== ProfileRole::Parent || $parent->household_id !== $bounty->household_id) {
            throw new BountyUnavailableException('Only a grown-up in this household can hire a job.');
        }

        if (! $bounty->kind->hireable()) {
            throw new BountyUnavailableException('That is a job a kid wants doing, not one on offer.');
        }

        // Aimed at a sibling, so it is theirs to answer. A grown-up stepping in
        // would be hijacking a deal between two kids.
        if ($bounty->isTargeted()) {
            throw new BountyUnavailableException('That offer is aimed at a sibling.');
        }

        if (! $bounty->isTakeable()) {
            throw new BountyUnavailableException('That job is no longer up for grabs.');
        }

        // A chore pays points, so that is the only currency a parent can settle
        // in. A ticket-priced offer is a deal between siblings.
        if ($bounty->reward_asset !== TradeAsset::Points) {
            throw new BountyUnavailableException('Jobs priced in tickets are between kids.');
        }

        $price = $points ?? $bounty->reward_amount;

        if ($price < TradeAsset::Points->minAmount() || $price > TradeAsset::Points->maxAmount()) {
            throw new InvalidArgumentException(
                'Pay between '.TradeAsset::Points->minAmount().' and '.TradeAsset::Points->maxAmount().' points.'
            );
        }

        return DB::transaction(function () use ($bounty, $price) {
            $chore = Chore::create([
                'household_id' => $bounty->household_id,
                'name' => $bounty->description,
                'points' => $price,
                'cadence' => ChoreCadence::Once,
                'quest_eligible' => false,
                'wheel_eligible' => false,
                // Spent on creation: this job belongs to the kid who offered
                // it, and a live one-time chore is on everybody's board.
                'used_at' => now(),
            ]);

            // Claimed rather than merely created. Chores have no notion of
            // being assigned to one kid, so leaving it open would put a deal
            // struck with one child up for a sibling to take. The pending
            // completion *is* the outstanding job: it shows on the kid's Quests
            // page as waiting, and the parent approves it once the work is
            // actually done, which is the ordinary path from here on.
            ChoreCompletion::create([
                'chore_id' => $chore->id,
                'profile_id' => $bounty->poster_profile_id,
                'status' => CompletionStatus::Pending,
                'points_awarded' => $price,
                'submitted_at' => now(),
            ]);

            $bounty->status = BountyStatus::Hired;
            $bounty->reward_amount = $price;
            $bounty->hired_chore_id = $chore->id;
            $bounty->settled_at = now();
            $bounty->save();

            $this->notify(
                $bounty->poster,
                'A grown-up hired you!',
                "\"{$bounty->description}\" for {$price} pts. Get it done and they'll sign it off.",
            );

            return $chore;
        });
    }

    /**
     * Settle everything in the household that ran out of time.
     *
     * The app has no scheduler, so this runs lazily off the bounty board, the
     * same way lapsed trades are settled off the Loot Shop. Household-wide, so
     * whoever opens the tab first clears everybody's — and the kid with
     * something tied up is the one most motivated to look.
     */
    public function sweep(Household $household): void
    {
        $stale = Bounty::where('household_id', $household->id)
            ->live()
            ->with(['poster', 'claimant', 'household'])
            ->get();

        foreach ($stale as $bounty) {
            match (true) {
                // Nobody took it.
                $bounty->status === BountyStatus::Open
                    && $bounty->expires_at?->isPast() => $this->expire($bounty),

                // Taken, then nothing. Back on the board for someone else.
                $bounty->status === BountyStatus::Claimed
                    && $bounty->claim_expires_at?->isPast() => $this->reopen($bounty, 'nobody finished it'),

                // The payer never answered. The work was reported done and
                // saying nothing is not a way to keep the points.
                $bounty->status === BountyStatus::Done
                    && $bounty->auto_release_at?->isPast() => $this->settleUp($bounty),

                default => null,
            };
        }
    }

    /** Pay the worker and close the deal. */
    private function settleUp(Bounty $bounty): void
    {
        $worker = $bounty->worker();
        $payer = $bounty->payer();

        if (! $worker || ! $payer) {
            throw new LogicException('A bounty cannot settle without both sides.');
        }

        DB::transaction(function () use ($bounty, $worker) {
            // The payer's side is already out of their balance, so this is the
            // release half of the escrow rather than a second charge.
            $this->move($bounty, $worker, $bounty->reward_amount, "{$bounty->summary()} (settled)");

            $bounty->status = BountyStatus::Paid;
            $bounty->settled_at = now();
            $bounty->auto_release_at = null;
            $bounty->save();
        });

        // Both balances just moved, and `big_saver` is balance-based.
        $this->badges->evaluate($worker->refresh());
        $this->badges->evaluate($payer->refresh());
    }

    /**
     * Put a taken job back on the board, releasing whoever had it. Refunds
     * first, while the claimant is still on the row to refund.
     */
    private function reopen(Bounty $bounty, string $reason): void
    {
        DB::transaction(function () use ($bounty, $reason) {
            // Only an offered bounty holds anything at this point — on a wanted
            // one the poster is the payer and stays on the hook, because the
            // job is still theirs and still up.
            if (! $bounty->kind->posterPays()) {
                $this->refund($bounty, $reason);
            }

            $bounty->status = BountyStatus::Open;
            $bounty->claimed_by_profile_id = null;
            $bounty->claimed_at = null;
            $bounty->claim_expires_at = null;
            $bounty->done_at = null;
            $bounty->auto_release_at = null;
            $bounty->expires_at = now()->addHours(Bounty::OPEN_HOURS);
            $bounty->save();
        });

        $bounty->unsetRelation('claimant');
    }

    private function expire(Bounty $bounty): void
    {
        DB::transaction(function () use ($bounty) {
            $this->refund($bounty, 'ran out of time');

            $bounty->status = BountyStatus::Expired;
            $bounty->settled_at = now();
            $bounty->save();
        });
    }

    /** Take the reward out of the payer's balance and hold it. */
    private function hold(Bounty $bounty, Profile $payer, string $reason): void
    {
        $this->move($bounty, $payer, -$bounty->reward_amount, "{$bounty->summary()} ({$reason})");
    }

    /**
     * Hand a held reward back. A no-op when nothing is being held, so every
     * ending path can call it without first working out whether there is
     * anything to give back.
     */
    private function refund(Bounty $bounty, string $reason): void
    {
        $holder = $bounty->payer();

        // An offered bounty holds nothing until somebody takes it.
        if (! $holder) {
            return;
        }

        $this->move($bounty, $holder, $bounty->reward_amount, "{$bounty->summary()} ({$reason})");
    }

    /**
     * Move a reward in or out of a kid's balance. The single place that knows
     * which service owns which currency, so adding a third would not mean
     * auditing hold, settle and refund separately.
     */
    private function move(Bounty $bounty, Profile $profile, int $amount, string $label): void
    {
        if ($amount === 0) {
            return;
        }

        $label = "{$profile->name}: {$label}";

        match ($bounty->reward_asset) {
            TradeAsset::Points => $this->ledger->record(
                $bounty->household,
                $profile,
                LedgerKind::Transfer,
                $amount,
                $label,
                $bounty,
            ),
            TradeAsset::Tickets => $this->tickets->record(
                $profile,
                TicketKind::Trade,
                $amount,
                $label,
                $bounty,
            ),
            // Unreachable: post() refuses a non-currency. Here so a new asset
            // can't slip through as a silent no-op.
            TradeAsset::Favour => throw new LogicException('A favour has no balance to move.'),
        };
    }

    /**
     * @throws InsufficientPointsException|InsufficientTicketsException
     */
    private function assertCanAfford(Profile $profile, TradeAsset $asset, int $amount): void
    {
        $shortfall = $amount - $profile->balanceOf($asset);

        if ($shortfall <= 0) {
            return;
        }

        throw $asset === TradeAsset::Tickets
            ? new InsufficientTicketsException($shortfall)
            : new InsufficientPointsException($shortfall);
    }

    /**
     * Tell the household a new job is up. Parents are told about offers of
     * work, since they are the ones who can hire one, and told nothing about a
     * kid touting for a sibling to make their bed.
     */
    private function announce(Bounty $bounty): void
    {
        $audience = Profile::where('household_id', $bounty->household_id)
            ->whereKeyNot($bounty->poster_profile_id)
            // Aimed at one sibling: nobody else needs telling, and no parent
            // can hire it, so telling them would only be an offer they cannot
            // take.
            ->when(
                $bounty->isTargeted(),
                fn ($query) => $query->whereKey($bounty->target_profile_id),
                fn ($query) => $query->when(
                    ! $bounty->kind->hireable(),
                    fn ($kidsOnly) => $kidsOnly->where('role', ProfileRole::Kid),
                ),
            )
            ->get();

        $title = $bounty->kind->posterPays() ? 'New job on the board' : 'Someone is offering to work';
        $body = "{$bounty->poster->name}: {$bounty->summary()}";

        foreach ($audience as $profile) {
            $this->notify($profile, $title, $body, $profile->isParent() ? '/parent/approvals' : '/kid/trades');
        }
    }

    /**
     * Best-effort: the bounty is already recorded and anything it holds already
     * held, so a failed push must not fail the request.
     */
    private function notify(Profile $profile, string $title, string $body, string $url = '/kid/trades'): void
    {
        try {
            $profile->notify(new BountyUpdate($title, $body, $url));
        } catch (Throwable $e) {
            Log::error('Bounty notification failed.', [
                'profile_id' => $profile->id,
                'exception' => $e,
            ]);
        }
    }
}
