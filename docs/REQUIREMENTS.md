# Pemetaan requirement

Dasar: dua gambar requirement Web Based Client Satisfaction yang diberikan pengguna.

| Requirement | Implementasi |
|---|---|
| Create/edit questionnaire | Menu Kuesioner, status Draf/Aktif/Ditutup |
| Multiple choice, rating, open-ended | Pilihan tunggal dari daftar, rating 1–5, teks |
| Required/optional | Pengaturan per pertanyaan, validasi server |
| Arrange and categorize | Tombol naik/turun, kategori per pertanyaan |
| Question bank | Bank Pertanyaan dan pemilihan dari editor |
| Templates | Tandai kuesioner sebagai template, gunakan Duplikat |
| Preview before publishing | Simpan Draf → Pratinjau tersimpan |
| Comment mandatory for 1/2/3 | Validasi browser dan server, termasuk rating opsional yang diisi rendah |
| Client information | Klien, PIC, email, proyek, status aktif |
| Assign questionnaire, unique link | Undangan personal, token acak 64 karakter, satu per klien/survei |
| Email or other channels | SMTP antrean atau salin tautan untuk dibagikan manual |
| Start/end date | Periode survei diperiksa saat membuka dan menyimpan jawaban |
| Completed/pending tracking | Distribusi: belum mengisi/draf/selesai |
| Automatic reminders | Scheduler + queue + SMTP, perlu konfigurasi server |
| Central response storage and timestamp | Database dan submitted_at; draf pada undangan |
| Additional feedback | Pertanyaan teks dan komentar penilaian |
| Overall/category satisfaction | Kalkulasi otomatis, rating dan persentase |
| Dashboard/trends/rate | Dashboard SVG/CSS, metrik respons dan response rate |
| Scores per client and high/low category | Laporan per klien, kategori diurutkan, sorotan kategori terendah |
| Project/client filters | Filter klien/proyek/survei/periode/sumber |
| Summary/detailed report | Ringkasan dan daftar respons, detail jawaban, CSV |
| PDF/Excel export | Print-to-PDF browser dan CSV kompatibel Excel; bukan XLSX native |
| Low-score follow-up | Dibuat otomatis ketika salah satu rating ≤3 |
| Person responsible/due date/status | Admin menetapkan user, tenggat, catatan dan status |

## Batas versi

- Laporan dihitung saat dibuka/diekspor; belum ada pengiriman laporan terjadwal.
- Pengingat otomatis mengharuskan email awal berhasil dikirim melalui aplikasi.
- Belum ada SSO, integrasi EMS/Transdesk, verifikasi email responden, lampiran jawaban, bobot pertanyaan, atau multiple-choice multi-select.
- User admin/viewer dibuat melalui CLI, bukan halaman pengelolaan user.
- Pengujian SMTP live dan deployment produksi harus dilakukan di lingkungan kantor.
