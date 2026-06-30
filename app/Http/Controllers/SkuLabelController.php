<?php

namespace App\Http\Controllers;

use App\Livewire\SkuLabelPrint;
use App\Models\Sku;
use App\Models\Tenant;
use App\Services\Labels\SkuLabelContentResolver;
use App\Services\Labels\SkuLabelPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class SkuLabelController extends Controller
{
    public function download(SkuLabelContentResolver $resolver, SkuLabelPdfService $pdfService): Response|RedirectResponse
    {
        $payload = session()->pull(SkuLabelPrint::SESSION_KEY);

        if (! is_array($payload)) {
            return redirect()
                ->route('skus.index')
                ->with('error', __('skus.label_session_expired'));
        }

        $this->authorizeInternalUser();

        $labels = [];
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        $skuCodes = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                abort(404);
            }

            $sku = Sku::query()
                ->whereIn('tenant_id', $this->allowedTenantIds())
                ->with('stockItem')
                ->findOrFail((int) ($entry['sku_id'] ?? 0));

            $content = (string) ($entry['content'] ?? '');
            $qty = (int) ($entry['qty'] ?? 0);
            $value = trim((string) ($entry['value'] ?? ''));
            $availableValue = $resolver->resolveValue($sku, $content);

            if ($qty < 1 || $value === '' || $availableValue === null) {
                abort(404);
            }

            $skuCodes[] = (string) $sku->sku;

            for ($i = 0; $i < $qty; $i++) {
                $labels[] = [
                    'value' => $value,
                    'code_text' => $value,
                    'name' => trim((string) ($entry['name'] ?? '')),
                ];
            }
        }

        if ($labels === []) {
            abort(404);
        }

        $pdf = $pdfService->render(
            (string) ($payload['layoutKey'] ?? ''),
            $labels,
            is_array($payload['skipCells'] ?? null) ? $payload['skipCells'] : [],
        );

        $filename = $this->filename($skuCodes);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function authorizeInternalUser(): void
    {
        if (Auth::user()?->user_type !== 'internal') {
            abort(403);
        }
    }

    /**
     * @return list<int>
     */
    private function allowedTenantIds(): array
    {
        if (Auth::user()?->user_type === 'internal') {
            return Tenant::query()->pluck('id')->all();
        }

        return Auth::user()?->activeTenantIds() ?? [];
    }

    /**
     * @param  list<string>  $skuCodes
     */
    private function filename(array $skuCodes): string
    {
        $date = now('Asia/Tokyo')->format('Ymd');

        if (count(array_unique($skuCodes)) === 1) {
            $sku = preg_replace('/[^A-Za-z0-9._-]+/', '-', $skuCodes[0]) ?: 'sku';

            return 'sku-labels-'.$sku.'-'.$date.'.pdf';
        }

        return 'sku-labels-'.$date.'.pdf';
    }
}
