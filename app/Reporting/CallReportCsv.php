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
 *
 * CE-7 points 1, 2 and 4 live here, because all three are about a value's journey INTO
 * a file and apply to every value this class is handed. Point 3 (durations as seconds
 * or as a clock) is deliberately NOT here — it is a choice the user made on the filter
 * form about what a number MEANS, and this class is given finished strings and never
 * sees a filter. It shapes the row in CallExportRows instead.
 */
final class CallReportCsv
{
    /**
     * CE-7 point 2. Three bytes that tell Excel on Windows the file is UTF-8. Without
     * them it falls back to the machine's regional code page and an accented name comes
     * out as mojibake — which reads as our bug, in a file the client opens in front of
     * their own team.
     */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * CE-7 point 1. A cell starting with any of these is evaluated as a formula on open
     * — OWASP's list, including the whitespace characters, which are on it because a
     * spreadsheet trims them before deciding. **Live for us, not theoretical: every
     * phone number we hold starts with `+`.**
     */
    private const FORMULA_STARTERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /**
     * Stream the CSV as a file download. Streaming (not building a string in memory)
     * keeps a large export flat on RAM; the row generator writes straight to the
     * output buffer.
     *
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function download(string $filename, array $headings, array $rows): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($headings, $rows): void {
                $handle = fopen('php://output', 'wb');

                fwrite($handle, self::BOM);
                self::putRow($handle, $headings);

                foreach ($rows as $row) {
                    self::putRow($handle, $row);
                }

                fclose($handle);
            },
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * The same download, fed by a generator instead of a built array (call-export.md
     * CE-5a) — the Call Export walks 50,000 rows and must never hold them all. A third
     * method rather than a rewrite of download(): both report Pages hand it small
     * grouped results correctly, and changing them would be a refactor nobody asked for.
     * DRY is preserved where it matters — one class still knows how to write CSV.
     *
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    public static function stream(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($headings, $rows): void {
                $handle = fopen('php://output', 'wb');

                fwrite($handle, self::BOM);
                self::putRow($handle, $headings);

                foreach ($rows as $row) {
                    self::putRow($handle, $row);
                    // Push each row out as it is written: memory stays flat, and a
                    // steadily-flushing connection is what nginx tolerates far better
                    // than one long silent request (CE-5b).
                    flush();
                }

                fclose($handle);
            },
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * One row, every cell made safe to open first (CE-7).
     *
     * `escape: ''` is not decoration. PHP 8.4 deprecated leaving the parameter out, so
     * the old call emitted a deprecation notice **per row** — 50,000 lines in the log
     * for one export. Empty is also the correct value: it turns off PHP's non-standard
     * backslash escaping and leaves plain RFC-4180 CSV, which is what a spreadsheet
     * expects. It becomes the default in PHP 9.
     *
     * @param  resource  $handle
     * @param  array<int, string|int|float|null>  $row
     */
    private static function putRow($handle, array $row): void
    {
        fputcsv($handle, array_map(self::safeCell(...), $row), escape: '');
    }

    /**
     * CE-7 points 1 and 4, which are one guard: a leading TAB inside the quoted field.
     *
     * WHY a tab and not the more commonly cited leading apostrophe: the apostrophe is
     * Excel's convention for text TYPED into a cell, and it stays visible when the same
     * character arrives from a CSV — so every phone number in the file would read
     * `'+919876543210`. OWASP names the tab as the Excel-resistant form for exactly this
     * reason. It does not print, and it makes the cell text, which is also what stops a
     * twelve-digit number being rewritten as `9.19877E+11` (point 4).
     *
     * Applied by SHAPE, not by column, because this class does not know which column it
     * is writing. Deliberately narrow, so a duration or a call id stays a real number
     * the supervisor can total in a spreadsheet:
     *  - anything starting with a formula character — every `+` phone number;
     *  - a long run of digits, which Excel keeps to 15 significant figures and then
     *    rounds, silently changing a phone number;
     *  - digits with a MEANINGFUL leading zero, which Excel simply eats — a landline
     *    `0177…` becomes `177…`. A bare `0` is not that: it is a duration of zero
     *    seconds, and turning it into text would break the column it sits in.
     */
    private static function safeCell(string|int|float|null $value): string|int|float|null
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $risky = in_array($value[0], self::FORMULA_STARTERS, true)
            || (ctype_digit($value) && (strlen($value) >= 12 || ($value[0] === '0' && strlen($value) > 1)));

        return $risky ? "\t".$value : $value;
    }
}
