<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationIntegrationAuditResource;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApplicationIntegrationAuditController extends Controller
{
    public function index(Request $request, Application $application): AnonymousResourceCollection
    {
        $this->authorize('view', $application);

        return ApplicationIntegrationAuditResource::collection(
            $application->integrationAudits()
                ->with('token')
                ->latest()
                ->paginate(min(max((int) $request->input('per_page', 20), 1), 100)),
        );
    }
}
