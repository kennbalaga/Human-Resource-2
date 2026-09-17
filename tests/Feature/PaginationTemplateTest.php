<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * The shared pager every list renders through `pagination::bootstrap-5`.
 */
class PaginationTemplateTest extends TestCase
{
    public function test_it_shows_a_window_of_five_pages_with_first_and_last(): void
    {
        $html = $this->render(currentPage: 7, total: 180, perPage: 15);

        foreach ([5, 6, 8, 9] as $page) {
            $this->assertStringContainsString('aria-label="Page '.$page.'"', $html);
        }
        $this->assertStringContainsString('aria-current="page"><span class="page-link">7</span>', $html);
        $this->assertStringNotContainsString('aria-label="Page 4"', $html);
        $this->assertStringNotContainsString('aria-label="Page 10"', $html);
        // No "1 2 ... 11 12" tail: the ends are reached through First and Last.
        $this->assertStringNotContainsString('...', $html);
        $this->assertStringContainsString('rel="first"', $html);
        $this->assertStringContainsString('rel="last"', $html);
        $this->assertStringContainsString('page=12', $html);
    }

    public function test_the_window_stays_five_wide_at_either_end(): void
    {
        $atEnd = $this->render(currentPage: 22, total: 323, perPage: 15);
        foreach ([18, 19, 20, 21] as $page) {
            $this->assertStringContainsString('aria-label="Page '.$page.'"', $atEnd);
        }
        $this->assertStringNotContainsString('aria-label="Page 17"', $atEnd);
        // On the last page, Next and Last are inert.
        $this->assertStringNotContainsString('rel="next"', $atEnd);
        $this->assertStringNotContainsString('rel="last"', $atEnd);

        $atStart = $this->render(currentPage: 1, total: 323, perPage: 15);
        foreach ([2, 3, 4, 5] as $page) {
            $this->assertStringContainsString('aria-label="Page '.$page.'"', $atStart);
        }
        $this->assertStringNotContainsString('aria-label="Page 6"', $atStart);
        $this->assertStringNotContainsString('rel="first"', $atStart);
        $this->assertStringNotContainsString('rel="prev"', $atStart);
    }

    public function test_go_to_page_keeps_the_filters_and_starts_on_the_current_page(): void
    {
        $html = $this->render(currentPage: 3, total: 323, perPage: 15, query: [
            'department_id' => 4,
            'status' => 'active',
            'ids' => [7, 9],
        ]);

        $this->assertStringContainsString('data-page-jump', $html);
        $this->assertStringContainsString('action="http://localhost/employees"', $html);
        $this->assertStringContainsString('<input type="hidden" name="department_id" value="4">', $html);
        $this->assertStringContainsString('<input type="hidden" name="status" value="active">', $html);
        $this->assertStringContainsString('<input type="hidden" name="ids[0]" value="7">', $html);
        $this->assertStringContainsString('<input type="hidden" name="ids[1]" value="9">', $html);
        $this->assertMatchesRegularExpression('/name="page"\s+value="3"\s+min="1"\s+max="22"/', $html);
        $this->assertStringContainsString('of 22', $html);
        // The page itself is the box, not a hidden field that would override it.
        $this->assertStringNotContainsString('type="hidden" name="page"', $html);
    }

    public function test_a_named_page_and_fragment_carry_through_the_jump(): void
    {
        $paginator = new LengthAwarePaginator(range(1, 5), 90, 5, 2, [
            'path' => 'http://localhost/dashboard',
            'pageName' => 'employees_page',
        ]);
        $paginator->fragment('employee-overview');

        $html = $paginator->links('pagination::bootstrap-5')->toHtml();

        $this->assertStringContainsString('action="http://localhost/dashboard#employee-overview"', $html);
        $this->assertMatchesRegularExpression('/name="employees_page"\s+value="2"/', $html);
    }

    public function test_a_short_list_names_its_page_and_offers_no_jump(): void
    {
        $html = $this->render(currentPage: 2, total: 40, perPage: 15);

        $this->assertStringContainsString('Page 2 of 3', $html);
        $this->assertStringNotContainsString('data-page-jump', $html);
    }

    public function test_a_single_page_renders_nothing(): void
    {
        $this->assertSame('', trim($this->render(currentPage: 1, total: 10, perPage: 15)));
    }

    /** @param  array<string, mixed>  $query */
    private function render(int $currentPage, int $total, int $perPage, array $query = []): string
    {
        $paginator = new LengthAwarePaginator(
            array_fill(0, min($perPage, $total), null),
            $total,
            $perPage,
            $currentPage,
            ['path' => 'http://localhost/employees', 'query' => $query],
        );

        return $paginator->links('pagination::bootstrap-5')->toHtml();
    }
}
