<?php

namespace App\Livewire\Settings;

use App\Services\ThemeSettingsService;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ThemeSettings extends Component
{
    #[Validate('required|regex:/^#[0-9a-fA-F]{6}$/')]
    public string $primaryColor = ThemeSettingsService::DEFAULT_PRIMARY_COLOR;

    #[Validate('required|regex:/^#[0-9a-fA-F]{6}$/')]
    public string $textColor = ThemeSettingsService::DEFAULT_TEXT_COLOR;

    public function mount(ThemeSettingsService $service): void
    {
        $saved = $service->get(auth()->user());

        $this->primaryColor = $saved['primary_color'];
        $this->textColor = $saved['text_color'];
    }

    public function save(ThemeSettingsService $service): void
    {
        $this->validate();

        $service->update(auth()->user(), $this->primaryColor, $this->textColor);

        session()->flash('status', 'Tema berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.settings.theme-settings');
    }
}
