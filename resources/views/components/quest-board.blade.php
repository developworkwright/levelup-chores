@props(['class' => ''])

<div
    {{ $attributes->merge(['class' => 'mt-2 rounded-[16px] p-3 '.$class]) }}
    style="background: var(--fq-lime); box-shadow: 0 10px 22px -14px #000"
>
    <div class="flex flex-wrap gap-2">
        {{ $slot }}
    </div>
</div>
