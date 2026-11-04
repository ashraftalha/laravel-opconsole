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

    private function presentTabs(array $checks = [], array $actions = ['clear' => ['last' => null]], int $pending = 0, int $errors = 0, int $history = 0, bool $hasOutput = false): array
    {
        $presenter = new PagePresenter;
        $method = new \ReflectionMethod($presenter, 'tabs');

        return $method->invoke(
            $presenter,
            ['checks' => $checks, 'database' => ['pending' => array_fill(0, $pending, 'migration')], 'errors' => ['count' => $errors]],
            $actions,
            ['available' => true, 'items' => array_fill(0, $history, [])],
            $hasOutput,
        );
    }

    public function test_tabs_are_listed_in_order_with_health_badges_counting_only_real_problems(): void
    {
        $info = $this->check('PHP settings', 'warning');
        $info['severity'] = 'info';

        $tabs = $this->presentTabs([$this->check('Security', 'warning'), $this->check('Security', 'healthy'), $info]);

        $this->assertSame(['overview', 'health', 'security', 'operations', 'history', 'system', 'logs'], array_keys($tabs['items']));
        $this->assertSame('1', $tabs['items']['health']['badge']);
        $this->assertSame('is-warning', $tabs['items']['health']['tone']);
        $this->assertSame('1 check needs attention', $tabs['items']['health']['badgeLabel']);
        $this->assertSame('overview', $tabs['default']);
    }

    public function test_critical_checks_open_the_health_tab_with_a_red_badge(): void
    {
        $tabs = $this->presentTabs([$this->check('Security', 'critical'), $this->check('PHP settings', 'warning')]);

        $this->assertSame('2', $tabs['items']['health']['badge']);
        $this->assertSame('is-bad', $tabs['items']['health']['tone']);
        $this->assertSame('overview', $tabs['default']);
    }

    public function test_action_output_opens_the_actions_tab_even_with_critical_checks(): void
    {
        $tabs = $this->presentTabs([$this->check('Security', 'critical')], hasOutput: true);

        $this->assertSame('operations', $tabs['default']);
    }

    public function test_actions_badge_shows_pending_migrations_then_a_failed_last_run(): void
    {
        $pending = $this->presentTabs(actions: ['migrate' => ['last' => null]], pending: 3);
        $this->assertSame('3', $pending['items']['operations']['badge']);
        $this->assertSame('3 pending migrations', $pending['items']['operations']['badgeLabel']);

        $failed = $this->presentTabs(actions: ['clear' => ['last' => ['ok' => false, 'text' => 'failed']]]);
        $this->assertSame('!', $failed['items']['operations']['badge']);
        $this->assertSame('is-bad', $failed['items']['operations']['tone']);

        // Pending migrations are not counted when the migrate action is disabled.
        $this->assertNull($this->presentTabs(pending: 3)['items']['operations']['badge']);
    }

    public function test_logs_and_history_badges_and_empty_states(): void
    {
        $busy = $this->presentTabs(errors: 4, history: 5);
        $this->assertSame('4', $busy['items']['logs']['badge']);
        $this->assertSame('4 errors in the last 24 hours', $busy['items']['logs']['badgeLabel']);
        $this->assertSame('5', $busy['items']['history']['badge']);

        $quiet = $this->presentTabs();
        foreach (['health', 'history', 'logs', 'system'] as $key) {
            $this->assertNull($quiet['items'][$key]['badge'], $key);
        }
    }

    public function test_actions_tab_is_omitted_without_actions_or_output(): void
    {
        $tabs = $this->presentTabs(actions: []);

        $this->assertArrayNotHasKey('operations', $tabs['items']);
        $this->assertArrayHasKey('overview', $tabs['items']);
        $this->assertSame('overview', $tabs['default']);
    }

    public function test_overview_distinguishes_verified_issues_from_unknown_checks(): void
    {
        $presenter = new PagePresenter;
        $method = new \ReflectionMethod($presenter, 'overview');
        $checks = [
            ['id' => 'database.connection', 'group' => 'Database and queues', 'label' => 'Database connection', 'status' => 'critical', 'severity' => 'danger', 'hint' => 'Connection failed.', 'value' => 'sqlite', 'fix' => 'Check DB_* settings.'],
            ['id' => 'scheduler.heartbeat', 'group' => 'Scheduler and workers', 'label' => 'Scheduler heartbeat', 'status' => 'unknown', 'severity' => 'info', 'hint' => 'Scheduler has not run.', 'value' => 'no heartbeat', 'fix' => 'Run schedule:work.'],
        ];
        $presentedChecks = $this->presentChecks($checks);
        $overview = $method->invoke($presenter, [
            'app' => ['environment' => 'testing', 'down' => false],
            'checks' => $checks,
        ], $presentedChecks, []);

        $this->assertSame('Critical issues', $overview['state']);
        $this->assertSame(1, $overview['critical']);
        $this->assertSame(1, $overview['unknown']);
        $this->assertSame('Database connection', $overview['attention'][0]['label']);
        $this->assertSame('Scheduler heartbeat', $overview['unknownItems'][0]['label']);
        $this->assertSame('testing', $overview['environment']);
    }

    public function test_unknown_only_overview_is_partial_and_empty_checks_are_unavailable(): void
    {
        $presenter = new PagePresenter;
        $method = new \ReflectionMethod($presenter, 'overview');
        $unknown = $this->check('Scheduler and workers', 'unknown');
        $unknownOverview = $method->invoke($presenter, ['app' => ['environment' => 'testing', 'down' => true], 'checks' => [$unknown]], $this->presentChecks([$unknown]), []);
        $emptyOverview = $method->invoke($presenter, ['app' => ['environment' => 'testing', 'down' => false], 'checks' => []], $this->presentChecks([]), []);

        $this->assertSame('Partially verified', $unknownOverview['state']);
        $this->assertSame('is-unknown', $unknownOverview['tone']);
        $this->assertTrue($unknownOverview['maintenance']['down']);
        $this->assertSame('Health unavailable', $emptyOverview['state']);
    }

    public function test_theme_defaults_to_auto_standalone_and_light_when_embedded(): void
    {
        $presenter = new PagePresenter;
        $method = new \ReflectionMethod($presenter, 'theme');

        $this->assertSame('auto', $method->invoke($presenter));

        $this->app['config']->set('opconsole.layout', 'host-shell');
        $this->assertSame('light', $method->invoke($presenter));

        $this->app['config']->set('opconsole.theme', 'dark');
        $this->assertSame('dark', $method->invoke($presenter));

        $this->app['config']->set('opconsole.theme', 'neon');
        $this->assertSame('light', $method->invoke($presenter));
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

    private function invokePrivate(string $method, ...$args): mixed
    {
        $presenter = new PagePresenter;
        $reflection = new \ReflectionMethod($presenter, $method);

        return $reflection->invoke($presenter, ...$args);
    }

    public function test_health_score_never_reads_healthier_than_the_state(): void
    {
        $this->assertNull($this->invokePrivate('score', 0, 0, 0, 0, 0));
        $this->assertSame(100, $this->invokePrivate('score', 10, 10, 0, 0, 0));
        // 9 healthy + 1 critical would be 90, but any critical check caps the score below 60.
        $this->assertSame(59, $this->invokePrivate('score', 10, 9, 0, 0, 1));
        // 9 healthy + 1 warning would be 94, but any warning caps it below 90.
        $this->assertSame(89, $this->invokePrivate('score', 10, 9, 1, 0, 0));
        // Unverified checks count half.
        $this->assertSame(75, $this->invokePrivate('score', 4, 2, 0, 2, 0));
    }

    public function test_stack_frames_are_marked_as_application_or_vendor_without_unescaping(): void
    {
        $details = "[stacktrace]\n#0 /app/Http/Controllers/HomeController.php(12): index()\n#1 /app/vendor/laravel/framework/src/Router.php(5): run()\n#2 {main}\n<b>not a frame</b>";
        $html = $this->invokePrivate('frames', $this->highlight($details, 'Home'));

        $this->assertStringContainsString('<span class="oc-frame oc-frame-app">#0 /app/Http/Controllers/<mark>Home</mark>Controller.php(12): index()</span>', $html);
        $this->assertStringContainsString('<span class="oc-frame oc-frame-vendor">#1 /app/vendor/', $html);
        $this->assertStringContainsString('<span class="oc-frame oc-frame-vendor">#2 {main}</span>', $html);
        $this->assertStringContainsString('&lt;b&gt;not a frame&lt;/b&gt;', $html);
        $this->assertStringContainsString("[stacktrace]\n", $html);
    }
}
