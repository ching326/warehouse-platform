<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class LocaleController extends Controller
{
    public function __invoke(string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('app.available_locales', ['en']), true), 404);

        session(['locale' => $locale]);

        return redirect()->back();
    }
}
