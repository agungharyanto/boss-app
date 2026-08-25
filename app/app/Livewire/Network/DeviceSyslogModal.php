<?php

namespace App\Livewire\Network;

use App\Services\Network\LibreNmsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * v0.8.4 — "Log" modal for DeviceMonitoringList, opened via a dispatched
 * `device-syslog-requested` event — same sibling-component pattern already
 * established for device-selected -> DeviceTrafficGraph and
 * device-history-requested -> DeviceHistoryModal, kept as its OWN
 * component rather than a 4th tab bolted onto DeviceHistoryModal: that
 * component's whole shape (metric tabs + Jam/Hari/.../Custom range tabs +
 * a Chart.js series) is built around time-series charts, which a
 * paginated/level-filtered syslog TABLE doesn't fit — same "one component
 * per distinct concern" architecture already used throughout this
 * codebase, not a new pattern invented here.
 *
 * Reuses `LibreNmsService::getSyslog()` verbatim (the same method the
 * `GET /api/v1/monitoring/devices/{device}/syslog` REST endpoint already
 * calls) — no new query logic, no direct librenms_db access from this
 * component.
 *
 * Two states, not three (unlike DeviceHistoryModal's cpu/memory/temperature
 * modal): there's no meaningful "this device has no syslog sensor" concept
 * the way a health sensor can genuinely not exist — a device either has
 * rows (`ok`) or it doesn't yet (`empty`, e.g. every device except
 * `ro-hotspot.bajastu.id` today, which has no NAS configured to send it
 * syslog at all) — both are real, non-error states. A genuine LibreNMS API
 * failure is `unavailable`, same three-state naming CONVENTION as the rest
 * of this module even though the middle state's meaning differs slightly.
 */
class DeviceSyslogModal extends Component
{
    use AuthorizesRequests;

    public bool $showModal = false;

    public ?int $deviceId = null;

    public string $deviceName = '';

    public int $limit = 50;

    public ?int $level = null;

    public string $state = 'empty';

    /** @var array<int, array{timestamp: ?string, host: ?string, program: ?string, level: ?int, msg: ?string}> */
    public array $rows = [];

    /**
     * Standard syslog severity levels (RFC 5424) — only the ones actually
     * useful as a filter option; `level` itself accepts any 0-7 from the
     * underlying data regardless of what's listed here.
     */
    public const LEVEL_LABELS = [
        2 => 'Critical',
        3 => 'Error',
        4 => 'Warning',
        5 => 'Notice',
        6 => 'Info',
        7 => 'Debug',
    ];

    public function mount(): void
    {
        $this->authorize('monitoring.view');
    }

    #[On('device-syslog-requested')]
    public function open(int $deviceId, string $deviceName): void
    {
        $this->authorize('monitoring.view');

        $this->deviceId = $deviceId;
        $this->deviceName = $deviceName;
        $this->limit = 50;
        $this->level = null;
        $this->showModal = true;

        $this->loadSyslog();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function changeLevel(string $level): void
    {
        $this->level = $level === '' ? null : (int) $level;
        $this->loadSyslog();
    }

    public function changeLimit(int $limit): void
    {
        if (! in_array($limit, [25, 50, 100, 200], true)) {
            return;
        }

        $this->limit = $limit;
        $this->loadSyslog();
    }

    public function loadSyslog(?LibreNmsService $service = null): void
    {
        if ($this->deviceId === null) {
            return;
        }

        $service ??= app(LibreNmsService::class);

        try {
            $rows = $service->getSyslog($this->deviceId, $this->limit, $this->level);
        } catch (Throwable $e) {
            Log::warning("DeviceSyslogModal: gagal ambil syslog device #{$this->deviceId} — {$e->getMessage()}");
            $this->state = 'unavailable';
            $this->rows = [];

            return;
        }

        $this->state = $rows === [] ? 'empty' : 'ok';
        $this->rows = $rows;
    }

    /**
     * @return array{label: string, class: string}
     */
    public function levelBadge(?int $level): array
    {
        return match (true) {
            $level === null => ['label' => '-', 'class' => 'bg-gray-100 text-gray-600'],
            $level <= 3 => ['label' => self::LEVEL_LABELS[$level] ?? "Level {$level}", 'class' => 'bg-red-100 text-red-700'],
            $level === 4 => ['label' => 'Warning', 'class' => 'bg-yellow-100 text-yellow-700'],
            $level === 5 => ['label' => 'Notice', 'class' => 'bg-blue-100 text-blue-700'],
            $level === 6 => ['label' => 'Info', 'class' => 'bg-gray-100 text-gray-600'],
            default => ['label' => 'Debug', 'class' => 'bg-gray-100 text-gray-500'],
        };
    }

    public function render()
    {
        return view('livewire.network.device-syslog-modal');
    }
}
