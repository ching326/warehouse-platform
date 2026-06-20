<?php

namespace App\Services\Amazon;

use App\Models\AmazonSpapiConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class AmazonSpapiTokenService
{
    private const TOKEN_URL = 'https://api.amazon.com/auth/o2/token';

    public function exchangeRefreshToken(AmazonSpapiConnection $connection): AmazonAccessTokenResult
    {
        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'client_id' => $connection->lwa_client_id,
                'client_secret' => $connection->lwa_client_secret,
            ]);
        } catch (Throwable $exception) {
            throw new AmazonSpapiTokenException($this->sanitizeMessage($exception->getMessage()), previous: $exception);
        }

        if ($response->failed()) {
            throw new AmazonSpapiTokenException($this->failureMessage($response->json(), $response->status()));
        }

        $payload = $response->json();
        $accessToken = trim((string) ($payload['access_token'] ?? ''));

        if ($accessToken === '') {
            throw new AmazonSpapiTokenException('missing_access_token: Token response did not include an access token.');
        }

        return new AmazonAccessTokenResult(
            accessToken: $accessToken,
            expiresIn: (int) ($payload['expires_in'] ?? 0),
            tokenType: (string) ($payload['token_type'] ?? 'bearer'),
        );
    }

    private function failureMessage(mixed $payload, int $status): string
    {
        $payload = is_array($payload) ? $payload : [];
        $error = trim((string) ($payload['error'] ?? 'token_exchange_failed'));
        $description = trim((string) ($payload['error_description'] ?? 'Amazon LWA token exchange failed.'));

        return $this->sanitizeMessage($error.': '.$description.' (HTTP '.$status.')');
    }

    private function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?: 'Amazon LWA token exchange failed.';

        return Str::limit($message, 500, '');
    }
}
