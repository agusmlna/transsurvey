<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class LanguageController
{
    public function update(Request $request): Response
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(['id', 'en'])],
            'return_to' => ['nullable', 'string', 'max:4096'],
        ]);
        $request->session()->put('locale', $data['locale']);
        // Keep validation errors, old input, and notices for the return page.
        $request->session()->reflash();
        $path = $data['return_to'] ?? '/';
        // Only local absolute paths. Reject protocol-relative URLs, control
        // characters and encoded backslashes/slashes that could redirect off-site.
        $decoded = rawurldecode($path);
        if (!str_starts_with($path, '/') || !str_starts_with($decoded, '/')
            || str_starts_with($decoded, '//') || preg_match('/[\\\\\x00-\x1f\x7f]/', $decoded)) {
            $path = '/';
        }
        return redirect()->to($path, 303)->withCookie(cookie(
            'transsurvey_locale', $data['locale'], 525600, '/', null,
            $request->isSecure(), true, false, 'lax'
        ));
    }
}
