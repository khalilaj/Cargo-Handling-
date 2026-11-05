<?php

namespace App\Domain\Reporting;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams rows as CSV. Cells that a spreadsheet would run as a formula are neutralised. */
class CsvExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int|string, scalar|null>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");                    // BOM so Excel reads UTF-8
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'safe'], array_values($row)));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** "=SUM(..)", "+1", "-1", "@x" and leading tab/CR are prefixed with an apostrophe (OWASP CSV injection). */
    public static function safe(mixed $cell): mixed
    {
        if (is_string($cell) && $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$cell;
        }

        return $cell;
    }
}
