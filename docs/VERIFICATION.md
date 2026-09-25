# Hasil verifikasi paket

Tanggal: 23 September 2026.

## Berhasil dijalankan

- Dependency terpasang melalui Composer, dengan Laravel **13.33.0** dan PHPUnit **12.5.35**. `composer.lock` disertakan untuk instalasi yang konsisten.
- `composer validate --no-check-publish` berhasil.
- PHP lint: **54 file PHP aplikasi/konfigurasi/test**, tanpa kesalahan sintaks.
- `node --check public/assets/app.js` berhasil.
- Kompilasi Blade dan lint hasil kompilasi: **82 file termasuk view dependency**, tanpa kesalahan sintaks.
- Setup lokal SQLite, pembuatan APP_KEY, migration, dan seed bank pertanyaan berhasil.
- DemoSeeder berhasil: 6 klien, 4 respons selesai, 1 draf, 1 undangan belum diisi.
- Cache route berhasil dibuat dan dibersihkan.
- Feature test: **13 tests, 86 assertions, semua lulus** menggunakan PHP 8.3.6 paket Ubuntu dan SQLite in-memory.

## Cakupan feature test

1. Redirect guest dan render halaman admin.
2. Rating 1/2/3 menolak komentar kosong/spasi.
3. Skor 3 dan 5 menghasilkan 4; follow-up dibuat; respons kedua ditolak.
4. Rating tinggi tidak memerlukan komentar atau follow-up.
5. Simpan draf, buka kembali, lalu submit.
6. Pilihan palsu, token tidak dikenal, dan survei ditutup ditolak.
7. Viewer tidak dapat mengedit atau mengirim email.
8. Distribusi ulang pasangan klien/survei tidak membuat undangan ganda.
9. Email belum aktif/data contoh tidak dapat dikirim.
10. Job email dengan Mail fake: kirim sekali dan ubah status.
11. Filter laporan dan sanitasi formula pada CSV.
12. Penyelesaian follow-up wajib catatan dan memeriksa versi edit.
13. Pertanyaan terkunci setelah distribusi; duplikasi membuat Draf tanpa undangan.

## Batas verifikasi

- SMTP menggunakan mock/fake, **tidak ada email sungguhan dikirim**. SMTP relay perusahaan, inbox, cron, dan worker produksi belum diuji langsung.
- MySQL tersedia pada konfigurasi dan migration; pengujian pada sesi ini menggunakan SQLite. Uji ulang di MySQL staging sebelum go-live, terutama akses bersamaan dan transaksi.
- Belum dilakukan load test atau deployment ke server kantor.
- Browser sesi ini memblokir akses ke alamat preview lokal (`ERR_BLOCKED_BY_CLIENT`); visual desktop/mobile belum diverifikasi melalui browser. Halaman telah dirender dalam feature test Laravel.
- Tidak ada migrasi akun/respons dari situs preview sebelumnya.

Gunakan `docs/UAT.md` untuk pengujian di lingkungan tujuan. ZIP tidak memuat vendor, .env aktif, password, database percobaan, log, atau cache hasil eksekusi.
