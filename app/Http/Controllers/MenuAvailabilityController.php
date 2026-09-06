<?php

namespace App\Http\Controllers;

use App\Services\MenuAvailabilityService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MenuAvailabilityController extends Controller
{
    public function index(MenuAvailabilityService $availability): View
    {
        Gate::authorize('menu-availability.view');

        return view('menu-availability.index', [
            'catalog' => $availability->catalog($this->company(), $this->branch()),
        ]);
    }
}
