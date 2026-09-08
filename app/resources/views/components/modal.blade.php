{{--
    Standardised modal shell (v0.17.0 Langkah 1).

    Replaces the hand-repeated pattern
      <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="closeFoo">
          <div class="bg-white rounded-md p-N w-full max-w-X space-y-Y"> ... </div>
      </div>
    that lived in ~18 places, each missing the same two mobile fixes:
      1. `p-4` on the BACKDROP so the panel keeps a gutter on every side on a
         phone instead of going edge-to-edge.
      2. `max-h-[90vh] overflow-y-auto` on the panel so tall content (chart
         modals with metric/range tabs + custom-date inputs) scrolls instead
         of getting clipped by the viewport.

    The `@if ($showFoo)` conditional STAYS with the caller — this component
    only exists in the DOM while the modal is open, so `wire:click.self` on
    its root still means "the backdrop itself was clicked, not a child".

    Props:
      - max-width    Tailwind max-w-* utility for the panel (default max-w-lg).
      - panel-class  the panel's own visual classes, passed verbatim so each
                     modal keeps its exact look (rounding / shadow / padding /
                     space-y). Default matches the most common shape.
      - backdrop     backdrop tint utility (default bg-black/40).

    Any wire:* / x-* directive (notably wire:click.self / wire:key) is
    forwarded to the backdrop root via the attribute bag.

    Usage:
      @if ($showFoo)
          <x-modal wire:click.self="closeFoo" max-width="max-w-md" panel-class="rounded-md p-6 space-y-4">
              ... unchanged content ...
          </x-modal>
      @endif
--}}
@props([
    'maxWidth' => 'max-w-lg',
    'panelClass' => 'rounded-md p-6 space-y-4',
    'backdrop' => 'bg-black/40',
])

<div {{ $attributes->merge(['class' => 'fixed inset-0 '.$backdrop.' flex items-center justify-center p-4 z-50']) }}>
    <div class="bg-white w-full {{ $maxWidth }} max-h-[90vh] overflow-y-auto {{ $panelClass }}">
        {{ $slot }}
    </div>
</div>
