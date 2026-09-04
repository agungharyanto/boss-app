'use strict';

const path = require('path');
const fs = require('fs');
const QRCode = require('qrcode');
const pino = require('pino');
const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
} = require('@whiskeysockets/baileys');

const { notifySessionStatus } = require('./webhook');

const AUTH_STATE_DIR = path.join(__dirname, '..', 'auth_state');

// Baileys' internal `sendMessage` does a query/waitForMessage round-trip
// against WhatsApp's servers. When a session's socket is half-dead (Noise
// handshake OK, `connection: open` fired, but the server isn't answering
// this client's stanzas — e.g. a rate-limited/restricted number), that
// call hangs for the full `defaultQueryTimeoutMs` (60s). We race it against
// a shorter deadline so the HTTP caller gets a clear, fast error instead of
// a 60s stall that then trips the caller's own (shorter) timeout with an
// opaque cURL-28.
const SEND_TIMEOUT_MS = 20000;

// Exponential backoff for automatic reconnects on a transient
// (non-logout, non-badSession) disconnect — was an immediate tight loop
// before, which hammers WhatsApp and can escalate a soft restriction.
const RECONNECT_BASE_MS = 5000;
const RECONNECT_MAX_MS = 60000;

/**
 * Sprint "whatsapp-gateway-reliability" — investigasi log riil ~20 jam
 * container ini (lihat CLAUDE.md untuk detail lengkap) menemukan pola
 * `stream:error conflict type=device_removed` berulang (3x dalam <1.5 jam
 * pada satu window) tepat setelah sesi berhasil reconnect. Root cause yang
 * TERBUKTI (dibaca langsung dari source Baileys `lib/Socket/socket.js`,
 * bukan tebakan) — BUKAN kebocoran socket lama (Baileys sendiri sudah
 * memanggil `end()`/`ws.close()` internal setiap disconnect, dikonfirmasi
 * dari source) — melainkan RACE CONDITION nyata: `connect()` versi lama
 * punya DUA `await` (useMultiFileAuthState, fetchLatestBaileysVersion)
 * SEBELUM `this.sessions.set(sessionKey, entry)` — kalau `connect()`
 * dipanggil dua kali nyaris bersamaan untuk `sessionKey` yang SAMA
 * (mis. `index.js` mulai `app.listen()` sebelum `restoreAll()` selesai,
 * dan request HTTP masuk di window itu; atau race UI "refresh" vs auto-
 * reconnect timer), KEDUANYA lolos cek `!existing.sock` di
 * `ensureConnected()` dan KEDUANYA memanggil `makeWASocket()` — dua socket
 * hidup sekaligus mengautentikasi sebagai perangkat tertaut yang SAMA,
 * yang oleh server WhatsApp dideteksi sebagai konflik dan salah satunya
 * di-`device_removed`.
 *
 * FIX: `connectLocks` — Map<sessionKey, Promise> — panggilan `connect()`
 * kedua untuk key yang sama SELAGI yang pertama masih berjalan tidak lagi
 * memulai `makeWASocket()` baru, melainkan menunggu (await) Promise yang
 * SAMA. Ini menutup race-nya secara struktural, di titik manapun ia bisa
 * terjadi — bukan cuma di satu jalur pemicu spesifik.
 *
 * FIX KEDUA: `ensureConnected()`/`getOrRefreshQr()` versi lama SELAMANYA
 * menganggap `entry.sock` yang sudah pernah di-set (walau socket-nya sudah
 * mati/`status: 'disconnected'`) sebagai "masih ada", jadi tombol
 * refresh/reconnect manual di UI jadi NO-OP total selama window backoff
 * otomatis — kemungkinan besar ini akar "harus refresh berkali-kali,
 * kelihatannya tidak ngaruh" yang dilaporkan Agung. Sekarang
 * `getOrRefreshQr()` pada status `disconnected` membatalkan timer backoff
 * yang masih menunggu lalu memicu reconnect SEKARANG (aman berkat lock di
 * atas — tidak lagi bisa balapan dengan timer otomatis yang dibatalkan).
 */
class SessionManager {
  constructor(logger) {
    this.logger = logger;
    this.sessions = new Map();
    // sessionKey -> Promise, hanya terisi SELAGI connect() untuk key itu
    // sedang berjalan. Lihat docblock kelas di atas.
    this.connectLocks = new Map();

    if (!fs.existsSync(AUTH_STATE_DIR)) {
      fs.mkdirSync(AUTH_STATE_DIR, { recursive: true });
    }
  }

  /** Re-attach every session with a persisted auth_state folder — survives Node restarts. */
  async restoreAll() {
    const entries = fs.readdirSync(AUTH_STATE_DIR, { withFileTypes: true });

    for (const entry of entries) {
      if (entry.isDirectory()) {
        this.logger.info({ sessionKey: entry.name }, 'restoring persisted session');
        await this.connect(entry.name).catch((err) => {
          this.logger.error({ sessionKey: entry.name, err: err.message }, 'failed to restore session');
        });
      }
    }
  }

  getState(sessionKey) {
    const existing = this.sessions.get(sessionKey);

    return {
      session_key: sessionKey,
      status: existing?.status || 'qr_pending',
      phone_number: existing?.phoneNumber || null,
    };
  }

  listStates() {
    return Array.from(this.sessions.keys()).map((key) => this.getState(key));
  }

  getQrCodeData(sessionKey) {
    return this.sessions.get(sessionKey)?.qrCodeData || null;
  }

  /**
   * GET /sessions/{key}/qr handler logic — if the session was logged out
   * OR its persisted creds are bad (DisconnectReason.badSession), the
   * persisted creds are no longer usable, so its auth_state folder is wiped
   * first to force a completely fresh pairing (new QR).
   *
   * A merely "disconnected" (transient) session already has its own
   * exponential-backoff reconnect timer scheduled — but that timer can be
   * up to RECONNECT_MAX_MS (60s) away. An explicit refresh here CANCELS
   * that pending timer and reconnects NOW instead — safe to do
   * unconditionally because `connect()` itself is lock-protected (see
   * class docblock), so this can never race the timer it just cancelled
   * nor any other concurrent caller for the same key.
   */
  async getOrRefreshQr(sessionKey) {
    const entry = this.sessions.get(sessionKey);

    if (entry && (entry.status === 'logged_out' || entry.status === 'bad_session')) {
      this.wipeAuthState(sessionKey);
      this.sessions.delete(sessionKey);
    } else if (entry && entry.status === 'disconnected' && entry.reconnectTimer) {
      clearTimeout(entry.reconnectTimer);
      entry.reconnectTimer = null;
    }

    await this.ensureConnected(sessionKey, { force: entry?.status === 'disconnected' });

    return this.getQrCodeData(sessionKey);
  }

  wipeAuthState(sessionKey) {
    const authDir = path.join(AUTH_STATE_DIR, sessionKey);
    fs.rmSync(authDir, { recursive: true, force: true });
  }

  /**
   * Lock-protected wrapper — the actual socket-creation logic lives in
   * `doConnect()`. A second call for the same `sessionKey` while the first
   * is still in flight AWAITS the first call's own promise instead of
   * starting a second `makeWASocket()` (see class docblock — this is the
   * structural fix for the `device_removed` race).
   */
  connect(sessionKey) {
    const inFlight = this.connectLocks.get(sessionKey);
    if (inFlight) {
      return inFlight;
    }

    const promise = this.doConnect(sessionKey).finally(() => {
      this.connectLocks.delete(sessionKey);
    });
    this.connectLocks.set(sessionKey, promise);

    return promise;
  }

  async doConnect(sessionKey) {
    const authDir = path.join(AUTH_STATE_DIR, sessionKey);
    const { state, saveCreds } = await useMultiFileAuthState(authDir);
    const { version } = await fetchLatestBaileysVersion();

    const sock = makeWASocket({
      version,
      auth: state,
      logger: pino({ level: 'warn' }),
      printQRInTerminal: false,
      // We only ever send outbound text — skip the presence query and full
      // history sync that otherwise pile onto the post-connect init phase.
      markOnlineOnConnect: false,
      syncFullHistory: false,
    });

    const entry = this.sessions.get(sessionKey) || { status: 'qr_pending', reconnectAttempts: 0 };
    entry.sock = sock;
    if (entry.reconnectAttempts === undefined) {
      entry.reconnectAttempts = 0;
    }
    this.sessions.set(sessionKey, entry);

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
      const { connection, lastDisconnect, qr } = update;

      if (qr) {
        entry.qrCodeData = await QRCode.toDataURL(qr);
        entry.status = 'qr_pending';
        this.logger.info({ sessionKey }, 'new QR code generated');
        await notifySessionStatus(this.logger, {
          session_key: sessionKey,
          status: 'qr_pending',
          qr_code_data: entry.qrCodeData,
        });
      }

      if (connection === 'open') {
        entry.status = 'connected';
        entry.phoneNumber = sock.user?.id ? sock.user.id.split(':')[0] : null;
        entry.qrCodeData = null;
        entry.reconnectAttempts = 0;
        this.logger.info({ sessionKey, phoneNumber: entry.phoneNumber }, 'session connected');
        await notifySessionStatus(this.logger, {
          session_key: sessionKey,
          status: 'connected',
          phone_number: entry.phoneNumber,
        });
      }

      if (connection === 'close') {
        const statusCode = lastDisconnect?.error?.output?.statusCode;
        const loggedOut = statusCode === DisconnectReason.loggedOut;
        const badSession = statusCode === DisconnectReason.badSession;

        if (loggedOut || badSession) {
          // Persisted creds are permanently unusable. Wipe them and stop —
          // reconnecting with the same bad creds just loops forever. The
          // next explicit "refresh QR" starts a clean pairing.
          entry.status = badSession ? 'bad_session' : 'logged_out';
          this.logger.warn({ sessionKey, statusCode, reason: entry.status }, 'session unusable — wiping creds, awaiting re-pair');
          this.wipeAuthState(sessionKey);
          await notifySessionStatus(this.logger, {
            session_key: sessionKey,
            status: badSession ? 'logged_out' : entry.status,
          });

          return;
        }

        entry.status = 'disconnected';
        this.logger.warn({ sessionKey, statusCode }, 'session disconnected');
        await notifySessionStatus(this.logger, {
          session_key: sessionKey,
          status: 'disconnected',
        });

        const attempt = entry.reconnectAttempts || 0;
        entry.reconnectAttempts = attempt + 1;
        const delay = Math.min(RECONNECT_BASE_MS * 2 ** attempt, RECONNECT_MAX_MS);

        this.logger.info({ sessionKey, delayMs: delay, attempt: attempt + 1 }, 'scheduling reconnect');
        entry.reconnectTimer = setTimeout(() => {
          entry.reconnectTimer = null;
          this.connect(sessionKey).catch((err) => {
            this.logger.error({ sessionKey, err: err.message }, 'reconnect failed');
          });
        }, delay);
      }
    });

    return entry;
  }

  async ensureConnected(sessionKey, { force = false } = {}) {
    const existing = this.sessions.get(sessionKey);

    if (!existing || !existing.sock || force) {
      return this.connect(sessionKey);
    }

    return existing;
  }

  async sendMessage(sessionKey, phoneNumber, message) {
    const entry = await this.ensureConnected(sessionKey);

    // `entry.status` alone is a stale flag — it stays 'connected' even
    // after the socket has gone half-dead. Require the socket to also have
    // an authenticated user identity before attempting a send.
    if (entry.status !== 'connected' || !entry.sock?.user?.id) {
      throw new Error(`session "${sessionKey}" is not connected (status=${entry.status})`);
    }

    const jid = this.toJid(phoneNumber);

    let timer;
    const deadline = new Promise((_resolve, reject) => {
      timer = setTimeout(
        () => reject(new Error(`send timed out after ${SEND_TIMEOUT_MS}ms — session likely unhealthy (connected but not responding)`)),
        SEND_TIMEOUT_MS,
      );
    });

    try {
      await Promise.race([entry.sock.sendMessage(jid, { text: message }), deadline]);
    } finally {
      clearTimeout(timer);
    }
  }

  /**
   * Sprint "whatsapp-gateway-reliability" LANGKAH 2 — alternatif Kode
   * Pairing (native Baileys `requestPairingCode`, BUKAN ganti backend).
   * HANYA untuk sesi yang belum pernah dipasangkan (fresh/unregistered) —
   * kode pairing tidak berlaku untuk sesi yang sudah `connected`.
   *
   * Selalu MEMULAI SESI BARU (wipe dulu kalau ada sisa auth_state lama —
   * sama seperti klik "refresh QR" pada sesi logged_out/bad_session) supaya
   * `sock.authState.creds.registered` benar-benar `false` saat
   * `requestPairingCode()` dipanggil — Baileys menolak/mengembalikan hasil
   * tidak valid kalau dipanggil pada sesi yang sudah teregistrasi.
   *
   * @returns {Promise<string>} kode 8 karakter (mis. "ABCD-1234")
   */
  async requestPairingCode(sessionKey, phoneNumber) {
    const digits = String(phoneNumber).replace(/[^0-9]/g, '');
    if (!digits) {
      throw new Error('phone_number is required');
    }

    const existing = this.sessions.get(sessionKey);
    if (existing?.status === 'connected') {
      throw new Error(`session "${sessionKey}" is already connected — pairing code only applies to a fresh session`);
    }

    // Mulai dari nol — sama seperti alur "refresh QR" pada sesi logged_out.
    this.wipeAuthState(sessionKey);
    this.sessions.delete(sessionKey);

    const entry = await this.connect(sessionKey);

    if (entry.sock.authState.creds.registered) {
      // Seharusnya mustahil tercapai (baru saja di-wipe), tapi dijaga
      // eksplisit karena Baileys sendiri tidak menolaknya secara jelas.
      throw new Error('session unexpectedly already registered — cannot request a pairing code');
    }

    const code = await entry.sock.requestPairingCode(digits);
    entry.pairingCode = code;
    entry.pairingPhoneNumber = digits;

    this.logger.info({ sessionKey, phoneNumber: digits }, 'pairing code requested');

    return code;
  }

  /**
   * Normalisasi nomor Indonesia ke format JID WhatsApp (kode negara, TANPA
   * '0' di depan, TANPA '+').
   *
   * BUG NYATA sebelum ini (laten sejak v0.4.0, ditemukan 2026-09-03): kode
   * lama hanya `replace(/[^0-9]/g, '')` — nomor lokal `087884374939` jadi
   * `087884374939@s.whatsapp.net` (JID tidak sah). `sock.sendMessage()` ke
   * JID bogus meng-hang saat Baileys mencoba resolve device-list-nya →
   * timeout, yang sempat SALAH didiagnosis sebagai "akun di-restrict".
   * Terbukti: kirim ke `6287884374939@s.whatsapp.net` sukses ~1 detik.
   *
   *   08xxxxxxxxxx  → 62 8xxxxxxxxxx
   *   628xxxxxxxxx  → tetap
   *   +628xxxxxxxxx → 628xxxxxxxxx
   *   8xxxxxxxxxx   → 62 8xxxxxxxxxx (asumsi Indonesia — deployment ISP ID)
   */
  toJid(phoneNumber) {
    let digits = String(phoneNumber).replace(/[^0-9]/g, '');

    if (digits.startsWith('0')) {
      digits = '62' + digits.slice(1);
    } else if (!digits.startsWith('62')) {
      digits = '62' + digits;
    }

    return `${digits}@s.whatsapp.net`;
  }
}

module.exports = { SessionManager };
