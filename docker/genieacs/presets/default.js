const hourly = Date.now() - 3590000;
const minutes = Date.now() - 60000;
const daily = Date.now() - 86400000;

// SSID/Password — read-only requests, never sets a value (2-arg declare()
// form: path + timestamps only). Fallback across the two vendor-observed
// KeyPassphrase locations (WLANConfiguration.*.KeyPassphrase and the
// PreSharedKey sub-object variant), same two paths already used by
// cpe_parameter_maps for the one already-verified device in this fleet.
//
// `path: minutes` added 2026-08-16 alongside the pre-existing `value:
// hourly` — found (via CLAUDE.md's own GenieACS Connected Clients
// investigation) that GenieACS's declare() has TWO independent timestamp
// attributes: `value` only controls how often an ALREADY-KNOWN leaf gets
// re-read, while `path` controls how often the wildcard's own SET OF
// INSTANCES gets rediscovered (confirmed against GenieACS's own docs:
// "Using a recent timestamp for path in declare() will result in a sync
// with the device to rediscover all Host instances"). Without `path`, a
// device whose WLANConfiguration.4/.5 were never manually refreshObject'd
// stays invisible forever even though `value: hourly` on `.SSID` looks
// like it should have covered every instance — it only ever re-reads
// instances GenieACS ALREADY believes exist. This is exactly why
// WLANConfiguration.4 ("TOKEN WIFI" hotspot SSID) was only ever visible
// indirectly via the WAN side's X_CT-COM_LanInterface reference, never
// read directly, until this fix.
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.SSID", {path: minutes, value: hourly});
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.Enable", {path: minutes, value: hourly});
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.KeyPassphrase", {value: hourly});
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.PreSharedKey.*.KeyPassphrase", {value: hourly});

// MAC address fallback (both candidates from the referral, still
// unverified against a real device — see CLAUDE.md's own "masalah lama
// yang belum terpecahkan" note).
declare("InternetGatewayDevice.DeviceInfo.X_CU_SerialNumber", {value: daily});
declare("InternetGatewayDevice.LANDevice.*.LANHostConfigManagement.MACAddress", {value: daily});

// Modem uptime — a genuinely STANDARD TR-069 path (unlike RX/TX power,
// which needs a vendor-specific object), same across every vendor in this
// fleet. Refreshed hourly like the other slow-changing values above.
declare("InternetGatewayDevice.DeviceInfo.UpTime", {value: hourly});

// Connected hosts (v0.7.6) — refreshed every ~minute so
// SyncCpeConnectedHosts' own 5-minute polling always sees recent data.
//
// `path: minutes` added 2026-08-16 — root cause of the "Terakhir Terlihat:
// 3 hari yang lalu" staleness Agung reported: the device's own periodic
// Inform kept _lastInform current, but Hosts.Host's own child-instance
// list (which hosts currently exist at all) was never rediscovered after
// its first walk, frozen at whatever it was days ago — `value: minutes`
// alone can only refresh LEAVES of already-known Host.N indices, it can't
// notice a host that reconnected under a different dynamic index or
// discover the object was ever populated in the first place. Same
// path-vs-value distinction as the WLANConfiguration fix above.
declare("InternetGatewayDevice.LANDevice.*.Hosts.Host.*.HostName", {path: minutes, value: minutes});
declare("InternetGatewayDevice.LANDevice.*.Hosts.Host.*.IPAddress", {path: minutes, value: minutes});
declare("InternetGatewayDevice.LANDevice.*.Hosts.Host.*.MACAddress", {path: minutes, value: minutes});
declare("InternetGatewayDevice.LANDevice.*.Hosts.Host.*.Active", {path: minutes, value: minutes});

// Client-to-SSID mapping (2026-08-16) — AssociatedDevice never populated
// under a blanket WLANConfiguration.*.AssociatedDevice wildcard even after
// repeated manual refreshObject tasks (see CLAUDE.md's own "Bagian D"
// finding). A referral's working Huawei config queries AssociatedDevice
// per KNOWN WLANConfiguration index separately instead of one wildcard
// across all indices — mirrored here for the 3 instances this fleet is
// actually known to have (1 = main 2.4GHz, 4 = hotspot/"TOKEN WIFI", 5 =
// 5GHz, all confirmed present via manual discovery). `path` is required
// here for the same reason as Hosts above — AssociatedDevice.* is itself
// a dynamic wildcard object needing rediscovery, not just a value refresh.
// If this STILL never populates after this fix, that's the final answer:
// this fleet's vendor firmware genuinely doesn't expose AssociatedDevice
// data over CWMP, unlike the referral's Huawei devices.
// Field name fix (same day, before this preset's first real verification
// was even done): the child OBJECT instances discovered fine with `path`,
// but every leaf read back as unset — this vendor's actual TR-098 field
// name is the flat compound `AssociatedDeviceMACAddress`, not a nested
// `.MACAddress` sub-path. Confirmed by inspecting a real device's raw
// AssociatedDevice.1/.4/.5 children directly (all 3 had exactly this field
// name present, `.MACAddress` alone does not exist on this object at all).
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.1.AssociatedDevice.*.AssociatedDeviceMACAddress", {path: minutes, value: minutes});
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.4.AssociatedDevice.*.AssociatedDeviceMACAddress", {path: minutes, value: minutes});
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration.5.AssociatedDevice.*.AssociatedDeviceMACAddress", {path: minutes, value: minutes});

// MAC address via WANPPPConnection (2026-08-16) — a full config export from
// a colleague's working GenieACS instance ("teman Agung") shows their
// pattern for MAC across every vendor is `pppoeMac`: read
// WANPPPConnection.MACAddress across the 4 real-world (WANConnectionDevice,
// WANPPPConnection) index combinations they've actually seen (1.1, 1.2, 2.1,
// 2.2), taking the first one with a value — NOT any of the 3 paths tried and
// abandoned in an earlier session (X_CU_SerialNumber,
// LANHostConfigManagement.MACAddress, and a WANDevice-level MAC that never
// panned out). `path` is included on each leaf, same reasoning as the
// AssociatedDevice fix above — WANConnectionDevice/WANPPPConnection are
// themselves dynamic sub-objects, so a plain `value:`-only declare would
// only ever refresh an index GenieACS already believes exists, never
// discover one it doesn't yet know about.
declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.MACAddress", {path: minutes, value: minutes});
declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.2.MACAddress", {path: minutes, value: minutes});
declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.MACAddress", {path: minutes, value: minutes});
declare("InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.2.MACAddress", {path: minutes, value: minutes});

// Multi-SSID / multi-WAN collection discovery (2026-08-16) — a `path:`-only
// declare straight on the CONTAINER object itself (no leaf, no `value:`),
// so GenieACS walks and records the real set of instance indices that
// exist under each collection — not leaf data, just "which indices are
// really there". Existing WLANConfiguration.*.SSID/.Enable declares above
// already use a wildcard with `path:`, which is what retroactively
// surfaced index .4 fleet-wide; this is the same idea applied one level up
// so AssociatedDevice's hardcoded .1/.4/.5 indices above (borrowed from the
// referral's own known Huawei indices, not derived from our own fleet) can
// be checked against what this fleet's devices actually have, and so
// multi-WAN structure gets the same treatment WLANConfiguration already
// got — this fleet's own WANConnectionDevice/WANPPPConnection/
// WANIPConnection indices were only ever confirmed manually on a handful of
// devices (.1/.4/.6/.7), other devices may use different ones.
declare("InternetGatewayDevice.LANDevice.*.WLANConfiguration", {path: minutes});
declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection", {path: minutes});
declare("InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANIPConnection", {path: minutes});
