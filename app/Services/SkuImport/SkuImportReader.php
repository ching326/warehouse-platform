<?php

namespace App\Services\SkuImport;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

class SkuImportReader
{
    /**
     * Read a CSV, XLSX, or XLS file and return headers, rows, and total row count.
     *
     * @return array{headers: list<string>, rows: list<list<mixed>>, total: int}
     */
    public function read(string $path, ?int $limit = null): array
    {
        $sheets = Excel::toArray(new class implements ToArray
        {
            public function array(array $array): void {}
        }, $path);

        $sheet = $sheets[0] ?? [];

        if (count($sheet) < 1) {
            return ['headers' => [], 'rows' => [], 'total' => 0];
        }

        $headers = array_map(fn ($v) => trim((string) $v), $sheet[0]);

        $rows = [];
        $total = 0;

        foreach ($sheet as $index => $line) {
            if ($index === 0) {
                continue;
            }

            if (collect($line)->every(fn ($v) => trim((string) $v) === '')) {
                continue;
            }

            $total++;

            if ($limit !== null && count($rows) >= $limit) {
                continue;
            }

            $raw = array_slice(array_pad((array) $line, count($headers), ''), 0, count($headers));
            $rows[] = array_values(array_map(fn ($v) => $v === null ? '' : $v, $raw));
        }

        return ['headers' => $headers, 'rows' => $rows, 'total' => $total];
    }
}
