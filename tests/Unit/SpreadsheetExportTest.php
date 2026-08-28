<?php

namespace Tests\Unit;

use App\Support\SpreadsheetExport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpreadsheetExportTest extends TestCase
{
    public static function formulaTriggers(): array
    {
        return [
            'equals' => ['=cmd|\'/c calc\'!A1'],
            'plus' => ['+1+1'],
            'at sign' => ['@SUM(A1:A9)'],
            'tab then equals' => ["\t=1+1"],
            'carriage return' => ["\r=1+1"],
            'minus on text' => ['-cmd'],
        ];
    }

    #[DataProvider('formulaTriggers')]
    public function test_a_cell_that_would_be_parsed_as_an_expression_is_quoted(string $value): void
    {
        $this->assertTrue(SpreadsheetExport::triggersFormula($value));
        $this->assertSame("'".$value, SpreadsheetExport::escapeForCsv($value));
    }

    /**
     * Worked minutes, undertime and negative totals are read as numbers by
     * whoever sorts or charts the export. Quoting them would turn every total
     * into text, so the guard has to leave them arithmetic.
     */
    public static function numericValues(): array
    {
        return [
            'negative total' => ['-5'],
            'positive integer string' => ['480'],
            'decimal' => ['7.5'],
            'integer' => [480],
            'float' => [7.5],
            'null' => [null],
        ];
    }

    #[DataProvider('numericValues')]
    public function test_numbers_are_left_arithmetic(mixed $value): void
    {
        $this->assertFalse(SpreadsheetExport::triggersFormula($value));
        $this->assertSame($value, SpreadsheetExport::escapeForCsv($value));
    }

    public function test_ordinary_text_is_untouched(): void
    {
        foreach (['Maria Santos', 'SYS-ADMIN-2026-0001', 'present', ''] as $value) {
            $this->assertFalse(SpreadsheetExport::triggersFormula($value));
            $this->assertSame($value, SpreadsheetExport::escapeForCsv($value));
        }
    }
}
