{{--
    v0.16.0 Core Network Infrastructure Management, Langkah 3. Draft
    offline: every field change is written to localStorage (key unique
    per form — fiber_node_draft_new for create, fiber_node_draft_{id} for
    edit) via a debounced window-level input listener rather than per-field
    Alpine watchers (simpler than wiring 9 separate x-model duplicates of
    every wire:model field). On mount, an existing draft newer than the
    form's own initial load is offered, never auto-applied. Cleared on a
    real successful save (the fiber-node-saved event GpsPhotoCapture's own
    save/upload actions do NOT dispatch — only FiberNodeForm::save() does,
    since the draft only ever covers FiberNodeForm's own fields).
--}}
<div
    class="p-6 max-w-3xl mx-auto"
    x-data="{
        draftKey: {{ $fiberNodeId ? \Illuminate\Support\Js::from('fiber_node_draft_'.$fiberNodeId) : \Illuminate\Support\Js::from('fiber_node_draft_new') }},
        draftAvailable: false,
        draftFields: {},
        init() {
            const raw = localStorage.getItem(this.draftKey);
            if (raw) {
                try {
                    this.draftFields = JSON.parse(raw);
                    this.draftAvailable = true;
                } catch (e) { localStorage.removeItem(this.draftKey); }
            }
            window.addEventListener('fiber-node-saved', () => localStorage.removeItem(this.draftKey));
        },
        saveDraft() {
            const data = {};
            this.$root.querySelectorAll('[wire\\:model]').forEach((el) => {
                data[el.getAttribute('wire:model')] = el.value;
            });
            localStorage.setItem(this.draftKey, JSON.stringify(data));
        },
        applyDraft() {
            Object.entries(this.draftFields).forEach(([field, value]) => $wire.set(field, value));
            this.draftAvailable = false;
        },
        dismissDraft() {
            localStorage.removeItem(this.draftKey);
            this.draftAvailable = false;
        },
    }"
>
    <h1 class="text-2xl font-semibold text-gray-800 mb-6">
        {{ $fiberNodeId ? __('Edit Titik Topologi Fiber') : __('Titik Topologi Fiber Baru') }}
    </h1>

    @if (session('status'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-800 text-sm rounded-md">
            {{ session('status') }}
        </div>
    @endif

    <div x-show="draftAvailable" class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded-md flex items-center justify-between">
        <span class="text-sm text-yellow-800">{{ __('Draft tersimpan ditemukan.') }}</span>
        <div class="space-x-2">
            <button type="button" x-on:click="applyDraft()" class="px-3 py-1 text-sm bg-primary text-white rounded-md hover:opacity-90">
                {{ __('Lanjutkan draft tersimpan?') }}
            </button>
            <button type="button" x-on:click="dismissDraft()" class="px-3 py-1 text-sm border border-gray-300 rounded-md hover:bg-gray-50">
                {{ __('Abaikan') }}
            </button>
        </div>
    </div>

    <form wire:submit="save" x-on:input.debounce.500ms="saveDraft()" class="space-y-4 p-4 border border-gray-200 rounded-md bg-gray-50">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Tipe Titik') }}</label>
                <select wire:model="nodeType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="otb">{{ __('OTB') }}</option>
                    <option value="closure">{{ __('Closure') }}</option>
                    <option value="odc">{{ __('ODC') }}</option>
                </select>
                @error('nodeType') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Label') }}</label>
                <input type="text" wire:model="localLabel" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('localLabel') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Titik Induk (Parent)') }}</label>
            <select wire:model="parentId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('-- Tidak ada --') }}</option>
                @foreach ($parentOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
            @error('parentId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        @if ($fiberNodeId === null)
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Latitude') }}</label>
                    <input type="text" wire:model="latitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('latitude') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Longitude') }}</label>
                    <input type="text" wire:model="longitude" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('longitude') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
            <p class="text-xs text-gray-500">
                {{ __('GPS & foto tersedia setelah titik ini disimpan.') }}
            </p>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Redaman Masuk (loss in, dB)') }}</label>
                <input type="text" wire:model="lossInDb" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('lossInDb') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Redaman Keluar (loss out, dB)') }}</label>
                <input type="text" wire:model="lossOutDb" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('lossOutDb') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">{{ __('Catatan') }}</label>
            <textarea wire:model="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
            @error('notes') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90">
                {{ __('Simpan') }}
            </button>
            <a href="{{ route('web.fiber-nodes.index') }}" class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50">
                {{ __('Kembali') }}
            </a>
        </div>
    </form>

    @if ($fiberNodeId !== null)
        <div class="mt-6">
            @livewire('network.gps-photo-capture', ['ownerType' => \App\Models\FiberNode::class, 'ownerId' => $fiberNodeId], key('gps-'.$fiberNodeId))
        </div>
    @endif
</div>
