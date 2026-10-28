<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetrics;
use App\Services\Extraction\ExtractionManager;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardMetrics $metrics, ExtractionManager $providers): View
    {
        return view('dashboard', [
            'm' => $metrics->build(),
            'liveProvider' => $providers->isLive(),
        ]);
    }
}
