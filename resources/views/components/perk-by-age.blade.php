@props(['knack', 'stage' => null, 'ink' => '#7dffb0'])

{{--
    A pet's perk at every age, so a kid knows what's coming before the pet
    has grown into it. The row for the age the pet is now is lit; a pet for
    sale, with no $stage, lights none.
--}}
<span {{ $attributes->merge(['class' => 'flex flex-col gap-[6px]']) }} data-perk-by-age>
    @foreach (App\Enums\PetStage::cases() as $age)
        @php $isNow = $stage === $age; @endphp
        <span class="flex flex-col gap-[1px] {{ $stage && ! $isNow ? 'opacity-60' : '' }}" data-perk-age="{{ $age->value }}">
            <span class="font-mono-fq text-[7.5px] tracking-[0.12em] uppercase" style="color: {{ $isNow ? $ink : '#8c7bab' }}">
                {{ $age->label() }} · {{ $knack->howOften($age) }}@if ($isNow) · now @endif
            </span>
            <span class="text-[11.5px] text-pretty" style="color: {{ $isNow ? '#f7f0ff' : '#c8bade' }}">{{ $knack->describe($age) }}</span>
        </span>
    @endforeach
</span>
