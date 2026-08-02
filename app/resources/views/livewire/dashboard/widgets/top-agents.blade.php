<div class="p-4 bg-white border border-gray-200 rounded-md">
    <p class="text-sm text-gray-500 mb-3">{{ __('Agent Referral Teratas') }}</p>

    @forelse ($agents as $agent)
        <div class="py-1.5 text-sm border-t border-gray-100 first:border-t-0 flex justify-between">
            <span style="color: var(--color-text)">{{ $agent->name }} ({{ $agent->type->label() }})</span>
            <span class="font-medium" style="color: var(--color-text)">{{ $agent->referrals_count }}</span>
        </div>
    @empty
        <p class="text-sm text-gray-400">—</p>
    @endforelse
</div>
