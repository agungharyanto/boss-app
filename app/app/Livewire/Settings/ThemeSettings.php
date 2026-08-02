<?php

namespace App\Livewire\Settings;

use Livewire\Attributes\Validate;
use Livewire\Component;

class ThemeSettings extends Component
{
    #[Validate('required|regex:/^#[0-9a-fA-F]{6}$/')]
    public string $primaryColor = '#2563eb';

    #[Validate('required|regex:/^#[0-9a-fA-F]{6}$/')]
    public string $textColor = '#1f2937';

    public function mount(): void
    {
        $preference = auth()->user()->preference;

        $this->primaryColor = $preference?->theme_primary_color ?? $this->primaryColor;
        $this->textColor = $preference?->theme_text_color ?? $this->textColor;
    }

    public function save(): void
    {
        $this->validate();

        auth()->user()->preference()->updateOrCreate([], [
            'theme_primary_color' => $this->primaryColor,
            'theme_text_color' => $this->textColor,
        ]);

        session()->flash('status', 'Tema berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.settings.theme-settings');
    }
}
