<?php

namespace App\Services\MarketplaceShippingNotice;

class MarketplaceShippingNoticeValidationResult
{
    public function __construct(
        public bool $ok,
        public bool $requiresConfirmation,
        public bool $noSelection,
        public array $validOrderIds,
        public array $missingOrderIds,
        public array $mixedTenantOrderIds,
        public array $mixedPlatformOrderIds,
        public array $wrongPlatformOrderIds,
        public array $blockedStatusOrderIds,
        public array $missingPlatformOrderIds,
        public array $missingShippingMethodOrderIds,
        public array $missingTrackingOrderIds,
        public array $missingMappingOrderIds,
        public array $missingCarrierCodeOrderIds,
        public array $noReadyLinesOrderIds,
        public array $alreadyExportedOrderIds,
        public string $message,
    ) {}

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'requires_confirmation' => $this->requiresConfirmation,
            'no_selection' => $this->noSelection,
            'valid_order_ids' => $this->validOrderIds,
            'missing_order_ids' => $this->missingOrderIds,
            'mixed_tenant_order_ids' => $this->mixedTenantOrderIds,
            'mixed_platform_order_ids' => $this->mixedPlatformOrderIds,
            'wrong_platform_order_ids' => $this->wrongPlatformOrderIds,
            'blocked_status_order_ids' => $this->blockedStatusOrderIds,
            'missing_platform_order_ids' => $this->missingPlatformOrderIds,
            'missing_shipping_method_order_ids' => $this->missingShippingMethodOrderIds,
            'missing_tracking_order_ids' => $this->missingTrackingOrderIds,
            'missing_mapping_order_ids' => $this->missingMappingOrderIds,
            'missing_carrier_code_order_ids' => $this->missingCarrierCodeOrderIds,
            'no_ready_lines_order_ids' => $this->noReadyLinesOrderIds,
            'already_exported_order_ids' => $this->alreadyExportedOrderIds,
            'message' => $this->message,
        ];
    }

    public function hasHardBlock(): bool
    {
        return $this->noSelection
            || $this->missingOrderIds !== []
            || $this->mixedTenantOrderIds !== []
            || $this->mixedPlatformOrderIds !== []
            || $this->wrongPlatformOrderIds !== []
            || $this->blockedStatusOrderIds !== []
            || $this->missingPlatformOrderIds !== []
            || $this->missingShippingMethodOrderIds !== []
            || $this->missingTrackingOrderIds !== []
            || $this->missingMappingOrderIds !== []
            || $this->missingCarrierCodeOrderIds !== []
            || $this->noReadyLinesOrderIds !== [];
    }
}
