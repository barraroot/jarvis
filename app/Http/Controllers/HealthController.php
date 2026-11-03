<?php

namespace App\Http\Controllers;

use App\Support\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * Extended `/up` (observability): `200` when database, Redis and Reverb
 * answer, `503` naming the failed components otherwise. Registered outside
 * the `web` group so a Redis outage (sessions) cannot mask the report.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $report = $health->run();

        return response()
            ->json($report, $report['status'] === HealthCheck::OK ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
