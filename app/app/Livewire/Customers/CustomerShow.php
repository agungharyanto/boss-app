<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\CreateCustomerContactAction;
use App\Actions\Customers\UpdateCustomerAction;
use App\Actions\Customers\UpdateCustomerContactAction;
use App\Actions\Customers\UpdateCustomerStatusAction;
use App\Enums\ContactAccessLevel;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

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
        ]);
    }
}
