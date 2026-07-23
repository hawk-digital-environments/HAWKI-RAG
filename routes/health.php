<?php

declare(strict_types=1);

use App\Services\Profile\AdminAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Liveness
|--------------------------------------------------------------------------
| Minimaler Laravel Liveness Check fuer Load Balancer, Docker und Uptime Tools.
*/
Route::get('/up', static fn (): Response => response()->noContent())->middleware('throttle:hawki-health');

Route::middleware(['web', 'throttle:hawki-health'])->group(function (): void {
    Route::get('/pipeline-health', static function (
        Request $request,
        AdminAccessService $adminAccess,
    ): View {
        return view('svelte-page', [
            'title' => 'HAWKI Pipeline Health',
            'vite' => ['resources/css/pipeline-health-dashboard.css', 'resources/css/dashboard-dark-theme.css', 'resources/js/pipeline-health-dashboard.js'],
            'configScriptId' => 'pipeline-health-dashboard-config',
            'config' => ['adminAuthorized' => $adminAccess->allows($request)],
            'rootAttributes' => ['data-pipeline-health-dashboard' => true],
        ]);
    });
});
