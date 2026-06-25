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
        if ($this->isExcelFile($path)) {
            return $this->readExcel($path, $limit);
        }

        return $this->readCsv($path, $limit);
    }

    private function isExcelFile(string $path): bool
    {
        $magic = file_get_contents($path, false, null, 0, 4);

        // XLSX = ZIP (PK header), XLS = OLE2 compound document
        return $magic === "PK\x03\x04" || $magic === "\xD0\xCF\x11\xE0";
    }

    private function readCsv(string $path, ?int $limit): array
    {
        $content = file_get_contents($path);

        // Strip UTF-8 BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        // If not valid UTF-8, assume Shift-JIS (common for Japanese CSV exports)
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'SJIS-WIN');
        }

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, $content);
        rewind($handle);

        $sheet = [];
        while (($row = fgetcsv($handle)) !== false) {
            $sheet[] = $row;
        }
        fclose($handle);

        return $this->processSheet($sheet, $limit);
    }

    private function readExcel(string $path, ?int $limit): array
    {
        $sheets = Excel::toArray(new class implements ToArray
        {
            public function array(array $array): void {}
        }, $path);

        return $this->processSheet($sheets[0] ?? [], $limit);
    }

    private function processSheet(array $sheet, ?int $limit): array
    {
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
