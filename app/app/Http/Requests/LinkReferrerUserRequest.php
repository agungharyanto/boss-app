<?php

namespace App\Http\Requests;

use App\Models\Referrer;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkReferrerUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Referrer::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                Rule::exists(User::class, 'id')->where('tenant_id', $this->user()->tenant_id),
                // Not-already-linked-to-another-Referrer is checked in
                // ReferrerService::linkExistingUser() (also enforced at the
                // DB level by referrers.user_id's own unique constraint) —
                // not duplicated here as a Rule::unique, since "which
                // referrer_id to exclude" doesn't apply the same way an
                // update-in-place does.
            ],
        ];
    }
}
