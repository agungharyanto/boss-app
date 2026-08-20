<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\CreateCustomerContactAction;
use App\Actions\Customers\UpdateCustomerAction;
use App\Actions\Customers\UpdateCustomerContactAction;
use App\Actions\Customers\UpdateCustomerStatusAction;
use App\Enums\ContactAccessLevel;
use App\Enums\CustomerStatus;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Services\Network\CpeBindingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

class CustomerShow extends Component
{
    use AuthorizesRequests;

    public Customer $customer;

    public bool $editingProfile = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string')]
    public string $address = '';

    #[Validate('required|string|max:20')]
    public string $phone_number = '';

    public string $selectedStatus = '';

    public bool $showContactForm = false;

    public ?int $editingContactId = null;

    #[Validate('required|string|max:255')]
    public string $contactName = '';

    #[Validate('required|string|max:20')]
    public string $contactPhone = '';

    #[Validate('nullable|string|max:255')]
    public string $contactRelationship = '';

    #[Validate('required|string')]
    public string $contactAccessLevel = '';

    public bool $contactCanViewBilling = false;

    public bool $contactCanRequestServiceChange = false;

    public bool $contactCanReceiveNotifications = true;

    public bool $contactIsAuthorized = false;

    public bool $showAddDeviceForm = false;

    #[Validate('required|string|max:255')]
    public string $newDeviceSerial = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);

        $this->customer = $customer;
        $this->syncProfileFields();
    }

    private function syncProfileFields(): void
    {
        $this->name = $this->customer->name;
        $this->address = $this->customer->address;
        $this->phone_number = $this->customer->phone_number;
    }

    public function startEditingProfile(): void
    {
        $this->authorize('update', $this->customer);
        $this->editingProfile = true;
    }

    public function updateProfile(UpdateCustomerAction $action): void
    {
        $this->authorize('update', $this->customer);

        $data = $this->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'phone_number' => 'required|string|max:20',
        ]);

        $this->customer = $action->handle($this->customer, $data);
        $this->editingProfile = false;
    }

    public function updateStatus(UpdateCustomerStatusAction $action): void
    {
        $this->authorize('update', $this->customer);

        $this->validate(['selectedStatus' => 'required|string']);

        $target = CustomerStatus::from($this->selectedStatus);

        if (! $this->customer->status->canTransitionTo($target)) {
            $this->addError('selectedStatus', "Tidak bisa mengubah status dari {$this->customer->status->label()} ke {$target->label()}.");

            return;
        }

        $this->customer = $action->handle($this->customer, $target);
        $this->selectedStatus = '';
    }

    public function openContactForm(?int $contactId = null): void
    {
        $this->authorize('create', CustomerContact::class);

        $this->resetContactForm();
        $this->showContactForm = true;
        $this->editingContactId = $contactId;

        if ($contactId) {
            $contact = $this->customer->contacts()->findOrFail($contactId);
            $this->contactName = $contact->name;
            $this->contactPhone = $contact->phone_number;
            $this->contactRelationship = (string) $contact->relationship;
            $this->contactAccessLevel = $contact->access_level->value;
            $this->contactCanViewBilling = $contact->can_view_billing;
            $this->contactCanRequestServiceChange = $contact->can_request_service_change;
            $this->contactCanReceiveNotifications = $contact->can_receive_notifications;
            $this->contactIsAuthorized = $contact->is_authorized_contact;
        }
    }

    public function cancelContactForm(): void
    {
        $this->resetContactForm();
        $this->showContactForm = false;
        $this->editingContactId = null;
    }

    private function resetContactForm(): void
    {
        $this->reset([
            'contactName', 'contactPhone', 'contactRelationship', 'contactAccessLevel',
            'contactCanViewBilling', 'contactCanRequestServiceChange', 'contactIsAuthorized',
        ]);
        $this->contactCanReceiveNotifications = true;
    }

    public function saveContact(CreateCustomerContactAction $createAction, UpdateCustomerContactAction $updateAction): void
    {
        $data = $this->validate([
            'contactName' => 'required|string|max:255',
            'contactPhone' => 'required|string|max:20',
            'contactRelationship' => 'nullable|string|max:255',
            'contactAccessLevel' => 'required|string',
        ]);

        $payload = [
            'name' => $data['contactName'],
            'phone_number' => $data['contactPhone'],
            'relationship' => $data['contactRelationship'] ?: null,
            'access_level' => ContactAccessLevel::from($data['contactAccessLevel']),
            'can_view_billing' => $this->contactCanViewBilling,
            'can_request_service_change' => $this->contactCanRequestServiceChange,
            'can_receive_notifications' => $this->contactCanReceiveNotifications,
            'is_authorized_contact' => $this->contactIsAuthorized,
        ];

        if ($this->editingContactId) {
            $contact = $this->customer->contacts()->findOrFail($this->editingContactId);
            $this->authorize('update', $contact);
            $updateAction->handle($contact, $payload);
        } else {
            $this->authorize('create', CustomerContact::class);
            $createAction->handle($this->customer, $payload);
        }

        $this->cancelContactForm();
        $this->customer->refresh();
    }

    public function deleteContact(int $contactId): void
    {
        $contact = $this->customer->contacts()->findOrFail($contactId);
        $this->authorize('delete', $contact);
        $contact->delete();
    }

    public function openAddDeviceForm(): void
    {
        $this->authorize('create', [CpeDevice::class, $this->customer->reseller]);

        $this->newDeviceSerial = '';
        $this->resetErrorBag('newDeviceSerial');
        $this->showAddDeviceForm = true;
    }

    public function cancelAddDeviceForm(): void
    {
        $this->newDeviceSerial = '';
        $this->resetErrorBag('newDeviceSerial');
        $this->showAddDeviceForm = false;
    }

    /**
     * The first manual bind path that doesn't go through a WorkOrder
     * (bindFromWorkOrder()) or the legacy importer
     * (ImportLegacyCpeBindings/LegacyDeviceMatcherService) — for a customer
     * like Sartimin who has zero cpe_devices rows and no work order to
     * bind from. Reuses CpeBindingService::bindFromLegacyImport() as-is
     * (the exact same method "Ganti Modem" on /cpe-devices already calls),
     * not a new binding code path — it already does exactly what's needed
     * here: look up the serial in GenieACS if known, create a
     * pending_first_connect row if not (never fails hard just because the
     * device hasn't informed yet), bind to this customer.
     */
    public function bindDevice(CpeBindingService $service): void
    {
        $this->authorize('create', [CpeDevice::class, $this->customer->reseller]);

        if ($this->customer->cpeDevices()->exists()) {
            $this->addError('newDeviceSerial', 'Customer ini sudah punya device ter-bind — pakai "Ganti Modem" di /cpe-devices kalau mau mengganti.');

            return;
        }

        $this->validate(['newDeviceSerial' => 'required|string|max:255']);

        try {
            $device = $service->bindFromLegacyImport($this->customer, trim($this->newDeviceSerial), null);
        } catch (Throwable $e) {
            $this->addError('newDeviceSerial', 'Gagal bind device: '.$e->getMessage());

            return;
        }

        $this->showAddDeviceForm = false;
        $this->newDeviceSerial = '';

        session()->flash(
            'device_bound_message',
            $device->genieacs_device_id !== null
                ? 'Device berhasil di-bind dan sudah dikenali GenieACS.'
                : 'Device berhasil di-bind, tapi belum pernah terlihat di GenieACS — statusnya "Menunggu Koneksi Pertama" sampai dia inform pertama kali.'
        );

        $this->customer->refresh();
    }

    public function render()
    {
        $this->customer->refresh();

        $availableTransitions = collect(CustomerStatus::cases())
            ->filter(fn (CustomerStatus $status) => $this->customer->status->canTransitionTo($status))
            ->values();

        return view('livewire.customers.customer-show', [
            'contacts' => $this->customer->contacts()->latest()->get(),
            'timelineEntries' => $this->customer->timelineEntries()->with('actor')->paginate(15),
            'availableTransitions' => $availableTransitions,
            'accessLevels' => ContactAccessLevel::cases(),
            'canManage' => auth()->user()->can('update', $this->customer),
            'cpeDevice' => $this->customer->cpeDevices()->first(),
            'canAddDevice' => auth()->user()->can('create', [CpeDevice::class, $this->customer->reseller]),
        ]);
    }
}
