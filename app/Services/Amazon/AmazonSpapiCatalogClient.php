<?php

namespace App\Services\Amazon;

use App\Models\AmazonSpapiConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AmazonSpapiCatalogClient
{
    public function __construct(
        private readonly AmazonSpapiTokenService $tokenService,
    ) {}

    public function getMainImageUrl(AmazonSpapiConnection $connection, string $asin, string $marketplaceId): ?string
    {
        if ($connection->status !== AmazonSpapiConnection::STATUS_CONNECTED) {
            throw new AmazonSpapiApiException(__('amazon_spapi_import.connection_not_ready'));
        }

        $accessToken = $this->tokenService->exchangeRefreshToken($connection)->accessToken;
        $response = $this->getWithRetry(
            rtrim($connection->endpoint, '/').'/catalog/2022-04-01/items/'.rawurlencode($asin),
            $accessToken,
            [
                'marketplaceIds' => $marketplaceId,
                'includedData' => 'images',
            ],
        );

        if ($response->failed()) {
            throw new AmazonSpapiApiException($this->failureMessage($response->json(), $response->status()));
        }

        return $this->mainImageUrl($response->json() ?? []);
    }

    private function getWithRetry(string $url, string $token, array $params): Response
    {
        $attempts = 0;

        do {
            $response = Http::withHeaders(['x-amz-access-token' => $token])
                ->acceptJson()
                ->get($url, $params);

            if ($response->status() !== 429) {
                return $response;
            }

            $attempts++;
            usleep(100000 * $attempts);
        } while ($attempts < 3);

        return $response;
    }

    private function mainImageUrl(array $payload): ?string
    {
        $images = [];

        foreach (($payload['images'] ?? $payload['payload']['images'] ?? []) as $imageSet) {
            foreach (($imageSet['images'] ?? []) as $image) {
                if (! is_array($image)) {
                    continue;
                }

                $link = trim((string) ($image['link'] ?? ''));

                if ($link === '') {
                    continue;
                }

                $images[] = [
                    'link' => $link,
                    'variant' => strtoupper((string) ($image['variant'] ?? '')),
                    'area' => ((int) ($image['width'] ?? 0)) * ((int) ($image['height'] ?? 0)),
                ];
            }
        }

        if ($images === []) {
            return null;
        }

        usort($images, fn (array $a, array $b) => [
            $b['variant'] === 'MAIN' ? 1 : 0,
            $b['area'],
        ] <=> [
            $a['variant'] === 'MAIN' ? 1 : 0,
            $a['area'],
        ]);

        return $images[0]['link'];
    }

    private function failureMessage(mixed $payload, int $status): string
    {
        $payload = is_array($payload) ? $payload : [];
        $message = (string) ($payload['message'] ?? $payload['error_description'] ?? $payload['errors'][0]['message'] ?? __('amazon_spapi_import.api_error'));

        return Str::limit(preg_replace('/\s+/', ' ', $message) ?: __('amazon_spapi_import.api_error'), 500, '').' (HTTP '.$status.')';
    }
}
