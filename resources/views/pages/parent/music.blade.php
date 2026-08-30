<?php

use App\Models\Profile;
use App\Services\MusicService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * The music library — where the songs the kid header plays are added and named.
 *
 * A screen rather than a folder a grown-up SSHes into, because in production
 * the songs live in the attached bucket and adding one should not be a deploy.
 * The filename is the title a kid reads, so naming is the substance of this
 * page: everything else is upload, rename, delete.
 *
 * @see MusicService for why the library is a folder rather than a table, and
 *      why stored filenames carry underscores instead of spaces.
 */
new class extends Component
{
    use WithFileUploads;

    public Profile $profile;

    /** The song being added. Held only until addSong() runs. */
    public $upload = null;

    /** What to call it. Blank falls back to the uploaded file's own name. */
    public string $newTitle = '';

    public ?string $flashMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->profile = Auth::guard('profile')->user();

        abort_unless($this->profile->isParent(), 403);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // mimetypes as well as the extension: the extension is what the
            // library keys off, and a renamed .wav would be silently unplayable
            // on exactly the browsers nobody in the house is testing on.
            'upload' => ['required', 'file', 'mimetypes:audio/mpeg', 'extensions:mp3', 'max:'.MusicService::MAX_UPLOAD_KB],
            'newTitle' => ['nullable', 'string', 'max:'.MusicService::MAX_TITLE],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'upload.required' => 'Pick an mp3 first.',
            'upload.mimetypes' => 'That has to be an mp3.',
            'upload.extensions' => 'That has to be an mp3.',
            'upload.max' => 'That song is over '.round(MusicService::MAX_UPLOAD_KB / 1024).'MB.',
        ];
    }

    public function addSong(): void
    {
        $this->validate();

        $service = app(MusicService::class);

        try {
            $stored = $service->store($this->upload, $this->newTitle);
        } catch (\Throwable $e) {
            // The music disk is configured to throw, which is the point: a
            // bucket that rejected the write must not leave this page claiming
            // the song was added. Parents get the short version.
            report($e);

            $this->errorMessage = 'That did not save — the music storage turned it down.';
            $this->flashMessage = null;

            return;
        }

        $this->reset('upload', 'newTitle', 'errorMessage');
        $this->flashMessage = $service->title($stored).' is on the list.';
    }

    public function renameSong(string $path, string $title): void
    {
        app(MusicService::class)->rename($path, trim($title));

        $this->flashMessage = null;
    }

    /**
     * The one destructive control, and it asks first in the markup rather than
     * here: a kid loses nothing but a remembered choice, which falls back to
     * the first song on the list, but the parent loses the upload.
     */
    public function removeSong(string $path): void
    {
        $service = app(MusicService::class);

        $this->flashMessage = $service->title($path).' is gone.';
        $service->delete($path);
    }

    public function with(): array
    {
        $tracks = app(MusicService::class)->tracks();

        return [
            'tracks' => $tracks,
            'totalBytes' => array_sum(array_column($tracks, 'bytes')),
            'maxTitle' => MusicService::MAX_TITLE,
            'maxMb' => (int) round(MusicService::MAX_UPLOAD_KB / 1024),
            // Named on the page so a bucket that is not wired up is visible
            // here, rather than as silence on a kid's phone.
            'diskName' => config('filesystems.music_disk'),
        ];
    }
}; ?>

<x-parent.shell :profile="$profile" active="music">
    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-3 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px]">
            <div>
                <h2 class="font-baloo text-xl font-extrabold">Add a song</h2>
                <p class="mt-[3px] text-xs text-fq-text-3">
                    mp3 only, up to {{ $maxMb }}MB. What you call it here is what the kids
                    see in the header menu.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <input
                    type="file"
                    wire:model="upload"
                    accept="audio/mpeg,.mp3"
                    aria-label="Song file"
                    class="min-w-[200px] flex-1 rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[9px] text-[13px] text-fq-text-2-b file:mr-3 file:rounded-[8px] file:border-0 file:bg-fq-panel-alt file:px-3 file:py-[5px] file:text-[12px] file:text-fq-text-2-b focus:border-fq-line-4 focus:outline-none"
                />

                <input
                    wire:model="newTitle"
                    type="text"
                    maxlength="{{ $maxTitle }}"
                    placeholder="Call it something"
                    class="w-[190px] rounded-[12px] border border-fq-line-2 bg-fq-sunk px-[11px] py-[9px] text-[13px] text-fq-text placeholder:text-fq-text-5 focus:border-fq-line-4 focus:outline-none"
                />

                <button
                    type="button"
                    wire:click="addSong"
                    wire:loading.attr="disabled"
                    wire:target="upload,addSong"
                    class="ml-auto shrink-0 rounded-[11px] px-4 py-[9px] font-baloo text-[13px] font-extrabold transition hover:brightness-110 disabled:opacity-60"
                    style="background: var(--fq-fill-gold-soft); color: var(--fq-ink)"
                >
                    <span wire:loading.remove wire:target="upload,addSong">Add song</span>
                    <span wire:loading wire:target="upload,addSong">Uploading&hellip;</span>
                </button>
            </div>

            @error('upload')
                <p class="text-[13px]" style="color: var(--fq-danger)">{{ $message }}</p>
            @enderror

            @if ($errorMessage)
                <p class="text-[13px]" style="color: var(--fq-danger)">{{ $errorMessage }}</p>
            @endif

            @if ($flashMessage)
                <p class="text-[13px]" style="color: var(--fq-lime)">{{ $flashMessage }}</p>
            @endif
        </div>

        <div class="flex flex-col gap-3 rounded-[28px] border border-fq-line bg-fq-bg p-[16px_14px]">
            <div class="flex flex-wrap items-baseline gap-2">
                <h2 class="font-baloo text-xl font-extrabold">The playlist</h2>
                <span class="font-mono-fq text-[10px] tracking-[0.14em] text-fq-text-4 uppercase">
                    {{ count($tracks) }} {{ Str::plural('SONG', count($tracks)) }}
                    @if ($tracks)
                        &middot; {{ number_format($totalBytes / 1048576, 1) }} MB
                    @endif
                    &middot; {{ $diskName }}
                </span>
            </div>

            @if (! $tracks)
                <p class="text-[13px] text-fq-text-5">
                    Nothing here yet, so the music button stays off the kid header entirely.
                </p>
            @else
                <div class="flex flex-col gap-2">
                    @foreach ($tracks as $track)
                        <div
                            wire:key="track-{{ $track['id'] }}"
                            class="flex flex-wrap items-center gap-2 rounded-[14px] border border-fq-line-2 bg-fq-panel px-3 py-[10px]"
                        >
                            <input
                                type="text"
                                value="{{ $track['title'] }}"
                                maxlength="{{ $maxTitle }}"
                                wire:change="renameSong(@js($track['path']), $event.target.value)"
                                aria-label="Song title"
                                class="min-w-[160px] flex-1 rounded-[10px] border border-transparent bg-transparent px-[6px] py-[4px] font-baloo text-[15px] font-bold text-fq-text hover:border-fq-line-2 focus:border-fq-line-4 focus:bg-fq-sunk focus:outline-none"
                            />

                            {{-- A parent should be able to hear what they just
                                 named without signing in as a kid and turning
                                 the header music on. --}}
                            <audio controls preload="none" src="{{ $track['url'] }}" class="h-[34px] max-w-[260px]"></audio>

                            <span class="font-mono-fq text-[10px] whitespace-nowrap text-fq-text-5">
                                {{ number_format($track['bytes'] / 1048576, 1) }} MB
                            </span>

                            <button
                                type="button"
                                wire:click="removeSong(@js($track['path']))"
                                wire:confirm="Delete {{ $track['title'] }}?"
                                title="Delete {{ $track['title'] }}"
                                aria-label="Delete {{ $track['title'] }}"
                                class="shrink-0 rounded-[10px] border border-fq-line-2 px-[10px] py-[6px] text-[12px] text-fq-text-4 transition hover:text-fq-text"
                            >Delete</button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-parent.shell>
