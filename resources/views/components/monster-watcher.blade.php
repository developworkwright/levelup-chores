{{-- The long game, watching from the corner of a page it isn't on.

     Being everywhere is a large part of being significant, and the level 3
     monster is the only one that has earned it — a kid should catch it out of
     the corner of their eye while clearing the board it is waiting behind.

     Deliberately barely there: faint, desaturated, untouchable, and gone
     entirely for anyone who has asked the OS for less movement. It is a mood,
     not a element of the page, and the moment it competes with the board for
     attention it has stopped doing its job. Hidden below a large screen too,
     where the room simply isn't there. --}}
@props(['state'])

<div
    wire:key="watcher-{{ $state['skin']->value }}-{{ $state['stage']->value }}"
    wire:ignore
    x-data="fqMonster(@js($state['skin']->value), @js($state['stage']->value), @js($state['tier']->dread()))"
    x-html="svg"
    class="fq-boss fq-watcher hidden lg:block"
    aria-hidden="true"
></div>
