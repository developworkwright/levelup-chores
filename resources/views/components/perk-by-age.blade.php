@props(['knack', 'stage' => null, 'ink' => '#7dffb0'])

{{--
    A pet's perk at both ages it can do it, so a kid knows what's coming
    before the pet has grown into it. The row for the age the pet is now is
    lit; a baby (or a pet for sale, with no $stage) lights neither.
--}}
<span {{ $attributes->merge(['class' => 'flex flex-col gap-[6px]']) }} data-perk-by-age>
    @foreach ([App\Enums\PetStage::Young, App\Enums\PetStage::Adult] as $age)
        @php $isNow = $stage === $age; @endphp
        <span class="flex flex-col gap-[1px] {{ $stage && ! $isNow ? 'opacity-60' : '' }}">
            <span class="font-mono-fq text-[7.5px] tracking-[0.12em] uppercase" style="color: {{ $isNow ? $ink : '#8c7bab' }}">
                {{ $age === App\Enums\PetStage::Adult ? 'Grown up' : 'Young' }} · {{ $knack->howOften($age) }}@if ($isNow) · now @endif
            </span>
            <span class="text-[11.5px] text-pretty" style="color: {{ $isNow ? '#f7f0ff' : '#c8bade' }}">{{ $knack->describe($age) }}</span>
        </span>
    @endforeach
</span>
