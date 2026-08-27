<?php

namespace App\Console\Commands;

use App\Enums\ProfileRole;
use App\Models\Profile;
use App\Notifications\ChoreReviewed;
use App\Notifications\ParentApprovalNeeded;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Push has four links in its chain — VAPID keys, a stored subscription, the
 * queue, and the push service itself — and a break in any one of them looks
 * identical from the sofa: no notification. This checks each link by name.
 */
class TestNotificationCommand extends Command
{
    protected $signature = 'notifications:test
        {--parent= : Only send to one parent, by name}
        {--kid= : Only send to one kid, by name}
        {--queue : Push it through the queue instead of sending immediately}
        {--check : Report what is configured without sending anything}';

    protected $description = 'Send a test push notification to the household, reporting exactly which link in the chain is broken.';

    public function handle(): int
    {
        $ok = $this->reportConfig();

        if ($this->option('parent') !== null && $this->option('kid') !== null) {
            $this->error('Pass --parent or --kid, not both.');

            return self::FAILURE;
        }

        $role = match (true) {
            $this->option('parent') !== null => ProfileRole::Parent,
            $this->option('kid') !== null => ProfileRole::Kid,
            default => null,
        };

        $profiles = Profile::when($role, fn ($query) => $query->where('role', $role))->get();

        if ($name = $this->option('parent') ?? $this->option('kid')) {
            $profiles = $profiles->filter(fn (Profile $p) => strcasecmp($p->name, $name) === 0)->values();

            if ($profiles->isEmpty()) {
                $this->error(sprintf('No %s named "%s" found.', $role === ProfileRole::Kid ? 'kid' : 'parent', $name));

                return self::FAILURE;
            }
        }

        if ($profiles->isEmpty()) {
            $this->error('No profiles exist, so there is nobody to notify.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Profile', 'Role', 'Devices subscribed'],
            $profiles->map(fn (Profile $p) => [$p->name, $p->role->value, $p->pushSubscriptions()->count()])->all(),
        );

        $subscribed = $profiles->filter(fn (Profile $p) => $p->pushSubscriptions()->exists());

        if ($subscribed->isEmpty()) {
            $this->error('Nobody has subscribed a device yet — no push can be delivered.');
            $this->line('  A parent turns them on from the Approvals tab: "Enable approval alerts".');
            $this->line('  A kid taps the bell in their header.');
            $this->line('  Button dead or missing? The browser reported no push support — on iOS the app');
            $this->line('  must be installed to the Home Screen first, and the site must be over HTTPS.');

            return self::FAILURE;
        }

        if ($this->option('check')) {
            $this->info('Check only — nothing was sent.');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        if (! $ok) {
            $this->error('Configuration is incomplete (see above); sending anyway so you can see the error.');
        }

        try {
            // Split by role so the notification a tap opens is a page that
            // profile can actually reach — a kid sent to /parent/approvals
            // would be bounced by the role middleware, which looks exactly
            // like the broken delivery this command exists to rule out.
            foreach ($subscribed->groupBy(fn (Profile $p) => $p->role->value) as $group) {
                $notification = $group->first()->isParent()
                    ? new ParentApprovalNeeded(
                        'Test notification',
                        'If you can read this, push notifications are working.',
                    )
                    : new ChoreReviewed(
                        'Test notification',
                        'If you can read this, your alerts are working.',
                    );

                $this->option('queue')
                    ? Notification::send($group, $notification)
                    : Notification::sendNow($group, $notification);
            }
        } catch (Throwable $e) {
            $this->error('The push failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        if ($this->option('queue')) {
            $this->info('Queued. It will only arrive once a worker picks it up.');

            return self::SUCCESS;
        }

        $this->info('Sent. It should arrive within a few seconds.');
        $this->line('  Nothing arrived? The push service accepted it but the browser did not show it —');
        $this->line('  check that notifications are allowed for the site at the OS level too.');

        return self::SUCCESS;
    }

    /** Prints the environment checks, returning false if any would stop delivery. */
    private function reportConfig(): bool
    {
        $queue = config('queue.default');

        $checks = [
            ['VAPID public key', (bool) config('webpush.vapid.public_key'), 'run `php artisan webpush:vapid`'],
            ['VAPID private key', (bool) config('webpush.vapid.private_key'), 'run `php artisan webpush:vapid`'],
            ['VAPID subject', (bool) config('webpush.vapid.subject'), 'set VAPID_SUBJECT to your site URL'],
        ];

        $ok = true;

        foreach ($checks as [$label, $passed, $fix]) {
            $this->line(sprintf('  %s %s%s', $passed ? '<info>OK  </info>' : '<error>MISS</error>', $label, $passed ? '' : "  — {$fix}"));
            $ok = $ok && $passed;
        }

        // Notifications are ShouldQueue, so on any driver but sync they sit in
        // the queue until a worker runs. A missing worker is invisible from the
        // app — the claim succeeds, the notification just never goes anywhere.
        $this->line(sprintf(
            '  %s Queue driver: %s%s',
            $queue === 'sync' ? '<info>OK  </info>' : '<comment>WARN</comment>',
            $queue,
            $queue === 'sync' ? ' (sent immediately)' : ' — notifications need a running worker to be delivered',
        ));

        return $ok;
    }
}
