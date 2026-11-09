<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\DynamicQrNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DynamicQrNotificationController extends Controller
{
    public function index(Request $request, DynamicQrNotifications $notifications): JsonResponse
    {
        Gate::authorize('create', Payment::class);
        Gate::authorize('viewAny', Order::class);

        return response()->json(['notifications' => $notifications->pending($this->company(), $this->branch(), $request->user())])
            ->header('Cache-Control', 'no-store');
    }

    public function acknowledge(Request $request, string $intent, DynamicQrNotifications $notifications): JsonResponse
    {
        Gate::authorize('create', Payment::class);
        Gate::authorize('viewAny', Order::class);
        $notifications->acknowledge($this->company(), $this->branch(), $request->user(), $intent);

        return response()->json(['acknowledged' => true])->header('Cache-Control', 'no-store');
    }
}
