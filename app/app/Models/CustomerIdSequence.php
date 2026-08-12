<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Internal counter table backing CustomerIdGeneratorService — deliberately
 * NOT BelongsToTenant/TenantScope-scoped (same posture as
 * reseller_tax_ledger/whatsapp_message_templates): every query here always
 * passes tenant_id explicitly rather than relying on Auth::check(), since
 * customer creation (and therefore CID generation) can run from a queued
 * job or import script with no authenticated user.
 */
class CustomerIdSequence extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'next_number',
    ];
}
