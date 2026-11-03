<?php

namespace AshrafTalha\OpConsole\Http\Controllers;

use AshrafTalha\OpConsole\Support\ConsoleStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Token-protected uptime endpoint: statuses and values only, never hints, fixes, or secrets. */
class HealthController extends Controller
{
    public function __invoke(Request $request, ConsoleStatus $status): JsonResponse
    {
        $token = config('opconsole.health_endpoint.token');

        // An empty token would make hash_equals('', '') succeed, so a missing token keeps the endpoint off.
        abort_unless(config('opconsole.health_endpoint.enabled', false) && is_string($token) && $token !== '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        $report = $status->health();

        return response()->json([
            'status' => $report['status'],
            'checked_at' => $report['checked_at'],
            'checks' => array_map(fn (array $check) => [
                'id' => $check['id'],
                'group' => $check['group'],
                'label' => $check['label'],
                'status' => $check['status'],
                'value' => $check['value'],
            ], $report['checks']),
        ], $report['status'] === 'critical' ? 503 : 200, ['Cache-Control' => 'no-store']);
    }
}
