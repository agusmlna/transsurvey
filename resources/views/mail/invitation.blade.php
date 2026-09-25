<!doctype html>
<html lang="id">

<body style="font-family:Arial,sans-serif;color:#263e46;line-height:1.8">
    <h2>{{ $reminder ? 'Pengingat pengisian survei' : 'Kami ingin mendengar pendapat Anda' }}</h2>
    <p>Yth. {{ $invitation->recipient_name }},</p>
    <p>Mohon kesediaannya untuk mengisi <strong>{{ $invitation->survey->title }}</strong>.</p>
    <p>{{ $invitation->survey->description }}</p>
    <p><a href="{{ $invitation->surveyUrl() }}"
            style="display:inline-block;padding:12px 20px;background:#c8102e;color:white;text-decoration:none;border-radius:6px">Isi
            survei</a></p>
    <p>Batas pengisian: {{ $invitation->survey->ends_at->format('d M Y') }} (WIB).</p>
    <p>Tautan ini khusus untuk Anda. Jika sudah mengisi, tidak perlu mengirim respons kembali.</p>
    <p>Terima kasih atas waktu dan masukan Anda.<br>Tim Client Experience</p>
</body>

</html>
