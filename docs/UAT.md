# Uji penerimaan pengguna

Lakukan dengan data UAT sebelum membagikan survei kepada klien.

| Langkah | Hasil yang diharapkan |
|---|---|
| Login salah berulang kali | Ditolak, lalu terkena pembatasan sementara |
| Login admin yang dibuat dari CLI | Dashboard tampil |
| Buat survei berisi rating wajib, rating opsional, pilihan, dan teks | Semua jenis dapat diedit/disimpan |
| Ambil pertanyaan dari bank, ubah urutan | Isi dan urutan tersimpan sesuai editor |
| Simpan Draf, buka pratinjau | Tidak menyimpan respons ke database |
| Publikasi, tambah klien, buat undangan | Tautan unik tersedia; pengulangan tidak membuat undangan duplikat |
| Isi rating 1, 2, atau 3 tanpa komentar | Ditolak, termasuk jika komentar hanya spasi |
| Pilih nilai rendah pada pertanyaan opsional | Komentar tetap wajib saat submit |
| Simpan draf belum lengkap | Tersimpan; belum menjadi respons dan belum masuk skor |
| Buka ulang tautan draf | Jawaban draf dipulihkan |
| Kirim rating 3 dan 5 dengan komentar valid | Skor 4/5 atau 80%, satu tindak lanjut otomatis |
| Kirim ulang tautan sama | Tidak membuat respons baru |
| Coba survei belum dimulai/sudah berakhir/ditutup | Tidak dapat mengirim respons |
| Ubah pertanyaan setelah undangan ada | Pertanyaan terkunci; duplikasi tersedia |
| Buka laporan berdasarkan klien/periode | Data sesuai filter; source Operasional mengecualikan contoh |
| Ekspor CSV berisi karakter Indonesia dan awalan = | Terbuka di Excel, teks tidak dieksekusi menjadi formula |
| Cetak laporan PDF | Ringkasan dan daftar terlihat; navigasi disembunyikan |
| Kelola tindak lanjut | PIC, due date, status tersimpan; catatan wajib saat selesai |
| Buka editor sama pada dua tab dan simpan berurutan | Perubahan lama ditolak, minta muat ulang |
| Login viewer | Dapat membaca laporan; tidak dapat mengedit atau mengirim email |
| SMTP belum aktif | Tidak ada email keluar |
| SMTP UAT aktif, worker dan scheduler hidup | Email diterima mailbox uji; status berubah dan reminder terjadwal |
| Respons selesai sebelum reminder | Reminder tidak dikirim |
| Survei/data contoh | Email tetap diblokir |
| Buka di mobile | Form rating, navigasi, dan tabel tetap dapat dipakai |

Setiap pengiriman SMTP UAT merupakan aksi email sungguhan di lingkungan Anda. Gunakan penerima uji internal yang sudah ditentukan, lalu periksa relay/inbox sebelum pemakaian eksternal.
