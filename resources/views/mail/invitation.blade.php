<!doctype html>
<html lang="id">

<body style="margin:0;padding:0;background:#ffffff">
    @php
        $kind = $emailKind ?? 'invitation';
        $surveyUrl = $invitation->surveyUrl();
        $code = $invitation->access_code;
        $name = $invitation->recipient_name;

        $deadlineId = $invitation->survey->ends_at->locale('id')->translatedFormat('j F Y');
        $deadlineEn = $invitation->survey->ends_at->locale('en')->translatedFormat('j F Y');

        // Isi email per bahasa. Urutan tampil: Indonesia penuh dulu, garis, lalu Inggris penuh.
        $sections = [
            'id' => [
                'greeting' => 'Yth. Bapak/Ibu '.$name.',',
                'labels'   => ['link' => 'Link survey', 'code' => 'Kode akses', 'deadline' => 'Batas waktu pengisian'],
                'deadline' => $deadlineId,
                'sign'     => ['Hormat kami,', 'Tim TCID'],
                'invitation' => [
                    'before' => [
                        'Kami mengundang Bapak/Ibu untuk berpartisipasi dalam Client Satisfaction Survey TCID 2026. Masukan Bapak/Ibu akan membantu kami memahami pengalaman kerja sama dan meningkatkan kualitas layanan kami.',
                        'Silakan klik tombol di bawah ini untuk memulai survey.',
                    ],
                    'button' => 'Mulai Survey',
                    'note'   => 'Tautan ini bersifat unik dan khusus untuk Bapak/Ibu. Mohon tidak membagikannya kepada pihak lain. Jika survey telah selesai diisi dan dikirimkan, tidak diperlukan tindakan lebih lanjut atau balasan atas email ini.',
                    'after'  => [
                        'Terima kasih atas waktu dan partisipasi Bapak/Ibu. Kami menantikan masukan yang diberikan.',
                    ],
                ],
                'not_started' => [
                    'before' => [
                        'Kami ingin mengingatkan Bapak/Ibu mengenai Client Satisfaction Survey TCID 2026 yang masih terbuka hingga '.$deadlineId.'. Kami sangat menghargai waktu dan masukan Bapak/Ibu.',
                        'Silakan klik tombol di bawah ini untuk memulai survey.',
                    ],
                    'button' => 'Mulai Survey',
                    'note'   => 'Tautan ini bersifat unik dan khusus untuk Bapak/Ibu. Mohon tidak membagikannya kepada pihak lain. Jika survey telah selesai diisi dan dikirimkan, mohon abaikan email pengingat ini. Tidak diperlukan balasan.',
                    'after'  => [
                        'Terima kasih atas waktu dan perhatian Bapak/Ibu.',
                    ],
                ],
                'save_draft' => [
                    'before' => [
                        'Kami melihat bahwa Bapak/Ibu telah memulai Client Satisfaction Survey TCID 2026, tetapi belum menyelesaikannya.',
                        'Silakan klik tombol di bawah ini untuk melanjutkan survey dan mengirimkannya sebelum '.$deadlineId.'.',
                    ],
                    'button' => 'Lanjutkan Survey',
                    'note'   => 'Tautan ini bersifat unik dan khusus untuk Bapak/Ibu. Mohon tidak membagikannya kepada pihak lain. Jika survey telah selesai diisi dan dikirimkan, mohon abaikan email pengingat ini. Tidak diperlukan balasan.',
                    'after'  => [
                        'Terima kasih atas waktu dan partisipasi Bapak/Ibu.',
                    ],
                ],
            ],
            'en' => [
                'greeting' => 'Dear '.$name.',',
                'labels'   => ['link' => 'Survey link', 'code' => 'Access code', 'deadline' => 'Submission deadline'],
                'deadline' => $deadlineEn,
                'sign'     => ['Best regards,', 'TCID Team'],
                'invitation' => [
                    'before' => [
                        'We invite you to participate in the TCID 2026 Client Satisfaction Survey. Your feedback will help us understand your experience and improve our services.',
                        'Please click the button below to start the survey.',
                    ],
                    'button' => 'Start Survey',
                    'note'   => 'This link is unique to you. Please do not share it with others. If you have already completed and submitted the survey, no further action or reply is required.',
                    'after'  => [
                        'Thank you for your time and participation. We look forward to receiving your feedback.',
                    ],
                ],
                'not_started' => [
                    'before' => [
                        'We would like to remind you about the TCID 2026 Client Satisfaction Survey, which remains open until '.$deadlineEn.'. Your feedback would be greatly appreciated.',
                        'Please click the button below to start the survey.',
                    ],
                    'button' => 'Start Survey',
                    'note'   => 'This link is unique to you. Please do not share it with others. If you have already completed and submitted the survey, please disregard this reminder. No reply is required.',
                    'after'  => [
                        'Thank you for your time and consideration.',
                    ],
                ],
                'save_draft' => [
                    'before' => [
                        'We noticed that you have started the TCID 2026 Client Satisfaction Survey but have not yet completed it.',
                        'Please click the button below to resume your survey and submit it by '.$deadlineEn.'.',
                    ],
                    'button' => 'Continue Survey',
                    'note'   => 'This link is unique to you. Please do not share it with others. If you have already completed and submitted the survey, please disregard this reminder. No reply is required.',
                    'after'  => [
                        'Thank you for your time and participation.',
                    ],
                ],
            ],
        ];
    @endphp

    <div style="max-width:620px;margin:0 auto;padding:24px;font-family:Arial,sans-serif;font-size:15px;color:#263e46;line-height:1.7">
        @foreach (['id', 'en'] as $lang)
            @php
                $s = $sections[$lang];
                $t = $s[$kind] ?? $s['invitation'];
            @endphp

            <p style="margin:0 0 14px">{{ $s['greeting'] }}</p>

            @foreach ($t['before'] as $paragraph)
                <p style="margin:0 0 14px">{{ $paragraph }}</p>
            @endforeach

            <p style="margin:18px 0">
                <a href="{{ $surveyUrl }}" style="display:inline-block;padding:11px 22px;background:#c8102e;color:#ffffff;font-weight:bold;text-decoration:none;border-radius:6px">{{ $t['button'] }}</a>
            </p>

            <p style="margin:0 0 14px">
                {{ $s['labels']['link'] }}: <a href="{{ $surveyUrl }}" style="color:#c8102e;word-break:break-all">{{ $surveyUrl }}</a>
            </p>

            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0;border:1px solid #fecdd3;border-radius:8px;background:#fff1f2">
                <tr>
                    <td style="padding:12px 18px;text-align:center">
                        <div style="font-size:12px;color:#9f1239">{{ $s['labels']['code'] }}</div>
                        <div style="font-family:Arial,sans-serif;font-size:20px;font-weight:bold;letter-spacing:3px;color:#c8102e">{{ $code }}</div>
                    </td>
                </tr>
            </table>

            <p style="margin:0 0 14px">
                {{ $s['labels']['deadline'] }}: {{ $s['deadline'] }}
            </p>

            <p style="margin:0 0 14px;font-style:italic;color:#475569">{{ $t['note'] }}</p>

            @foreach ($t['after'] as $paragraph)
                <p style="margin:0 0 14px">{{ $paragraph }}</p>
            @endforeach

            <p style="margin:0">{{ $s['sign'][0] }}<br>{{ $s['sign'][1] }}</p>

            @if ($lang === 'id')
                <hr style="border:0;border-top:1px solid #cbd5e1;margin:32px 0">
            @endif
        @endforeach
    </div>
</body>

</html>