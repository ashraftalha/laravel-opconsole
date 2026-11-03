<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Support\ConsoleStatus;
use AshrafTalha\OpConsole\Support\PagePresenter;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Carbon;

class PagePresenterTest extends TestCase
{
    private function highlight(string $text, string $search): string
    {
        $presenter = new PagePresenter;
        $method = new \ReflectionMethod($presenter, 'highlight');
        $method->setAccessible(true);

        return $method->invoke($presenter, $text, $search);
    }

    private function presentChecks(array $checks): array
    {
        $presenter = new PagePresenter;
        $method = new \ReflectionMethod($presenter, 'checks');

        return $method->invoke($presenter, $checks);
    }

    private function check(string $group, string $status = 'healthy'): array
    {
        return ['id' => strtolower(str_replace(' ', '.', $group)).'.'.uniqid(), 'group' => $group, 'label' => $group.' check', 'ok' => $status === 'healthy', 'status' => $status, 'severity' => 'warning', 'hint' => 'Hint.', 'value' => null, 'fix' => null];
    }

    public function test_check_groups_follow_the_fixed_order_with_custom_groups_last(): void
    {
        $groups = $this->presentChecks([
            $this->check('URL and email'),
            $this->check('Custom'),
            $this->check('Files and folders'),
            $this->check('Scheduler and workers'),
            $this->check('Security'),
        ])['groups'];

        $this->assertSame(['Security', 'Scheduler and workers', 'Files and folders', 'URL and email', 'Custom'], array_column($groups, 'name'));
    }

    public function test_groups_with_problems_still_come_first(): void
    {
        $groups = $this->presentChecks([
            $this->check('Security'),
            $this->check('URL and email', 'critical'),
        ])['groups'];

        $this->assertSame('URL and email', $groups[0]['name']);
    }

    public function test_plain_text_is_escaped(): void
    {
        $this->assertSame('&lt;script&gt;', $this->highlight('<script>', ''));
    }

    public function test_search_term_is_wrapped_in_mark_and_escaped(): void
    {
        $this->assertSame('a <mark>b</mark> c', $this->highlight('a b c', 'b'));
    }

    /** Regression: searching for entity-looking text must not wedge a <mark> inside an emitted entity. */
    public function test_searching_for_entity_text_does_not_corrupt_escaped_output(): void
    {
        $result = $this->highlight('<div>', 'div');

        $this->assertSame('&lt;<mark>div</mark>&gt;', $result);
        $this->assertStringNotContainsString('&<mark>', $result);
    }

    public function test_search_is_case_insensitive(): void
    {
        $this->assertSame('<mark>Hello</mark> world', $this->highlight('Hello world', 'hello'));
    }

    /** Regression: searching for literal "0" must highlight "0" and preserve q=0 across filter URLs. */
    public function test_searching_for_literal_zero_is_preserved_in_filter_urls_and_highlighted(): void
    {
        $this->assertSame('exit code <mark>0</mark>', $this->highlight('exit code 0', '0'));

        $presenter = new PagePresenter;
        $status = $this->app->make(ConsoleStatus::class)->status(null);
        $view = $presenter->page(
            $status,
            [['name' => 'laravel.log', 'size' => 100, 'modified' => Carbon::now()]],
            [
                'name' => 'laravel.log',
                'env' => null,
                'level' => null,
                'search' => '0',
                'limit' => 50,
                'entries' => [['time' => '2026-01-01 00:00:00', 'env' => 'testing', 'level' => 'error', 'message' => 'exit 0', 'details' => '']],
                'counts' => ['error' => 1],
                'envs' => ['testing' => 1],
                'groups' => [],
                'total' => 1,
                'allTotal' => 1,
                'shown' => 1,
                'truncated' => false,
                'more' => false,
            ],
            null,
        );

        $this->assertNotNull($view['log']['resetFiltersUrl']);
        $this->assertStringContainsString('q=0', $view['log']['levelChips'][0]['url']);
    }
}
