<?php

namespace App\Livewire\Installation;

use App\Models\ToolType;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * v0.13.4.1 — CRUD sederhana untuk master data ToolType (dipakai form klaim
 * WO via signed-link, App\Http\Controllers\WorkOrderClaimController). Tidak
 * ada delete keras — `work_order_tool_usages.tool_type_id` restrictOnDelete,
 * jadi alat yang genuinely sudah pernah dipakai tidak bisa dihapus; admin
 * cukup nonaktifkan (`is_active=false`) supaya tidak muncul lagi di
 * dropdown form klaim, tanpa merusak riwayat WO yang sudah tercatat.
 */
class ToolTypeIndex extends Component
{
    use AuthorizesRequests;

    public bool $showCreateForm = false;

    public string $name = '';

    public string $category = '';

    public ?int $editingToolTypeId = null;

    public string $editName = '';

    public string $editCategory = '';

    public function mount(): void
    {
        $this->authorize('tool_types.view');
    }

    public function createToolType(): void
    {
        $this->authorize('tool_types.manage');

        $this->name = trim($this->name);
        $tenantId = auth()->user()->tenant_id;

        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique(ToolType::class, 'name')->where('tenant_id', $tenantId)],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        ToolType::create([
            'tenant_id' => $tenantId,
            'name' => $this->name,
            'category' => $this->category !== '' ? $this->category : null,
            'is_active' => true,
        ]);

        $this->reset(['name', 'category', 'showCreateForm']);
    }

    public function edit(int $toolTypeId): void
    {
        $this->authorize('tool_types.manage');

        $toolType = ToolType::findOrFail($toolTypeId);
        $this->editingToolTypeId = $toolType->id;
        $this->editName = $toolType->name;
        $this->editCategory = (string) $toolType->category;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingToolTypeId', 'editName', 'editCategory']);
    }

    public function updateToolType(): void
    {
        $this->authorize('tool_types.manage');

        $toolType = ToolType::findOrFail($this->editingToolTypeId);
        $this->editName = trim($this->editName);
        $tenantId = auth()->user()->tenant_id;

        $this->validate([
            'editName' => ['required', 'string', 'max:255', Rule::unique(ToolType::class, 'name')->where('tenant_id', $tenantId)->ignore($toolType->id)],
            'editCategory' => ['nullable', 'string', 'max:255'],
        ]);

        $toolType->update([
            'name' => $this->editName,
            'category' => $this->editCategory !== '' ? $this->editCategory : null,
        ]);

        $this->cancelEdit();
    }

    public function toggleActive(int $toolTypeId): void
    {
        $this->authorize('tool_types.manage');

        $toolType = ToolType::findOrFail($toolTypeId);
        $toolType->update(['is_active' => ! $toolType->is_active]);
    }

    public function render()
    {
        return view('livewire.installation.tool-type-index', [
            'toolTypes' => ToolType::query()->orderBy('name')->get(),
            'canManage' => auth()->user()->can('tool_types.manage'),
        ]);
    }
}
