<?php

namespace App\Services\Amazon;

use App\Models\AmazonSpapiConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AmazonRestrictedDataTokenService
{
    /**
     * @param  array<int,array<string,mixed>>  $resources
     */
    public function create(AmazonSpapiConnection $connection, string $accessToken, array $resources): string
    {
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->post(rtrim($connection->endpoint, '/').'/tokens/2021-03-01/restrictedDataToken', [
                'restrictedResources' => $resources,
            ]);

        if ($response->failed()) {
            throw new AmazonSpapiApiException($this->failureMessage($response->json(), $response->status()));
        }

        $token = trim((string) ($response->json('restrictedDataToken') ?? ''));

        if ($token === '') {
            throw new AmazonSpapiApiException(__('amazon_spapi_import.pii_missing'));
        }

        return $token;
    }

    private function failureMessage(mixed $payload, int $status): string
    {
        $payload = is_array($payload) ? $payload : [];
        $message = (string) ($payload['message'] ?? $payload['error_description'] ?? __('amazon_spapi_import.pii_missing'));

        return Str::limit(preg_replace('/\s+/', ' ', $message) ?: __('amazon_spapi_import.pii_missing'), 500, '').' (HTTP '.$status.')';
    }
}
