<?php

namespace App\Http\Controllers;

use App\Enums\LogoutReason;
use App\Http\Requests\LoginRequest;
use App\Services\UserAccessLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('dashboard') : view('auth.login');
    }

    public function store(LoginRequest $request, UserAccessLogService $accessLogs): RedirectResponse
    {
        if (! Auth::attempt($request->safe()->only(['email', 'password']), $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'El correo o la contraseña no son correctos.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->forget(['active_company_id', 'active_branch_id']);
        $accessLogs->startAfterLogin($request, $request->user());

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, UserAccessLogService $accessLogs): RedirectResponse
    {
        $accessLogs->finish($request, $request->user(), LogoutReason::Manual);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
