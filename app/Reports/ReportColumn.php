<?php

namespace App\Reports;

use Closure;

/**
 * One column of a report, defined once and rendered by every surface.
 *
 * The attendance report used to describe its columns three times: a header
 * array for CSV and XLSX, a hand-written `<thead>` in the screen view, and a
 * second, shorter hand-written one in the PDF view. They drifted, which is how
 * the approval column came to exist on screen and in neither export. A column
 * declared here carries its own heading and its own value, so a surface can
 * choose whether to render a column but not what it is called or what it holds.
 */
final class ReportColumn
{
    /**
     * @param  Closure(object): mixed  $value
     */
    private function __construct(
        public readonly string $label,
        public readonly Closure $value,
        public readonly bool $numeric,
        public readonly bool $compact,
    ) {}

    /**
     * @param  Closure(object): mixed  $value
     */
    public static function make(string $label, Closure $value): self
    {
        return new self($label, $value, numeric: false, compact: true);
    }

    /** Right-align this column and keep it arithmetic in spreadsheets. */
    public function numeric(): self
    {
        return new self($this->label, $this->value, numeric: true, compact: $this->compact);
    }

    /**
     * Drop this column from the compact layout. A PDF is a fixed-width page,
     * not a scrollable table: past roughly ten columns landscape A4 stops
     * wrapping and starts overlapping, so the wide reports mark their least
     * load-bearing columns here rather than shipping an unreadable page.
     */
    public function wide(): self
    {
        return new self($this->label, $this->value, numeric: $this->numeric, compact: false);
    }

    public function resolve(object $record): mixed
    {
        return ($this->value)($record);
    }

    /**
     * @param  array<int, self>  $columns
     * @return array<int, self>
     */
    public static function compactOnly(array $columns): array
    {
        return array_values(array_filter($columns, fn (self $column) => $column->compact));
    }

    /**
     * @param  array<int, self>  $columns
     * @return array<int, string>
     */
    public static function labels(array $columns): array
    {
        return array_map(fn (self $column) => $column->label, $columns);
    }

    /**
     * @param  array<int, self>  $columns
     * @return array<int, mixed>
     */
    public static function row(array $columns, object $record): array
    {
        return array_map(fn (self $column) => $column->resolve($record), $columns);
    }
}
