<select
    onchange="window.location.href = this.value"
    class="text-sm rounded-md border-gray-300 shadow-sm"
    aria-label="{{ __('Pilih bahasa') }}"
>
    <option value="{{ route('lang.switch', 'id') }}" @selected(app()->getLocale() === 'id')>
        Bahasa Indonesia
    </option>
    <option value="{{ route('lang.switch', 'en') }}" @selected(app()->getLocale() === 'en')>
        English
    </option>
</select>
