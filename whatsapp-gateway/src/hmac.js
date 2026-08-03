'use strict';

const crypto = require('crypto');

// Mirrors app/app/Support/WhatsappHmac.php exactly — timestamp.body signed
// with HMAC-SHA256, 5 minute tolerance window against replay.
const TOLERANCE_SECONDS = 300;

function sign(secret, body, timestamp) {
  return crypto.createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');
}

function verify(secret, body, signature, timestampHeader) {
  const timestamp = parseInt(timestampHeader, 10);

  if (!Number.isFinite(timestamp)) {
    return false;
  }

  if (Math.abs(Math.floor(Date.now() / 1000) - timestamp) > TOLERANCE_SECONDS) {
    return false;
  }

  const expected = sign(secret, body, timestamp);
  const expectedBuf = Buffer.from(expected, 'utf8');
  const givenBuf = Buffer.from(String(signature || ''), 'utf8');

  if (expectedBuf.length !== givenBuf.length) {
    return false;
  }

  return crypto.timingSafeEqual(expectedBuf, givenBuf);
}

module.exports = { sign, verify };
