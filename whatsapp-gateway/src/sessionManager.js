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
   * GET /sessions/{key}/qr handler logic — if the session was logged out,
   * the persisted creds are no longer usable, so its auth_state folder is
   * wiped first to force a completely fresh pairing (new QR). A merely
   * "disconnected" (non-logout) session already reconnects on its own via
   * connection.update's handler below, so no wipe is needed for that case.
   */
  async getOrRefreshQr(sessionKey) {
    const entry = this.sessions.get(sessionKey);

    if (entry && entry.status === 'logged_out') {
      const authDir = path.join(AUTH_STATE_DIR, sessionKey);
      fs.rmSync(authDir, { recursive: true, force: true });
      this.sessions.delete(sessionKey);
    }

    await this.ensureConnected(sessionKey);

    return this.getQrCodeData(sessionKey);
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
    });

    const entry = this.sessions.get(sessionKey) || { status: 'qr_pending' };
    entry.sock = sock;
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

        entry.status = loggedOut ? 'logged_out' : 'disconnected';
        this.logger.warn({ sessionKey, loggedOut, statusCode }, 'session disconnected');
        await notifySessionStatus(this.logger, {
          session_key: sessionKey,
          status: entry.status,
        });

        if (!loggedOut) {
          // Transient disconnect (network blip, container restart) —
          // reconnect automatically using the same persisted creds. A
          // genuine logout instead waits for an explicit "refresh QR"
          // action (getOrRefreshQr), which wipes creds and starts a fresh
          // pairing rather than looping reconnect attempts against a
          // permanently invalid session.
          this.connect(sessionKey).catch((err) => {
            this.logger.error({ sessionKey, err: err.message }, 'reconnect failed');
          });
        }
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

    if (entry.status !== 'connected') {
      throw new Error(`session "${sessionKey}" is not connected (status=${entry.status})`);
    }

    const jid = this.toJid(phoneNumber);
    await entry.sock.sendMessage(jid, { text: message });
  }

  toJid(phoneNumber) {
    const digits = String(phoneNumber).replace(/[^0-9]/g, '');

    return `${digits}@s.whatsapp.net`;
  }
}

module.exports = { SessionManager };
