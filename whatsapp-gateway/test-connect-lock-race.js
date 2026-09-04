'use strict';

// Sprint "whatsapp-gateway-reliability" — verifikasi TERISOLASI (tanpa
// menyentuh sesi WhatsApp asli sama sekali) bahwa fix connect-lock di
// SessionManager benar-benar mencegah dua `makeWASocket()` untuk
// `sessionKey` yang sama berjalan bersamaan (akar masalah race
// `device_removed` yang didiagnosis dari log riil — lihat docblock
// SessionManager). Baileys asli di-monkey-patch di level module-cache
// SEBELUM SessionManager di-require, supaya destructuring
// `{ default: makeWASocket, ... }` di dalamnya memakai stub ini.
//
// Dijalankan manual (bukan bagian test suite Laravel):
//   docker compose exec whatsapp-gateway node test-connect-lock-race.js
// Skrip ini menghapus dirinya sendiri dari image? TIDAK — file test biasa,
// dibiarkan di repo untuk verifikasi ulang di masa depan.

const path = require('path');
const os = require('os');
const fs = require('fs');

let makeWASocketCallCount = 0;

// Baileys' CJS-interop wrapper exposes `default` as a read-only property —
// tidak bisa di-assign langsung. Ganti seluruh entry di require.cache
// untuk path yang sudah di-resolve SEBELUM SessionManager (yang men-
// destructure `{ default: makeWASocket, ... }`) sempat me-require-nya.
const resolvedPath = require.resolve('@whiskeysockets/baileys');

const fakeExports = {
  default: function fakeMakeWASocket() {
    makeWASocketCallCount += 1;
    const { EventEmitter } = require('events');
    const ev = new EventEmitter();
    return {
      ev: { on: (...args) => ev.on(...args), emit: (...args) => ev.emit(...args) },
      user: null,
      authState: { creds: { registered: false } },
      sendMessage: async () => {},
    };
  },
  useMultiFileAuthState: async () => {
    // Simulasikan I/O nyata (readdir/mkdir) supaya ADA window `await` di
    // dalam connect() — persis kondisi yang membuat race aslinya mungkin.
    await new Promise((r) => setTimeout(r, 20));
    return { state: {}, saveCreds: async () => {} };
  },
  fetchLatestBaileysVersion: async () => {
    await new Promise((r) => setTimeout(r, 20));
    return { version: [2, 3000, 0] };
  },
  DisconnectReason: { loggedOut: 401, badSession: 500 },
};

require.cache[resolvedPath] = {
  id: resolvedPath,
  filename: resolvedPath,
  loaded: true,
  exports: fakeExports,
};

const { SessionManager } = require('./src/sessionManager');

async function main() {
  // AUTH_STATE_DIR di sessionManager.js dihitung dari __dirname FILE itu
  // sendiri (selalu /app/auth_state di container ini) — TIDAK bisa
  // diarahkan ke tmpDir tanpa mengubah source. `useMultiFileAuthState` di
  // atas sudah di-stub (tidak benar-benar menyentuh disk), jadi aman —
  // hanya folder kosong test-key/key-a/key-b/test-key-2 yang mungkin
  // tertinggal di /app/auth_state, dibersihkan di akhir skrip ini.
  const TEST_KEYS = ['test-key', 'key-a', 'key-b', 'test-key-2'];

  const logger = { info: () => {}, warn: () => {}, error: () => {} };
  const manager = new SessionManager(logger);

  console.log('--- Skenario 1: dua connect() bersamaan untuk key yang SAMA ---');
  makeWASocketCallCount = 0;
  const [a, b] = await Promise.all([manager.connect('test-key'), manager.connect('test-key')]);
  console.log('makeWASocket dipanggil:', makeWASocketCallCount, '(harus 1)');
  console.log('kedua promise resolve ke entry yang SAMA:', a === b);

  if (makeWASocketCallCount !== 1) {
    console.error('GAGAL: race masih terjadi — makeWASocket terpanggil lebih dari sekali untuk key yang sama.');
    process.exit(1);
  }
  if (a !== b) {
    console.error('GAGAL: kedua panggilan concurrent tidak mengembalikan entry yang sama.');
    process.exit(1);
  }

  console.log();
  console.log('--- Skenario 2: dua key BERBEDA bersamaan -> keduanya harus tetap connect ---');
  makeWASocketCallCount = 0;
  await Promise.all([manager.connect('key-a'), manager.connect('key-b')]);
  console.log('makeWASocket dipanggil:', makeWASocketCallCount, '(harus 2 — key berbeda tidak boleh saling menunggu)');
  if (makeWASocketCallCount !== 2) {
    console.error('GAGAL: key berbeda seharusnya tidak saling mengunci.');
    process.exit(1);
  }

  console.log();
  console.log('--- Skenario 3: connect() lagi SETELAH yang pertama selesai -> boleh membuat socket baru ---');
  makeWASocketCallCount = 0;
  await manager.connect('test-key-2');
  await manager.connect('test-key-2'); // sengaja berurutan, bukan concurrent
  console.log('makeWASocket dipanggil:', makeWASocketCallCount, '(harus 2 — lock cuma untuk yang BERSAMAAN, bukan permanen)');
  if (makeWASocketCallCount !== 2) {
    console.error('GAGAL: lock seharusnya lepas setelah connect() pertama selesai.');
    process.exit(1);
  }

  console.log();
  console.log('SEMUA SKENARIO LULUS — connect-lock bekerja sesuai desain.');

  const AUTH_STATE_DIR = path.join(__dirname, 'auth_state');
  for (const key of TEST_KEYS) {
    fs.rmSync(path.join(AUTH_STATE_DIR, key), { recursive: true, force: true });
  }
}

main().catch((err) => {
  console.error('ERROR:', err);
  process.exit(1);
});
