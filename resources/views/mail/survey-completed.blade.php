<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Survei selesai</title></head>
<body style="margin:0;background:#f5f6f8;font-family:Arial,sans-serif;color:#263238;line-height:1.6">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 16px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;margin:auto;background:white;border:1px solid #e5e7eb;border-radius:12px">
<tr><td style="padding:24px 28px;border-bottom:3px solid #c8102e;font-size:22px;font-weight:bold">Trans<span style="color:#c8102e">Survey</span></td></tr>
<tr><td style="padding:28px">
<p style="margin-top:0">Halo {{ $adminName }},</p>
<h1 style="font-size:22px;margin:0 0 16px">Survei sudah selesai diisi</h1>
<p>PIC berikut sudah mengirimkan jawaban survei. Responsnya dapat dilihat di TransSurvey.</p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f9fafb;border-radius:8px">
@foreach ([
    'Klien' => $response->client->name,
    'PIC' => $response->invitation->recipient_name,
    'Email PIC' => $response->invitation->recipient_email,
    'Kuesioner' => $response->survey->title,
    'Selesai pada' => $response->submitted_at->copy()->timezone('Asia/Jakarta')->format('d M Y, H:i').' WIB',
] as $label => $value)
<tr><td style="padding:8px 16px;color:#64748b;vertical-align:top;width:110px">{{ $label }}</td><td style="padding:8px 16px;word-break:break-word">{{ $value }}</td></tr>
@endforeach
</table>
<p style="margin:24px 0"><a href="{{ route('responses.show', $response) }}" style="display:inline-block;background:#c8102e;color:white;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold">Lihat respons</a></p>
<p style="font-size:13px;color:#64748b">Masuk ke akun TransSurvey untuk melihat detail jawaban.</p>
</td></tr>
<tr><td style="padding:18px 28px;background:#f9fafb;font-size:12px;color:#64748b">Pemberitahuan ini dikirim kepada akun dengan role admin saat respons diterima.</td></tr>
</table></td></tr></table>
</body></html>
