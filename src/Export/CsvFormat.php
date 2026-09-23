<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Export;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * How a CSV is written, and only the choices a bookkeeping import actually offers.
 *
 * The default opens correctly in a German spreadsheet and imports into DATEV
 * Unternehmen online and Lexware Office: semicolon, decimal comma, UTF-8 with
 * a byte order mark (without it Excel reads the umlauts as two characters
 * each). Windows-1252 is there for the desktop programs that still expect it;
 * a character it cannot hold is spelt in Latin ("Łukasz" becomes "Lukasz")
 * rather than breaking the file.
 *
 * Anything else is refused. A pipe or a tab-separated file with a decimal comma
 * would import without an error and put every amount in the wrong column.
 */
final class CsvFormat
{
    public const DELIMITERS = [';', ',', 'tab'];

    public const ENCODINGS = ['utf-8-bom', 'utf-8', 'windows-1252'];

    public const DECIMALS = [',', '.'];

    private function __construct(
        public readonly string $delimiter,
        public readonly string $encoding,
        public readonly string $decimal,
    ) {}

    public static function default(): self
    {
        return new self(';', 'utf-8-bom', ',');
    }

    /**
     * @param  array<string, mixed>  $options  `delimiter`, `encoding`, `decimal`; missing ones keep the default
     */
    public static function fromOptions(array $options): self
    {
        $default = self::default();
        $pick = function (string $key, array $allowed, string $fallback) use ($options): string {
            $value = $options[$key] ?? null;

            if ($value === null || $value === '') {
                return $fallback;
            }

            $value = is_string($value) ? strtolower($value) : $value;

            if ($value === "\t") {
                $value = 'tab';
            }

            if (! in_array($value, $allowed, true)) {
                throw new InvalidArgumentException(__('invoices::exports.error_csv_option', [
                    'key' => $key,
                    'value' => is_scalar($value) ? (string) $value : get_debug_type($value),
                    'allowed' => implode(', ', $allowed),
                ]));
            }

            return $value;
        };

        $delimiter = $pick('delimiter', self::DELIMITERS, $default->delimiter);
        $decimal = $pick('decimal', self::DECIMALS, $default->decimal);

        if ($delimiter === ',' && $decimal === ',') {
            throw new InvalidArgumentException(__('invoices::exports.error_csv_comma'));
        }

        return new self($delimiter, $pick('encoding', self::ENCODINGS, $default->encoding), $decimal);
    }

    /** The character fputcsv() separates with. */
    public function separator(): string
    {
        return $this->delimiter === 'tab' ? "\t" : $this->delimiter;
    }

    /** Cents as the target reads them: 11900 is `119,00` or `119.00`, a credit note `-119,00`. */
    public function amount(int $cent): string
    {
        return number_format($cent / 100, 2, $this->decimal, '');
    }

    /** Basis points as a percentage: 1900 is `19,00`, 2550 is `25,50`. */
    public function rate(int $basisPoints): string
    {
        return number_format($basisPoints / 100, 2, $this->decimal, '');
    }

    /** What goes before the first row. */
    public function preamble(): string
    {
        return $this->encoding === 'utf-8-bom' ? "\xEF\xBB\xBF" : '';
    }

    /**
     * A text cell a spreadsheet will not run.
     *
     * Excel, LibreOffice and Google Sheets read a cell that starts with `=`,
     * `+`, `-` or `@` (and a tab or carriage return in front of one) as a
     * formula. A buyer who calls themselves `=HYPERLINK(...)` would otherwise
     * put a working link, or worse, into the bookkeeper's sheet. A leading
     * apostrophe makes the cell text, which is the mitigation OWASP names for
     * CSV injection. Only for text columns: an amount of `-19,00` has to stay a
     * number.
     */
    public function text(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$value
            : $value;
    }

    /**
     * The value in the file's encoding.
     *
     * For Windows-1252 without iconv's `//TRANSLIT`, whose result depends on
     * the process locale: under `C` it turns "Łukasz" into "?ukasz". Characters
     * the code page has (ä, ó, €) stay as they are; everything else is spelt in
     * Latin first, through ICU where the intl extension is there and through
     * Laravel's own table where it is not. Both are independent of the locale.
     */
    public function encode(string $value): string
    {
        if ($this->encoding !== 'windows-1252') {
            return $value;
        }

        $latin = (string) preg_replace_callback(
            '/[^\x{0000}-\x{007F}\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
            .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2013}\x{2014}'
            .'\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]/u',
            fn (array $m) => self::latin($m[0]),
            $value,
        );

        return (string) mb_convert_encoding($latin, 'Windows-1252', 'UTF-8');
    }

    private static function latin(string $character): string
    {
        static $transliterator = null;

        if ($transliterator === null && class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII') ?: false;
        }

        $spelt = $transliterator ? $transliterator->transliterate($character) : false;

        if (! is_string($spelt) || $spelt === '') {
            $spelt = Str::ascii($character);
        }

        return $spelt !== '' ? $spelt : '?';
    }

    /**
     * One row, encoded and terminated with CRLF, which is what both DATEV and
     * Excel expect.
     *
     * @param  resource  $stream
     * @param  list<string|int|null>  $row
     */
    public function put($stream, array $row): void
    {
        fputcsv(
            $stream,
            array_map(fn ($value) => $this->encode((string) $value), $row),
            $this->separator(),
            '"',
            '',
            "\r\n",
        );
    }
}
