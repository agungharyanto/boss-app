"""Satu antrean in-process per `olt_device_id` (desain §6) — mencegah dua
sesi CLI terbuka bersamaan ke OLT yang sama (kelas insiden "Max Logins=1"
yang sudah ditemukan nyata untuk G02ID/root di v0.23.1). SATU sesi CLI
penuh (login -> command -> logout) memegang lock ini sepanjang durasinya.
"""
from __future__ import annotations

import threading

_locks: dict[int, threading.Lock] = {}
_locks_guard = threading.Lock()

DEFAULT_LOCK_TIMEOUT_SECONDS = 30.0


class OltBusyError(Exception):
    pass


def _get_lock(olt_device_id: int) -> threading.Lock:
    with _locks_guard:
        lock = _locks.get(olt_device_id)
        if lock is None:
            lock = threading.Lock()
            _locks[olt_device_id] = lock
        return lock


class OltSessionGuard:
    """Context manager: `with OltSessionGuard(olt_device_id):` — blocking
    acquire dengan timeout wajar; raise OltBusyError kalau OLT sedang
    dipakai request lain, daripada mengizinkan 2 sesi CLI bersamaan."""

    def __init__(self, olt_device_id: int, timeout: float = DEFAULT_LOCK_TIMEOUT_SECONDS):
        self.olt_device_id = olt_device_id
        self.timeout = timeout
        self._lock = _get_lock(olt_device_id)

    def __enter__(self) -> 'OltSessionGuard':
        acquired = self._lock.acquire(timeout=self.timeout)
        if not acquired:
            raise OltBusyError(f'OLT #{self.olt_device_id} sedang dipakai request lain — coba lagi')
        return self

    def __exit__(self, exc_type, exc, tb) -> None:
        self._lock.release()
