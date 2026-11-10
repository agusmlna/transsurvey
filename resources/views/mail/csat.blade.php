<!doctype html>
<html lang="id">

<body style="margin:0;padding:0;background:#ffffff">
    @php
        $surveyUrl = $invitation->surveyUrl();
        $name = $invitation->recipient_name ?: 'Bapak/Ibu';
        $ticket = $invitation->ticket_id ?: '-';

        $expiresId = $invitation->expires_at
            ? $invitation->expires_at->copy()->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y, H:i').' WIB'
            : 'Tanpa batas waktu';
        $expiresEn = $invitation->expires_at
            ? $invitation->expires_at->copy()->timezone('Asia/Jakarta')->locale('en')->translatedFormat('j F Y, H:i').' WIB'
            : 'No time limit';

        // Inggris tampil lebih dulu, lalu garis pemisah dan versi Indonesia.
        $sections = [
            'en' => [
                'greeting' => 'Dear '.$name.',',
                'intro' => [
                    'Thank you for contacting us. We would like to hear about your experience with the handling of ticket '.$ticket.'.',
                    'Please click the button below to share your rating. It only takes about a minute.',
                ],
                'button' => 'Start Satisfaction Survey',
                'labels' => [
                    'link' => 'Survey link',
                    'ticket' => 'Ticket',
                    'status' => 'Ticket status',
                    'channel' => 'Channel',
                    'valid' => 'Link valid until',
                ],
                'valid' => $expiresEn,
                'note' => 'This link is unique to you. Please do not share it with others. If you have already submitted the survey, no further action is required.',
                'sign' => ['Best regards,', 'Customer Care Team'],
            ],
            'id' => [
                'greeting' => 'Yth. Bapak/Ibu '.$name.',',
                'intro' => [
                    'Terima kasih telah menghubungi kami. Kami ingin mengetahui pengalaman Bapak/Ibu atas penanganan tiket '.$ticket.'.',
                    'Silakan klik tombol di bawah ini untuk memberikan penilaian. Pengisian hanya membutuhkan waktu sekitar satu menit.',
                ],
                'button' => 'Mulai Survei Kepuasan',
                'labels' => [
                    'link' => 'Link survei',
                    'ticket' => 'Tiket',
                    'status' => 'Status tiket',
                    'channel' => 'Channel',
                    'valid' => 'Tautan berlaku sampai',
                ],
                'valid' => $expiresId,
                'note' => 'Tautan ini khusus untuk Bapak/Ibu. Mohon tidak membagikannya kepada pihak lain. Jika survei sudah diisi, tidak diperlukan tindakan lebih lanjut.',
                'sign' => ['Hormat kami,', 'Tim Customer Care'],
            ],
        ];
    @endphp

    <div style="max-width:620px;margin:0 auto;padding:24px;font-family:Arial,sans-serif;font-size:15px;color:#263e46;line-height:1.7">
        @foreach (['en', 'id'] as $lang)
            @php($s = $sections[$lang])

            <p style="margin:0 0 14px">{{ $s['greeting'] }}</p>

            @foreach ($s['intro'] as $paragraph)
                <p style="margin:0 0 14px">{{ $paragraph }}</p>
            @endforeach

            <p style="margin:18px 0">
                <a href="{{ $surveyUrl }}" style="display:inline-block;padding:11px 22px;background:#c8102e;color:#ffffff;font-weight:bold;text-decoration:none;border-radius:6px">{{ $s['button'] }}</a>
            </p>

            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 14px;background:#f8fafc;border-radius:8px">
                <tr>
                    <td style="padding:8px 14px;color:#64748b;width:150px">{{ $s['labels']['ticket'] }}</td>
                    <td style="padding:8px 14px;font-weight:bold">{{ $ticket }}</td>
                </tr>
                @if (filled($invitation->ticket_status))
                    <tr>
                        <td style="padding:8px 14px;color:#64748b">{{ $s['labels']['status'] }}</td>
                        <td style="padding:8px 14px">{{ $invitation->ticket_status }}</td>
                    </tr>
                @endif
                @if (filled($invitation->channel))
                    <tr>
                        <td style="padding:8px 14px;color:#64748b">{{ $s['labels']['channel'] }}</td>
                        <td style="padding:8px 14px">{{ $invitation->channel }}</td>
                    </tr>
                @endif
            </table>

            <p style="margin:0 0 14px">
                {{ $s['labels']['link'] }}: <a href="{{ $surveyUrl }}" style="color:#c8102e;word-break:break-all">{{ $surveyUrl }}</a><br>
                {{ $s['labels']['valid'] }}: {{ $s['valid'] }}
            </p>

            <p style="margin:0 0 14px;font-style:italic;color:#475569">{{ $s['note'] }}</p>

            <p style="margin:0">{{ $s['sign'][0] }}<br>{{ $s['sign'][1] }}</p>

            @if ($lang === 'en')
                <hr style="border:0;border-top:1px solid #cbd5e1;margin:32px 0">
            @endif
        @endforeach
    </div>
</body>

</html>
