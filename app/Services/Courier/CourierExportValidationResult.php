<?php

namespace App\Services\Courier;

class CourierExportValidationResult
{
    public function __construct(
        public bool $ok,
        public bool $requiresConfirmation,
        public array $validOrderIds,
        public array $missingOrderIds,
        public array $blockedStatusOrderIds,
        public array $wrongCarrierOrderIds,
        public array $unsupportedCourierOrderIds,
        public array $mixedTenantOrderIds,
        public array $alreadyExportedOrderIds,
        public array $noReadyLinesOrderIds,
        public string $message,
    ) {}

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'requires_confirmation' => $this->requiresConfirmation,
            'valid_order_ids' => $this->validOrderIds,
            'missing_order_ids' => $this->missingOrderIds,
            'blocked_status_order_ids' => $this->blockedStatusOrderIds,
            'wrong_carrier_order_ids' => $this->wrongCarrierOrderIds,
            'unsupported_courier_order_ids' => $this->unsupportedCourierOrderIds,
            'mixed_tenant_order_ids' => $this->mixedTenantOrderIds,
            'already_exported_order_ids' => $this->alreadyExportedOrderIds,
            'no_ready_lines_order_ids' => $this->noReadyLinesOrderIds,
            'message' => $this->message,
        ];
    }

    public function hasHardBlock(): bool
    {
        return $this->missingOrderIds !== []
            || $this->blockedStatusOrderIds !== []
            || $this->wrongCarrierOrderIds !== []
            || $this->unsupportedCourierOrderIds !== []
            || $this->mixedTenantOrderIds !== []
            || $this->noReadyLinesOrderIds !== [];
    }
}
