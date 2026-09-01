<?php

namespace App\Livewire\Network;

use App\Livewire\Concerns\StagesPhotoUploads;
use App\Models\FiberNode;
use App\Models\FiberNodePhoto;
use App\Models\Odp;
use App\Services\Network\FiberTopologyService;
use Livewire\Component;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 3. The
 * reusable "component Livewire terpisah" the sprint brief asks for —
 * embedded identically inside FiberNodeForm's edit mode AND
 * App\Livewire\Installation\OdpEdit (a genuinely new page — see that
 * component's own docblock for why no Odp edit page existed before this).
 *
 * Deliberately always operates against an ALREADY-PERSISTED owner
 * (ownerType/ownerId are required, non-null). A brand-new FiberNode has no
 * id yet to attach photos to — since Langkah 5 FiberNodeForm's own create
 * mode handles GPS + photos + splitter itself (in one transaction, see
 * FiberTopologyService::createNodeWithAttachments) rather than embedding
 * this component. This still sidesteps Livewire's real constraint that a
 * TemporaryUploadedFile can't be handed off between two component
 * instances across a network round trip.
 *
 * Langkah 6: the photo picker (here and in FiberNodeForm) is now two
 * buttons — camera vs gallery — via App\Livewire\Concerns\StagesPhotoUploads.
 */
class GpsPhotoCapture extends Component
{
    use StagesPhotoUploads;

    public string $ownerType;

    public int $ownerId;

    public string $latitude = '';

    public string $longitude = '';

    protected function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'newPhotos.*' => ['image', 'max:20480'],
        ];
    }

    public function mount(string $ownerType, int $ownerId): void
    {
        abort_unless(
            auth()->user()->can('network_infrastructure.view') || auth()->user()->can('network_infrastructure.manage'),
            403
        );

        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;

        $owner = $this->resolveOwner();
        $this->latitude = $owner->latitude !== null ? (string) $owner->latitude : '';
        $this->longitude = $owner->longitude !== null ? (string) $owner->longitude : '';
    }

    private function resolveOwner(): FiberNode|Odp
    {
        return $this->ownerType === FiberNode::class
            ? FiberNode::findOrFail($this->ownerId)
            : Odp::findOrFail($this->ownerId);
    }

    /**
     * "Ambil lokasi saya" only fills the input (via a dispatched browser
     * event the Blade view's Alpine listens for) — it never auto-saves,
     * so a technician can still drag/edit the pin manually before
     * confirming, per the sprint brief's own explicit requirement.
     */
    public function saveLocation(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $this->validate();

        $service->updateCoordinates(
            $this->resolveOwner(),
            $this->latitude !== '' ? (float) $this->latitude : null,
            $this->longitude !== '' ? (float) $this->longitude : null,
        );

        session()->flash('gps-photo-status', 'Lokasi berhasil disimpan.');
    }

    /**
     * Files arrive here already uploaded to Livewire's own temp storage
     * (that part is unavoidable — it's how wire:model="newPhotos" always
     * works) but are NOT yet turned into real FiberNodePhoto rows —
     * the Blade view shows a thumbnail preview via each file's own
     * ->temporaryUrl() first, and only this explicit action persists them,
     * satisfying "preview thumbnail sebelum submit".
     */
    public function uploadPhotos(FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $this->validate();

        $owner = $this->resolveOwner();

        foreach ($this->newPhotos as $file) {
            $service->addPhoto($owner, $file);
        }

        $this->newPhotos = [];
        session()->flash('gps-photo-status', 'Foto berhasil diunggah.');
    }

    public function deletePhoto(int $photoId, FiberTopologyService $service): void
    {
        abort_unless(auth()->user()->can('network_infrastructure.manage'), 403);

        $photo = FiberNodePhoto::where('owner_type', $this->ownerType)
            ->where('owner_id', $this->ownerId)
            ->findOrFail($photoId);

        $service->deletePhoto($photo);
    }

    public function render(FiberTopologyService $service)
    {
        $photos = FiberNodePhoto::where('owner_type', $this->ownerType)
            ->where('owner_id', $this->ownerId)
            ->latest()
            ->get();

        return view('livewire.network.gps-photo-capture', [
            'photos' => $photos,
            'mapPoints' => $service->mapReferencePoints($this->resolveOwner()),
        ]);
    }
}
