{{--
    Glue view (2026-08-16) — exists ONLY to keep cpe-devices.show's render
    and layouts.app's render inside ONE top-level Blade render() call tree.

    Root cause this works around: Illuminate\View\Factory::renderCount hits
    0 (and flushStateIfDoneRendering() wipes @push/@stack state) the instant
    ANY top-level view()->render() call finishes — including a call whose
    *result* you only intended to embed into a second, later render. The
    controller originally called view('cpe-devices.show', ...)->render()
    as its own separate statement, then passed the resulting string into a
    second view('layouts.app', ...) call — by the time that second call's
    @stack('scripts') ran, cpe-devices.show's @push('scripts') content had
    already been flushed into oblivion by the FIRST call's own
    flushStateIfDoneRendering(). Confirmed via Playwright: the reveal
    button's onclick handler rendered fine (it's inline HTML, part of the
    string), but the <script> defining window.cpeRevealPppoePassword was
    silently absent from the final page.

    Nesting the render via @include here means cpe-devices.show renders
    WHILE this view's own top-level render is still in progress
    (renderCount already > 0), so its @push never gets flushed prematurely
    — @stack('scripts') inside the nested layouts.app include picks it up
    correctly. This is exactly the same "one unbroken top-level render"
    shape every other (Livewire, single-view) page in this app already
    uses without ever hitting this pitfall.
--}}
@include('layouts.app', [
    'title' => $title,
    'slot' => new \Illuminate\Support\HtmlString(view('cpe-devices.show', $pageData)->render()),
])
