<?php

declare(strict_types=1);

return [
    // v0.22.x (login terpadu) — pesan gagal login SAMA untuk jalur email
    // (staff) maupun nomor HP (Referrer), tidak membocorkan apakah
    // identitas terdaftar atau jalur mana yang dicoba.
    'failed' => 'Email/nomor HP atau password salah.',
    'password' => 'Kata sandi salah.',
    'throttle' => 'Terlalu banyak upaya masuk. Silakan coba lagi dalam :seconds detik.',
];
