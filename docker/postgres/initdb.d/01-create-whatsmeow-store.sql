-- Migrasi whatsmeow (branch migrasi-whatsmeow) — database logis TERPISAH dari
-- boss_db di dalam container boss-postgresql yang SAMA (keputusan Agung: bukan
-- tabel campur di boss_db, karena skema whatsmeow_store dikelola whatsmeow
-- sendiri lewat sqlstore.Container.Upgrade() — bukan migration Laravel, jadi
-- tidak boleh tercampur dengan migration tracking Laravel/tetap logically
-- separated sesuai semangat BOSS-009 walau satu instance Postgres fisik).
--
-- File ini HANYA dieksekusi otomatis oleh image postgres resmi saat data
-- directory pertama kali diinisialisasi (server baru/fresh volume) — lihat
-- BOSS-011. Untuk server YANG SUDAH BERJALAN (seperti dev VM ini), database
-- ini sudah dibuat manual sekali via:
--   docker compose exec boss-postgresql psql -U boss_app -d boss_db \
--     -c "CREATE DATABASE whatsmeow_store OWNER boss_app;"
-- — perintah di bawah ini murni supaya `git clone` + `docker compose up -d`
-- di server baru mereproduksi state yang sama tanpa langkah manual tambahan.
SELECT 'CREATE DATABASE whatsmeow_store OWNER ' || quote_ident(current_user)
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'whatsmeow_store')
\gexec
