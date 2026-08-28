<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Keeps exported workforce data from being read back as spreadsheet formulas.
 *
 * Every export in this system carries text that somebody typed into the HR
 * screens — employee names, department names, biometric device labels, audit
 * log paths. Excel, LibreOffice and Google Sheets all treat a cell whose first
 * character is `=`, `+`, `-` or `@` as a formula rather than a value, so an
 * employee recorded as `=cmd|'/c calc'!A1` stops being a name the moment the
 * report is opened and becomes an instruction aimed at the reviewer's machine.
 * The person harmed is never the one who typed it: it is whoever opens the
 * attendance report afterwards.
 *
 * The two formats need different repairs, so both live here rather than being
 * half-remembered at each call site:
 *
 * - CSV has no type system. The only place to say "this is text" is the value
 *   itself, so a leading apostrophe is prepended — the convention every
 *   spreadsheet program understands.
 * - XLSX does have one, so the cell is written as an explicit string instead
 *   and the value is left exactly as it was recorded. No apostrophe is added
 *   there, because none is needed to make the cell inert.
 *
 * Numbers are deliberately exempt. Worked minutes and undertime are read as
 * numbers by whoever sorts or charts the export, and a negative total is a
 * legitimate `-` that must stay arithmetic rather than becoming text.
 */
final class SpreadsheetExport
{
    /**
     * Leading characters a spreadsheet reads as the start of an expression.
     * Tab and carriage return are included because both are stripped during
     * parsing, which promotes whatever follows them to the first position.
     *
     * @var array<int, string>
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Write one CSV row with every text cell neutralised.
     *
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    public static function writeCsvRow($handle, array $row): void
    {
        fputcsv($handle, array_map(self::escapeForCsv(...), $row));
    }

    /**
     * Write one XLSX row, typing text cells as strings so the leading
     * character is never parsed.
     *
     * @param  array<int, mixed>  $row
     */
    public static function writeXlsxRow(Worksheet $sheet, array $row, int $rowNumber): void
    {
        foreach (array_values($row) as $index => $value) {
            $cell = $sheet->getCell([$index + 1, $rowNumber]);

            if (self::triggersFormula($value)) {
                $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

                continue;
            }

            $cell->setValue($value);
        }
    }

    /** Prefix a risky value so the spreadsheet reads the whole cell as text. */
    public static function escapeForCsv(mixed $value): mixed
    {
        return self::triggersFormula($value) ? "'".$value : $value;
    }

    /**
     * Whether this value would be parsed as an expression. Anything that is
     * already a number — or a string a spreadsheet would read as one — is left
     * alone so totals stay arithmetic.
     */
    public static function triggersFormula(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return false;
        }

        return in_array($value[0], self::FORMULA_TRIGGERS, true);
    }
}
