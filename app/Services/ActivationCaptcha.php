<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ActivationCaptcha
{
    public function verify(Request $request): void
    {
        if (! config('services.recaptcha.enabled')) return;
        $request->validate(['g-recaptcha-response' => 'required|string|max:4096'], [
            'g-recaptcha-response.required' => 'Please complete the Google verification.',
        ]);
        $valid = false;
        if (config('services.recaptcha.site_key') && config('services.recaptcha.secret_key')) {
            try {
                $response = Http::asForm()->timeout(10)->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => config('services.recaptcha.secret_key'),
                    'response' => $request->input('g-recaptcha-response'),
                    'remoteip' => $request->ip(),
                ]);
                $valid = $response->successful() && $response->json('success') === true && empty($response->json('error-codes'));
            } catch (\Illuminate\Http\Client\ConnectionException $exception) {
                // Keep the account inactive if verification cannot be completed.
            }
        }
        if (! $valid) throw ValidationException::withMessages(['g-recaptcha-response' => 'Google verification could not be completed. Please try again.']);
    }
}
