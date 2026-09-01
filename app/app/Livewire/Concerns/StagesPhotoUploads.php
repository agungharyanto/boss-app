<?php

namespace App\Livewire\Concerns;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * v0.16.0 Langkah 6 — two separate photo sources (a "Ambil foto" camera
 * button and a "Pilih dari galeri" button) that both feed the SAME
 * previewed-but-not-yet-persisted `$newPhotos` array.
 *
 * A single `wire:model` file input can't do this: each `change` event
 * OVERWRITES the bound property, so picking from the gallery after taking
 * a camera photo would drop the camera photo. So each source binds to its
 * own staging property (`cameraPhotos` / `galleryPhotos`), and the
 * matching `updated*` hook MERGES it into `$newPhotos` then clears the
 * staging prop — the temp files themselves survive (Livewire keeps them
 * on the temp disk, now referenced by `$newPhotos`).
 *
 * Shared verbatim by FiberNodeForm (create mode) and GpsPhotoCapture
 * (edit, used by OdpEdit). Same "one concern, one trait" pattern as
 * App\Livewire\Concerns\ValidatesCustomHistoryRange (v0.8.3).
 */
trait StagesPhotoUploads
{
    use WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $cameraPhotos = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $galleryPhotos = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $newPhotos = [];

    public function updatedCameraPhotos(): void
    {
        $this->mergeStagedPhotos('cameraPhotos');
    }

    public function updatedGalleryPhotos(): void
    {
        $this->mergeStagedPhotos('galleryPhotos');
    }

    private function mergeStagedPhotos(string $source): void
    {
        $this->validateOnly("{$source}.*", ["{$source}.*" => ['image', 'max:20480']]);

        $this->newPhotos = array_merge($this->newPhotos, $this->{$source});
        $this->{$source} = [];
    }

    public function removeNewPhoto(int $index): void
    {
        unset($this->newPhotos[$index]);
        $this->newPhotos = array_values($this->newPhotos);
    }
}
