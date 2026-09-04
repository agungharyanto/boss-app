'use strict';

const express = require('express');
const pino = require('pino');

const hmac = require('./src/hmac');
const { SessionManager } = require('./src/sessionManager');

const PORT = process.env.PORT || 3000;
const HMAC_SECRET = process.env.WHATSAPP_GATEWAY_HMAC_SECRET || '';

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });

if (!HMAC_SECRET) {
  logger.warn('WHATSAPP_GATEWAY_HMAC_SECRET is empty — every request will be rejected. Set it in whatsapp-gateway/.env.');
}

const sessionManager = new SessionManager(logger);

const app = express();

// Raw body is captured for HMAC verification — signing over a re-encoded
// JSON string would silently break on any whitespace/key-order difference
// versus what Laravel actually signed.
app.use(
  express.json({
    verify: (req, _res, buf) => {
      req.rawBody = buf.toString('utf8');
    },
  }),
);

function verifyHmac(req, res, next) {
  const signature = req.header('X-Whatsapp-Signature');
  const timestamp = req.header('X-Whatsapp-Timestamp');
  const body = req.rawBody || '';

  if (!hmac.verify(HMAC_SECRET, body, signature, timestamp)) {
    logger.warn({ path: req.path }, 'rejected request with invalid/missing HMAC signature');

    return res.status(401).json({ success: false, message: 'Invalid signature', data: null, meta: {} });
  }

  next();
}

app.get('/sessions', verifyHmac, (_req, res) => {
  res.json({ success: true, message: 'OK', data: null, meta: {}, sessions: sessionManager.listStates() });
});

app.get('/sessions/:sessionKey/health', verifyHmac, (req, res) => {
  res.json({ success: true, message: 'OK', data: sessionManager.getState(req.params.sessionKey), meta: {} });
});

app.get('/sessions/:sessionKey/qr', verifyHmac, async (req, res) => {
  try {
    const qrCodeData = await sessionManager.getOrRefreshQr(req.params.sessionKey);
    res.json({ success: true, message: 'OK', data: null, meta: {}, qr_code_data: qrCodeData });
  } catch (err) {
    logger.error({ err: err.message, sessionKey: req.params.sessionKey }, 'failed to get/refresh QR');
    res.status(500).json({ success: false, message: err.message, data: null, meta: {} });
  }
});

// Sprint "whatsapp-gateway-reliability" — alternatif "Kode Pairing" (native
// Baileys requestPairingCode), untuk sesi yang belum pernah dipasangkan.
app.post('/sessions/:sessionKey/pair', verifyHmac, async (req, res) => {
  const { phone_number: phoneNumber } = req.body || {};

  if (!phoneNumber) {
    return res.status(422).json({ success: false, message: 'phone_number is required', data: null, meta: {} });
  }

  try {
    const pairingCode = await sessionManager.requestPairingCode(req.params.sessionKey, phoneNumber);
    res.json({ success: true, message: 'OK', data: null, meta: {}, pairing_code: pairingCode });
  } catch (err) {
    logger.error({ err: err.message, sessionKey: req.params.sessionKey }, 'failed to request pairing code');
    res.status(500).json({ success: false, message: err.message, data: null, meta: {} });
  }
});

// Branch migrasi-whatsmeow — tombol "Logout" UI, BEDA dari wipe internal
// yang sudah ada (logged_out/bad_session): ini memanggil sock.logout()
// Baileys dulu (memberi tahu server WhatsApp) sebelum menghapus state
// lokal — lihat sessionManager.js's logout() docblock.
app.post('/sessions/:sessionKey/logout', verifyHmac, async (req, res) => {
  try {
    await sessionManager.logout(req.params.sessionKey);
    res.json({ success: true, message: 'Logged out', data: null, meta: {} });
  } catch (err) {
    logger.error({ err: err.message, sessionKey: req.params.sessionKey }, 'failed to logout session');
    res.status(500).json({ success: false, message: err.message, data: null, meta: {} });
  }
});

app.post('/sessions/:sessionKey/send', verifyHmac, async (req, res) => {
  const { phone_number: phoneNumber, message } = req.body || {};

  if (!phoneNumber || !message) {
    return res.status(422).json({ success: false, message: 'phone_number and message are required', data: null, meta: {} });
  }

  try {
    await sessionManager.sendMessage(req.params.sessionKey, phoneNumber, message);
    res.json({ success: true, message: 'Sent', data: null, meta: {} });
  } catch (err) {
    logger.error({ err: err.message, sessionKey: req.params.sessionKey }, 'failed to send message');
    res.status(502).json({ success: false, message: err.message, data: null, meta: {} });
  }
});

app.listen(PORT, async () => {
  logger.info(`whatsapp-gateway listening on port ${PORT}`);
  await sessionManager.restoreAll().catch((err) => {
    logger.error({ err: err.message }, 'failed to restore persisted sessions on boot');
  });
});
