<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Services\Profile\OperatorAccessService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function page(Request $request, OperatorAccessService $operatorAccess): View
    {
        return view('svelte-page', [
            'title' => 'HAWKI Settings',
            'vite' => ['resources/css/app.css', 'resources/css/dashboard-dark-theme.css', 'resources/css/hawki-rag-theme.css', 'resources/js/settings-dashboard.js'],
            'configScriptId' => 'settings-dashboard-config',
            'config' => [
                ...$this->settings->browserPayload(),
                'operatorAuthorized' => $operatorAccess->allows($request),
            ],
            'rootAttributes' => ['data-settings-dashboard' => true],
        ]);
    }

    public function show(): JsonResponse
    {
        return response()->json($this->settings->browserPayload());
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        return response()->json($this->settings->update($request->validated()));
    }
}
