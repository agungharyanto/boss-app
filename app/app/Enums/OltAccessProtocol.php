<?php

namespace App\Enums;

/**
 * CLI admin/management access only — SNMP is NOT a case here (addendum
 * #2, v0.8.1). SNMP is a monitoring protocol independent of how an admin
 * logs into the OLT's CLI, so its fields live in their own always-on
 * section instead of being one more mutually-exclusive protocol choice —
 * see docs/CLAUDE.md "Network Navigation Restructure & OLT Credential
 * Registry (v0.8.1)" for the full reasoning (a real UX bug — switching
 * Access Protocol wiped whatever had been typed in the other protocol's
 * fields — is what surfaced this modeling mistake).
 *
 * Fixed set on purpose — new protocols can be added here later, but the
 * credential FORM fields per protocol stay hand-coded (Telnet/SSH
 * sections in App\Livewire\Network\OltDeviceIndex), never a generic JSON
 * blob.
 */
enum OltAccessProtocol: string
{
    case Telnet = 'telnet';
    case Ssh = 'ssh';

    public function label(): string
    {
        return match ($this) {
            self::Telnet => 'Telnet',
            self::Ssh => 'SSH',
        };
    }
}
