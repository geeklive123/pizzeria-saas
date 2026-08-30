<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\SaleEntryService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function create(SaleEntryService $sales): View
    {
        Gate::authorize('create', Order::class);

        return view('sales.create', $sales->data($this->company(), $this->branch()));
    }
}
