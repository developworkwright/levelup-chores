{{-- One cosmetic, drawn. `art_path ?? recipe`: an upload is a picture, anything
     else is generated in the browser by cosmetics.js — see <fq-cosmetic> in
     resources/js/cosmetic-elements.js.

     `mode="fill"` draws the art edge to edge, for a face on a tile; the default
     is the padded shop tile. `still` stops it moving, which is the rule in any
     list: a feed of twelve blinking avatars is a worse page than a still one.
     `stage` draws a pet at that age — see App\Enums\PetStage. --}}
@props([
    'item',
    'mode' => 'tile',
    'still' => false,
    'label' => null,
    'pose' => null,
    'stage' => null,
])

<fq-cosmetic
    {{ $attributes->class([$item->effect?->cssClass()]) }}
    kind="{{ $item->slot->value }}"
    @if ($item->isUpload())
        src="{{ $item->artUrl($stage) }}"
        @if ($item->motion) motion="{{ $item->motion->value }}" @endif
    @else
        recipe="{{ $item->recipe }}"
    @endif
    mode="{{ $mode }}"
    @if ($still) still @endif
    @if ($label) label="{{ $label }}" @endif
    @if ($pose) pose="{{ $pose }}" @endif
    aria-hidden="true"
></fq-cosmetic>
