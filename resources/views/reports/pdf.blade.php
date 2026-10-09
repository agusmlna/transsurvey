<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <title>{{ __('Laporan kepuasan klien - TransSurvey') }}</title>
    <style>
        {!! file_get_contents(public_path('assets/transsurvey-reports.css')) !!} @page {
            margin: 32px 36px 52px;
        }

        body {
            margin: 0;
        }

        .rs-report {
            font-family: DejaVu Sans, sans-serif;
            border: 0;
            padding: 0;
            margin: 0;
            font-size: 10px;
        }

        .rs-report h1 {
            font-size: 24px;
        }

        .rs-report .rs-table th,
        .rs-report .rs-table td {
            font-size: 9px;
            padding: 8px;
        }

        .rs-report .rs-meta th,
        .rs-report .rs-meta td {
            font-size: 10px;
        }

        .rs-report .rs-section-title {
            margin-top: 18px;
        }

        .rs-report .rs-metrics strong {
            font-size: 24px;
        }

        .rs-report .rs-brand-table {
            margin-bottom: 0;
        }

        .rs-report .rs-brand-table td {
            padding-bottom: 8px;
        }

        .rs-report .rs-title {
            padding-top: 12px;
            margin-bottom: 12px;
        }

        .rs-report .rs-meta {
            margin-bottom: 12px;
        }

        .rs-report .rs-meta th {
            width: 25%;
        }

        .rs-report .rs-meta td {
            width: 75%;
        }

        .rs-report .rs-meta th,
        .rs-report .rs-meta td {
            padding: 2px 0;
        }

        .rs-report .rs-metrics {
            margin-bottom: 14px;
        }

        .rs-report .rs-metrics td {
            padding: 9px 8px;
        }

        .rs-report .rs-summary {
            padding: 12px;
            margin-bottom: 14px;
        }

        .rs-report .rs-section-title {
            margin-top: 14px;
        }

        .rs-report .rs-method {
            margin-top: 16px;
            padding-top: 10px;
            font-size: 8px;
            page-break-inside: avoid;
        }
    </style>
</head>

<body>@include('reports.document')</body>

</html>
