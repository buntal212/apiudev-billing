<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationIntegrationAuditResource;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApplicationIntegrationTestAuditController extends Controller
{
    public function index(Request $request, Application $application): AnonymousResourceCollection
    {
        $this->authorize('view', $application);

        return ApplicationIntegrationAuditResource::collection(
            $application->integrationAudits()
                ->with('token')
                ->where('operation', 'like', 'test.%')
                ->latest()
                ->paginate(min(max((int) $request->input('per_page', 20), 1), 100)),
        );
    }
}
