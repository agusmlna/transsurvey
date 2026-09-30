<!doctype html>
<html lang="id">

<body style="font-family:Arial,sans-serif;color:#263e46;line-height:1.8">
    <h2>{{ $reminder ? 'Pengingat pengisian survei' : 'Kami ingin mendengar pendapat Anda' }}</h2>
    <p>Yth. {{ $invitation->recipient_name }},</p>
    <p>Mohon kesediaannya untuk mengisi <strong>{{ $invitation->survey->title }}</strong>.</p>
    <p>{{ $invitation->survey->description }}</p>
    <p>Untuk membuka survei, klik tombol di bawah lalu masukkan kode akses berikut:</p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0;border:1px solid #fecdd3;border-radius:8px;background:#fff1f2">
        <tr>
            <td style="padding:16px 24px;text-align:center">
                <div style="font-size:12px;color:#9f1239">Kode akses survei</div>
                <div style="font-family:Arial,sans-serif;font-size:30px;font-weight:bold;letter-spacing:6px;color:#c8102e">{{ $invitation->access_code }}</div>
            </td>
        </tr>
    </table>
    <p>Kode ini khusus untuk undangan survei Anda. Jangan bagikan tautan dan kode kepada orang lain.</p>
    <p><a href="{{ $invitation->surveyUrl() }}"
            style="display:inline-block;padding:12px 20px;background:#c8102e;color:white;text-decoration:none;border-radius:6px">Isi
            survei</a></p>
    <p>Batas pengisian: {{ $invitation->survey->ends_at->format('d M Y') }} (WIB).</p>
    <p>Tautan ini khusus untuk Anda. Jika sudah mengisi, tidak perlu mengirim respons kembali.</p>
    <p>Terima kasih atas waktu dan masukan Anda.<br>Tim Client Experience</p>
</body>

</html>
