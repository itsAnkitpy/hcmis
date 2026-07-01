<?php

declare(strict_types=1);

namespace App\Reporting;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a report's rows into a CSV download (RP-5). Deliberately plain comma-separated
 * text — Excel opens it directly; a styled XLSX builder would be the one thing tempting
 * a new dependency, so it is a later nicety (KISS). Shared by both report Pages so the
 * "same numbers as the on-screen table" guarantee lives in one tested place: the Page
 * passes the SAME service output it renders, in the same column order.
 */
final class CallReportCsv
{
    /**
     * Stream the CSV as a file download. Streaming (not building a string in memory)
     * keeps a large export flat on RAM; the row generator writes straight to the
     * output buffer.
     *
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string|int|float>>  $rows
     */
    public static function download(string $filename, array $headings, array $rows): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($headings, $rows): void {
                $handle = fopen('php://output', 'wb');

                fputcsv($handle, $headings);

                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }

                fclose($handle);
            },
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * The same CSV as a string — the seam the export test asserts against, so the
     * "CSV matches the table" check does not have to drive a streamed response.
     *
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string|int|float>>  $rows
     */
    public static function toString(array $headings, array $rows): string
    {
        $handle = fopen('php://temp', 'r+b');

        fputcsv($handle, $headings);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }
}
