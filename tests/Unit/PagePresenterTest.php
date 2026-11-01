<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Support\PagePresenter;
use AshrafTalha\OpConsole\Tests\TestCase;

class PagePresenterTest extends TestCase
{
    private function highlight(string $text, string $search): string
    {
        $presenter = new PagePresenter;
        $method = new \ReflectionMethod($presenter, 'highlight');
        $method->setAccessible(true);

        return $method->invoke($presenter, $text, $search);
    }

    public function test_plain_text_is_escaped(): void
    {
        $this->assertSame('&lt;script&gt;', $this->highlight('<script>', ''));
    }

    public function test_search_term_is_wrapped_in_mark_and_escaped(): void
    {
        $this->assertSame('a <mark>b</mark> c', $this->highlight('a b c', 'b'));
    }

    /** Regression for S-L1: searching for entity-looking text must not wedge a <mark> inside an emitted entity. */
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
}
