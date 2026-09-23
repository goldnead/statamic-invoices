<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Export;

use InvalidArgumentException;

/**
 * How a CSV is written, and only the choices a bookkeeping import actually offers.
 *
 * The default opens correctly in a German spreadsheet and imports into DATEV
 * Unternehmen online and Lexware Office: semicolon, decimal comma, UTF-8 with
 * a byte order mark (without it Excel reads the umlauts as two characters
 * each). Windows-1252 is there for the desktop programs that still expect it;
 * a character it cannot hold becomes a question mark rather than breaking the
 * file.
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

    public function encode(string $value): string
    {
        if ($this->encoding !== 'windows-1252') {
            return $value;
        }

        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);

        return $converted === false ? (string) mb_convert_encoding($value, 'Windows-1252', 'UTF-8') : $converted;
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
