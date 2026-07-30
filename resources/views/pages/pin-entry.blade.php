<?php

use App\Models\Profile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Component;

new class extends Component
{
    public Profile $profile;

    public string $pin = '';

    public bool $error = false;

    public ?string $lockedMessage = null;

    public function mount(Profile $profile): void
    {
        $authed = Auth::guard('profile')->user();
        if ($authed) {
            $this->redirect($authed->isParent() ? '/parent' : '/kid', navigate: true);

            return;
        }

        $this->profile = $profile;
        $this->syncLockState();
    }

    public function press(string $digit): void
    {
        if ($this->lockedMessage || strlen($this->pin) >= 4) {
            return;
        }

        $this->error = false;
        $this->pin .= $digit;

        if (strlen($this->pin) === 4) {
            $this->verify();
        }
    }

    public function backspace(): void
    {
        $this->pin = substr($this->pin, 0, -1);
        $this->error = false;
    }

    public function verify(): void
    {
        $throttleKey = 'pin-attempt:'.$this->profile->id.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $this->pin = '';
            $this->error = true;

            return;
        }

        RateLimiter::hit($throttleKey, 60);

        if ($this->syncLockState()) {
            $this->pin = '';

            return;
        }

        if ($this->profile->checkPin($this->pin)) {
            $this->profile->resetPinAttempts();
            $this->profile->save();
            RateLimiter::clear($throttleKey);

            Auth::guard('profile')->login($this->profile);
            session()->regenerate();

            $this->redirect($this->profile->isParent() ? '/parent' : '/kid', navigate: true);

            return;
        }

        $this->profile->recordFailedPinAttempt();
        $this->profile->save();

        $this->pin = '';
        $this->error = true;
        $this->syncLockState();
    }

    /**
     * Refresh the lockout message from the profile's current state.
     * Returns true if the profile is (now) locked.
     */
    private function syncLockState(): bool
    {
        if ($this->profile->isLocked()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($this->profile->locked_until, true) / 60));
            $this->lockedMessage = "Too many tries — try again in {$minutes} minute".($minutes === 1 ? '' : 's').'.';

            return true;
        }

        $this->lockedMessage = null;

        return false;
    }
}; ?>

<div class="mx-auto flex max-w-[400px] flex-col items-center gap-6 px-5 pt-16 pb-10">
    <div
        class="flex h-[76px] w-[76px] items-center justify-center rounded-[24px] font-baloo text-[34px] font-extrabold text-fq-bg"
        style="background:{{ $profile->color->cssVar() }}"
    >
        {{ mb_substr($profile->name, 0, 1) }}
    </div>

    <div class="flex flex-col items-center gap-1 text-center">
        <h1 class="font-baloo text-[26px] font-bold">Hey {{ $profile->name }}</h1>
        <p class="text-sm text-fq-text-3">Enter your 4-digit code</p>
    </div>

    <div class="flex gap-2" wire:key="pin-dots">
        @for ($i = 0; $i < 4; $i++)
            <div
                class="flex h-[52px] w-11 items-center justify-center rounded-[14px] border-2 bg-fq-panel"
                style="border-color: {{ $i === strlen($pin) ? 'var(--fq-cyan)' : 'var(--fq-line)' }}"
            >
                @if ($i < strlen($pin))
                    <span class="font-baloo text-2xl text-fq-cyan">&bull;</span>
                @endif
            </div>
        @endfor
    </div>

    @if ($lockedMessage)
        <p class="text-[13px] font-semibold text-fq-danger">{{ $lockedMessage }}</p>
    @elseif ($error)
        <p class="text-[13px] font-semibold text-fq-danger">Nope, try again</p>
    @endif

    <div class="grid w-full max-w-[280px] grid-cols-3 gap-[10px]" wire:key="pin-keypad">
        @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $key)
            <button
                type="button"
                wire:click="press('{{ $key }}')"
                @disabled($lockedMessage)
                class="rounded-[16px] border border-fq-line-2 bg-fq-sunk py-4 font-baloo text-[22px] font-bold transition hover:bg-[#26305a] disabled:opacity-40"
            >{{ $key }}</button>
        @endforeach

        <span></span>

        <button
            type="button"
            wire:click="press('0')"
            @disabled($lockedMessage)
            class="rounded-[16px] border border-fq-line-2 bg-fq-sunk py-4 font-baloo text-[22px] font-bold transition hover:bg-[#26305a] disabled:opacity-40"
        >0</button>

        <button
            type="button"
            wire:click="backspace"
            @disabled($lockedMessage)
            class="rounded-[16px] border border-fq-line-2 bg-fq-sunk py-4 font-baloo text-[22px] font-bold transition hover:bg-[#26305a] disabled:opacity-40"
        >&larr;</button>
    </div>

    <a href="{{ route('login') }}" wire:navigate class="text-sm text-fq-text-3 hover:text-fq-text">&larr; back</a>
</div>
