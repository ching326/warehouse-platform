<?php

namespace App\Services\Amazon;

use App\Models\AmazonSpapiConnection;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AmazonSpapiOrdersClient
{
    public const MAX_PREVIEW_ORDERS = 500;

    public function __construct(
        private readonly AmazonSpapiTokenService $tokenService,
        private readonly AmazonRestrictedDataTokenService $rdtService,
    ) {}

    /**
     * @return array{orders: array<int,array<string,mixed>>, items: array<string,array<int,array<string,mixed>>>, capped: bool}
     */
    public function fetch(AmazonSpapiConnection $connection, string $windowType, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($connection->status !== AmazonSpapiConnection::STATUS_CONNECTED) {
            throw new AmazonSpapiApiException(__('amazon_spapi_import.connection_not_ready'));
        }

        $accessToken = $this->tokenService->exchangeRefreshToken($connection)->accessToken;
        $orders = $this->fetchOrders($connection, $accessToken, $windowType, $from, $to);
        $actionableOrders = array_values(array_filter(
            $orders['orders'],
            fn (array $order): bool => in_array((string) ($order['OrderStatus'] ?? ''), ['Unshipped', 'PartiallyShipped', 'Canceled', 'Cancelled'], true)
        ));

        if ($actionableOrders === []) {
            return [
                'orders' => $orders['orders'],
                'items' => [],
                'capped' => $orders['capped'],
            ];
        }

        $rdt = $this->restrictedToken($connection, $accessToken, $actionableOrders);
        $items = [];

        foreach ($actionableOrders as $order) {
            $amazonOrderId = (string) ($order['AmazonOrderId'] ?? '');
            if ($amazonOrderId === '') {
                continue;
            }

            $items[$amazonOrderId] = $this->fetchItems($connection, $rdt, $amazonOrderId);
        }

        return [
            'orders' => $orders['orders'],
            'items' => $items,
            'capped' => $orders['capped'],
        ];
    }

    /**
     * @return array{orders: array<int,array<string,mixed>>, capped: bool}
     */
    private function fetchOrders(AmazonSpapiConnection $connection, string $accessToken, string $windowType, CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = [];
        $nextToken = null;

        do {
            $params = $nextToken
                ? ['NextToken' => $nextToken]
                : $this->windowParams($connection, $windowType, $from, $to);

            $response = $this->getWithRetry(
                rtrim($connection->endpoint, '/').'/orders/v0/orders',
                $accessToken,
                $params,
            );

            if ($response->failed()) {
                throw new AmazonSpapiApiException($this->failureMessage($response->json(), $response->status()));
            }

            $payload = $response->json('payload') ?? [];
            foreach (($payload['Orders'] ?? []) as $order) {
                $orders[] = $order;

                if (count($orders) >= self::MAX_PREVIEW_ORDERS) {
                    return ['orders' => $orders, 'capped' => true];
                }
            }

            $nextToken = $payload['NextToken'] ?? null;
        } while ($nextToken);

        return ['orders' => $orders, 'capped' => false];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchItems(AmazonSpapiConnection $connection, string $rdt, string $amazonOrderId): array
    {
        $items = [];
        $nextToken = null;

        do {
            $params = $nextToken ? ['NextToken' => $nextToken] : [];
            $response = $this->getWithRetry(
                rtrim($connection->endpoint, '/').'/orders/v0/orders/'.rawurlencode($amazonOrderId).'/orderItems',
                $rdt,
                $params,
            );

            if ($response->failed()) {
                throw new AmazonSpapiApiException($this->failureMessage($response->json(), $response->status()));
            }

            $payload = $response->json('payload') ?? [];
            foreach (($payload['OrderItems'] ?? []) as $item) {
                $items[] = $item;
            }

            $nextToken = $payload['NextToken'] ?? null;
        } while ($nextToken);

        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     */
    private function restrictedToken(AmazonSpapiConnection $connection, string $accessToken, array $orders): string
    {
        $resources = [[
            'method' => 'GET',
            'path' => '/orders/v0/orders',
            'dataElements' => ['buyerInfo', 'shippingAddress'],
        ]];

        foreach ($orders as $order) {
            $amazonOrderId = (string) ($order['AmazonOrderId'] ?? '');
            if ($amazonOrderId !== '') {
                $resources[] = [
                    'method' => 'GET',
                    'path' => '/orders/v0/orders/'.$amazonOrderId.'/orderItems',
                    'dataElements' => ['buyerInfo', 'shippingAddress'],
                ];
            }
        }

        return $this->rdtService->create($connection, $accessToken, $resources);
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

    private function windowParams(AmazonSpapiConnection $connection, string $windowType, CarbonInterface $from, CarbonInterface $to): array
    {
        $prefix = $windowType === 'created' ? 'Created' : 'LastUpdated';

        return [
            'MarketplaceIds' => $connection->marketplace_id,
            $prefix.'After' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
            $prefix.'Before' => $to->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function failureMessage(mixed $payload, int $status): string
    {
        $payload = is_array($payload) ? $payload : [];
        $message = (string) ($payload['message'] ?? $payload['error_description'] ?? $payload['errors'][0]['message'] ?? __('amazon_spapi_import.api_error'));

        return Str::limit(preg_replace('/\s+/', ' ', $message) ?: __('amazon_spapi_import.api_error'), 500, '').' (HTTP '.$status.')';
    }
}
