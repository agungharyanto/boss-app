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
 * Multi-session Baileys manager, keyed by session_key (reseller_id as a
 * string, or the literal "direct") — one Baileys socket + persisted
 * auth_state/{session_key}/ folder per key, per the v0.4.0 spec's fixed
 * topology (one WhatsApp number per reseller, plus one direct session).
 */
class SessionManager {
  constructor(logger) {
    this.logger = logger;
    this.sessions = new Map();

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
   * first to force a completely fresh pairing (new QR). A merely
   * "disconnected" (transient) session already reconnects on its own via
   * connection.update's handler below, so no wipe is needed for that case.
   */
  async getOrRefreshQr(sessionKey) {
    const entry = this.sessions.get(sessionKey);

    if (entry && (entry.status === 'logged_out' || entry.status === 'bad_session')) {
      this.wipeAuthState(sessionKey);
      this.sessions.delete(sessionKey);
    }

    await this.ensureConnected(sessionKey);

    return this.getQrCodeData(sessionKey);
  }

  wipeAuthState(sessionKey) {
    const authDir = path.join(AUTH_STATE_DIR, sessionKey);
    fs.rmSync(authDir, { recursive: true, force: true });
  }

  async connect(sessionKey) {
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
        setTimeout(() => {
          this.connect(sessionKey).catch((err) => {
            this.logger.error({ sessionKey, err: err.message }, 'reconnect failed');
          });
        }, delay);
      }
    });

    return entry;
  }

  async ensureConnected(sessionKey) {
    const existing = this.sessions.get(sessionKey);

    if (!existing || !existing.sock) {
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
