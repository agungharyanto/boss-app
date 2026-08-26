<?php

namespace App\Livewire\Referrers;

use App\Enums\ReferrerType;
use App\Models\Referrer;
use App\Models\User;
use App\Services\ReferrerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class ReferrerIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public bool $showCreateForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:30')]
    public string $phone = '';

    #[Validate('required|string')]
    public string $type = '';

    public bool $isActive = true;

    public bool $createLoginAccount = false;

    /**
     * Shown exactly once right after a login account is created (either at
     * create-time or via the separate "Buat Akun Login" action on an
     * existing Referrer) — never re-derivable/re-shown once dismissed or
     * once the page reloads, since ReferrerService never persists it
     * anywhere beyond this in-memory property.
     */
    public ?string $generatedPassword = null;

    public ?string $generatedPasswordForName = null;

    public ?int $editingReferrerId = null;

    #[Validate('required|string|max:255')]
    public string $editName = '';

    #[Validate('required|string|max:30')]
    public string $editPhone = '';

    #[Validate('required|string')]
    public string $editType = '';

    public bool $editIsActive = true;

    public ?int $linkingReferrerId = null;

    public ?int $selectedUserId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Referrer::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function createReferrer(ReferrerService $service): void
    {
        $this->authorize('manage', Referrer::class);

        $this->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:30', Rule::unique(Referrer::class, 'phone')->where('tenant_id', auth()->user()->tenant_id)],
            'type' => 'required|string|in:'.implode(',', array_column(ReferrerType::cases(), 'value')),
        ]);

        $result = $service->create([
            'name' => $this->name,
            'phone' => $this->phone,
            'type' => $this->type,
            'is_active' => $this->isActive,
        ], $this->createLoginAccount);

        if ($result['generated_password'] !== null) {
            $this->generatedPassword = $result['generated_password'];
            $this->generatedPasswordForName = $result['referrer']->name;
        }

        $this->reset(['name', 'phone', 'type', 'isActive', 'createLoginAccount', 'showCreateForm']);
        $this->isActive = true;
    }

    public function edit(int $referrerId): void
    {
        $referrer = Referrer::findOrFail($referrerId);
        $this->authorize('manage', Referrer::class);

        $this->editingReferrerId = $referrer->id;
        $this->editName = $referrer->name;
        $this->editPhone = $referrer->phone;
        $this->editType = $referrer->type->value;
        $this->editIsActive = $referrer->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingReferrerId', 'editName', 'editPhone', 'editType', 'editIsActive']);
    }

    public function updateReferrer(ReferrerService $service): void
    {
        $referrer = Referrer::findOrFail($this->editingReferrerId);
        $this->authorize('manage', Referrer::class);

        $this->validate([
            'editName' => 'required|string|max:255',
            'editPhone' => ['required', 'string', 'max:30', Rule::unique(Referrer::class, 'phone')->where('tenant_id', auth()->user()->tenant_id)->ignore($referrer->id)],
            'editType' => 'required|string|in:'.implode(',', array_column(ReferrerType::cases(), 'value')),
        ]);

        $service->update($referrer, [
            'name' => $this->editName,
            'phone' => $this->editPhone,
            'type' => $this->editType,
            'is_active' => $this->editIsActive,
        ]);

        $this->cancelEdit();
    }

    public function deactivateReferrer(int $referrerId, ReferrerService $service): void
    {
        $this->authorize('manage', Referrer::class);

        $service->deactivate(Referrer::findOrFail($referrerId));
    }

    public function generateLoginAccount(int $referrerId, ReferrerService $service): void
    {
        $this->authorize('manage', Referrer::class);

        $referrer = Referrer::findOrFail($referrerId);

        try {
            $result = $service->generateLoginAccount($referrer);
        } catch (InvalidArgumentException $e) {
            $this->addError('generateLoginAccount', $e->getMessage());

            return;
        }

        $this->generatedPassword = $result['generated_password'];
        $this->generatedPasswordForName = $result['referrer']->name;
    }

    public function dismissGeneratedPassword(): void
    {
        $this->reset(['generatedPassword', 'generatedPasswordForName']);
    }

    public function openLinkUser(int $referrerId): void
    {
        $this->authorize('manage', Referrer::class);

        $this->linkingReferrerId = $referrerId;
        $this->selectedUserId = null;
    }

    public function cancelLinkUser(): void
    {
        $this->reset(['linkingReferrerId', 'selectedUserId']);
    }

    public function confirmLinkUser(ReferrerService $service): void
    {
        $this->authorize('manage', Referrer::class);

        $this->validate(['selectedUserId' => 'required|integer']);

        $referrer = Referrer::findOrFail($this->linkingReferrerId);
        $user = User::where('tenant_id', auth()->user()->tenant_id)->findOrFail($this->selectedUserId);

        try {
            $service->linkExistingUser($referrer, $user);
        } catch (InvalidArgumentException $e) {
            $this->addError('selectedUserId', $e->getMessage());

            return;
        }

        $this->cancelLinkUser();
    }

    public function render()
    {
        $referrers = Referrer::query()
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->orderBy('name')
            ->paginate(15);

        // Users not yet linked to any Referrer, for the "link existing
        // user" dropdown — same tenant only.
        $linkedUserIds = Referrer::query()->whereNotNull('user_id')->pluck('user_id');
        $availableUsers = User::where('tenant_id', auth()->user()->tenant_id)
            ->whereNotIn('id', $linkedUserIds)
            ->orderBy('name')
            ->get();

        return view('livewire.referrers.referrer-index', [
            'referrers' => $referrers,
            'availableUsers' => $availableUsers,
            'referrerTypes' => ReferrerType::cases(),
            'canManage' => auth()->user()->can('manage', Referrer::class),
        ]);
    }
}
