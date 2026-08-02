# BOSS App — Roadmap Sprint (Urutan Dikunci)

| Versi   | Cluster         | Nama                          | Isi Utama                                                                                   | Status  |
|---------|-----------------|-------------------------------|-----------------------------------------------------------------------------------------------|---------|
| v0.1.0  | Operasional     | Foundation                    | Repo, Docker, Laravel, PostgreSQL, Redis, Nginx, login, role, UFW/Fail2ban, backup            | Selesai |
| v0.2.0  | Operasional     | Customer CRM                  | Data pelanggan, kontak keluarga, authorized contact, customer timeline                        | Selesai |
| v0.3.0  | Operasional     | Registration & Referral       | Registrasi multi-channel, agent, referral, komisi pending                                     | Selesai |
| v0.3.1  | UI/UX           | Personalization & Navigation  | Theme custom (primary/text color), language switcher, dashboard widget selector, sidebar cluster-dropdown | Backlog |
| v0.3.2  | Operasional     | Multi-Tenant Reseller         | Tabel resellers (child dari tenant), reseller_id menyebar, guard/role reseller, reseller_package_pricing | Backlog |
| v0.3.3  | Billing & Finance | Regulatory Tax Engine        | tax_components dinamis (nama bebas, persen/nominal, on/off, versi-per-tanggal), reseller_tax_policies, reseller_tax_ledger, komdigi_remittance_summary | Backlog |
| v0.3.4  | Billing & Finance | Invoicing Core               | Subscription plan per customer, generate invoice bulanan, invoice line items, status invoice   | Backlog |
| v0.3.5  | Billing & Finance | Payment Gateway (Xendit)     | Integrasi Xendit (VA/QRIS/invoice), webhook handler + signature verification, idempotency, reconciliation | Backlog |
| v0.4.0  | Komunikasi      | Communication (Baileys)       | WhatsApp gateway, notifikasi group, routing area, OTP                                         | Backlog |
| v0.5.0  | Operasional     | Installation                  | Work order teknisi, scan MAC/serial, ODP/PON, foto instalasi                                  | Backlog |
| v0.6.0  | Network         | FreeRADIUS                    | Akun PPPoE via RADIUS, profile bandwidth, accounting (radacct), CoA/disconnect                | Backlog |
| v0.6.1  | Customer App    | Mobile Self-Service Portal    | Auth guard customer terpisah, ganti password (OTP), cek pemakaian, bayar tagihan               | Backlog |
| v0.7.0  | Network         | GenieACS                      | Binding ONT, SSID/password, RX power, reboot, provisioning                                    | Backlog |
| v0.8.0  | Network         | LibreNMS & Graph              | Device monitoring, graph jaringan, graph pemakaian per-pelanggan, alert                       | Backlog |
| v0.9.0  | Billing & Finance | Commission                   | Eligibility, approval, payment, clawback (menyempurnakan commission_ledger v0.3.0)             | Backlog |
| v0.10.0 | Network         | Outage Engine                 | ONT down detection, korelasi area, incident, maintenance                                      | Backlog |

Kita tidak loncat versi dalam satu cluster. Setiap versi selesai penuh
(lihat Definition of Done di RULES.md) sebelum lanjut ke versi berikutnya.
Urutan antar-cluster mengikuti dependency teknis: reseller sebelum
billing/tax (hindari retrofit), tax sebelum invoicing, invoicing sebelum
payment gateway, FreeRADIUS sebelum mobile app & usage graph.
