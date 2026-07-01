<?php

namespace App\Livewire;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Billing\BillingRunException;
use App\Services\Billing\BillingRunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class BillingRunIndex extends Component
{
    public string $tenantId = '';

    public string $period = '';

    public array $expandedLines = [];

    public function mount(): void
    {
        $this->authorizeInternalUser();
        $this->period = now('Asia/Tokyo')->subMonthNoOverflow()->format('Y-m');
    }

    public function updatedTenantId(): void
    {
        $this->expandedLines = [];
    }

    public function updatedPeriod(): void
    {
        $this->expandedLines = [];
    }

    public function generate(BillingRunService $service): void
    {
        $this->validate(['period' => ['required', 'date_format:Y-m']]);

        $tenant = $this->selectedTenant();

        try {
            $service->generate($tenant, $this->period);
            session()->flash('status', __('billing.invoice_generated'));
        } catch (BillingRunException $exception) {
            session()->flash('error', $exception->getMessage());
        }
    }

    public function finalize(BillingRunService $service): void
    {
        $invoice = $this->selectedInvoice();

        if (! $invoice instanceof Invoice) {
            return;
        }

        try {
            $service->finalize($invoice);
            session()->flash('status', __('billing.invoice_finalized'));
        } catch (BillingRunException $exception) {
            session()->flash('error', $exception->getMessage());
        }
    }

    public function toggleLine(int $lineId): void
    {
        if (isset($this->expandedLines[$lineId])) {
            unset($this->expandedLines[$lineId]);

            return;
        }

        $this->expandedLines[$lineId] = true;
    }

    public function exportCsv()
    {
        $invoice = $this->selectedInvoice();

        if (! $invoice instanceof Invoice) {
            return null;
        }

        $invoice->load('tenant', 'lines.sources');
        $filename = 'invoice_'.$invoice->tenant->code.'_'.$invoice->period.'.csv';

        return response()->streamDownload(function () use ($invoice): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Period', 'Tenant', 'Status', 'Currency', 'Total']);
            fputcsv($handle, [$invoice->period, $invoice->tenant->code, $invoice->status, $invoice->currency, $invoice->total]);
            fputcsv($handle, []);
            fputcsv($handle, ['Fee type', 'Unit', 'Quantity', 'Rate', 'Markup %', 'Cost base', 'Rate from', 'Rate to', 'Amount', 'Sources']);

            foreach ($invoice->lines as $line) {
                fputcsv($handle, [
                    $this->feeTypeLabel($line->fee_type),
                    $this->unitLabel($line->unit),
                    $line->quantity,
                    $line->rate,
                    $line->markup_pct,
                    $line->cost_base,
                    $line->rate_from === null ? null : CarbonImmutable::parse($line->rate_from)->format('Y-m-d'),
                    $line->rate_to === null ? null : CarbonImmutable::parse($line->rate_to)->format('Y-m-d'),
                    $line->amount,
                    $line->sources->count(),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        $invoice = $this->selectedInvoice();

        return view('livewire.billing-run-index', [
            'tenants' => Tenant::query()
                ->whereIn('id', $this->allowedTenantIds())
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'invoice' => $invoice?->load('tenant', 'lines.sources'),
            'warnings' => $invoice instanceof Invoice ? ($invoice->warnings ?? []) : [],
        ])->layout('inventory', [
            'title' => __('billing.billing_page_title'),
            'subtitle' => __('billing.billing_page_subtitle'),
            'pageWide' => true,
        ]);
    }

    public function feeTypeLabel(string $feeType): string
    {
        return __('billing.fee_types.'.$feeType);
    }

    public function unitLabel(string $unit): string
    {
        return __('billing.units.'.$unit);
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            Invoice::STATUS_FINALIZED => 'green',
            Invoice::STATUS_VOID => 'red',
            default => 'blue',
        };
    }

    private function selectedTenant(): Tenant
    {
        return Tenant::query()
            ->whereIn('id', $this->allowedTenantIds())
            ->findOrFail((int) $this->tenantId);
    }

    private function selectedInvoice(): ?Invoice
    {
        if ($this->tenantId === '' || $this->period === '') {
            return null;
        }

        return Invoice::query()
            ->whereIn('tenant_id', $this->allowedTenantIds())
            ->where('tenant_id', (int) $this->tenantId)
            ->where('period', $this->period)
            ->first();
    }

    private function authorizeInternalUser(): void
    {
        if (Auth::user()?->user_type !== 'internal') {
            abort(403);
        }
    }

    private function allowedTenantIds(): array
    {
        return Tenant::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
