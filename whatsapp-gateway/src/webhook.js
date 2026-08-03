'use strict';

const hmac = require('./hmac');

const LARAVEL_BASE_URL = process.env.LARAVEL_BASE_URL || 'http://boss-nginx';
const HMAC_SECRET = process.env.WHATSAPP_GATEWAY_HMAC_SECRET || '';

/**
 * POSTs a connection.update event to Laravel's
 * /api/v1/whatsapp/webhook/session-status, HMAC-signed the same way
 * SendWhatsappMessageJob signs its outbound calls the other direction.
 * Fire-and-forget with a logged failure — a missed webhook is recovered by
 * WhatsappSessionService::reconcileFromGateway() (whatsapp:check-session-health,
 * hourly) reading GET /sessions instead.
 */
async function notifySessionStatus(logger, payload) {
  const body = JSON.stringify(payload);
  const timestamp = Math.floor(Date.now() / 1000);
  const signature = hmac.sign(HMAC_SECRET, body, timestamp);

  try {
    const response = await fetch(`${LARAVEL_BASE_URL}/api/v1/whatsapp/webhook/session-status`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Whatsapp-Timestamp': String(timestamp),
        'X-Whatsapp-Signature': signature,
      },
      body,
    });

    if (!response.ok) {
      logger.warn({ status: response.status, payload }, 'session-status webhook rejected by Laravel');
    }
  } catch (err) {
    logger.error({ err: err.message, payload }, 'failed to POST session-status webhook to Laravel');
  }
}

module.exports = { notifySessionStatus };
