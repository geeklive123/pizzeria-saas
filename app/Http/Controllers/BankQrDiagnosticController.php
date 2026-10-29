<?php

namespace App\Http\Controllers;

use App\Actions\BankQrDiagnosticAction;
use App\Enums\MembershipRole;
use App\Models\BankQrDiagnostic;
use App\Models\DynamicQrIntent;
use App\Services\BankQrImageInspection;
use App\Support\CompanyContext;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankQrDiagnosticController extends Controller
{
    public function index(BankQrImageInspection $images)
    {
        $this->authorizeRole();

        $records = BankQrDiagnostic::query()->forCompany($this->company())->latest('id')->limit(50)->get();
        $unresolved = BankQrDiagnostic::query()->forCompany($this->company())
            ->whereIn('status', ['generation_unknown', 'reconciliation_required'])->get();
        $records = $unresolved->concat($records)->unique('id')->sortByDesc('id')->values();
        $blocker = BankQrDiagnostic::query()->forCompany($this->company())
            ->where(fn ($query) => $query->where('active_company_id', $this->company()->id)->orWhere('status', 'generation_unknown'))
            ->oldest('id')->first();
        if ($blocker && ! $records->contains('id', $blocker->id)) {
            $records->prepend($blocker);
        }
        $inspections = $records->mapWithKeys(function ($record) use ($images) {
            $original = $record->original_qr_image;
            if ($original === null) {
                return [$record->id => null];
            }

            return [$record->id => $images->inspect($original) + ['png_available' => $images->pngBytes($original) !== null]];
        });
        $evidence = DB::table('bank_qr_reconciliation_evidence')->where('company_id', $this->company()->id)
            ->whereIn('diagnostic_id', $records->pluck('id'))->orderBy('id')->get()->map(function ($row) {
                $row->details = Crypt::decryptString($row->details);
                $row->candidate_qr_id = $row->candidate_qr_id === null ? null : Crypt::decryptString($row->candidate_qr_id);

                return $row;
            })->groupBy('diagnostic_id');

        return response()->view('bank-qr-diagnostics.index', [
            'records' => $records,
            'inspections' => $inspections,
            'blocker' => $blocker,
            'salesIncidents' => DynamicQrIntent::query()->forCompany($this->company())->whereIn('status', ['generation_unknown', 'reconciliation_required'])->oldest('id')->get(),
            'evidence' => $evidence,
            'enabled' => config('baneco.diagnostics_enabled'),
            'requestKey' => (string) Str::ulid(),
        ])->header('Cache-Control', 'private, no-store, no-cache, max-age=0, must-revalidate')
            ->header('Pragma', 'no-cache')->header('Expires', '0')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, BankQrDiagnosticAction $action)
    {
        $this->authorizeRole();
        $data = $request->validate(['amount' => ['required', 'string', 'in:1,1.0,1.00'], 'request_key' => ['required', 'ulid'], 'confirm_generation' => ['required', 'accepted']]);
        try {
            $action->generate($this->company(), $request->user(), $data['amount'], $this->branch(), $data['request_key']);
        } catch (DomainException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()]);
        }

        return redirect()->route('bank-qr-diagnostics.index');
    }

    public function image(string $ulid, BankQrImageInspection $images)
    {
        $this->authorizeRole();
        $record = BankQrDiagnostic::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
        $bytes = $images->pngBytes($record->original_qr_image);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function check(string $ulid, Request $request, BankQrDiagnosticAction $action)
    {
        $this->authorizeRole();
        $record = BankQrDiagnostic::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
        $action->check($record, $request->user(), $request->routeIs('bank-qr-diagnostics.cancel'));

        return redirect()->route('bank-qr-diagnostics.index');
    }

    public function reconcile(string $ulid, Request $request, BankQrDiagnosticAction $action)
    {
        $this->authorizeRole();
        $record = BankQrDiagnostic::query()->forCompany($this->company())->where('ulid', $ulid)->firstOrFail();
        $data = $request->validate([
            'details' => ['required', 'string', 'min:20', 'max:2000'],
            'candidate_qr_id' => ['required_if:action,verify', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,190}$/'],
            'action' => ['required', 'in:evidence,verify,approve'],
            'confirmed' => ['nullable', 'boolean'],
            'bank_contact' => ['required_if:action,evidence', 'nullable', 'string', 'max:120'],
            'bank_case' => ['required_if:action,evidence', 'nullable', 'string', 'max:120'],
            'bank_date' => ['required_if:action,evidence', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        try {
            match ($data['action']) {
                'evidence' => $action->recordExternalConfirmation($record, $request->user(), $data['details'], $data['bank_contact'], $data['bank_case'], $data['bank_date']),
                'verify' => $action->verifyCandidate($record, $request->user(), $data['candidate_qr_id'], $data['details']),
                'approve' => $action->authorizeNotGenerated($record, $request->user(), $data['details'], $request->boolean('confirmed')),
            };
        } catch (DomainException $exception) {
            return back()->withErrors(['reconciliation' => $exception->getMessage()]);
        }

        return redirect()->route('bank-qr-diagnostics.index');
    }

    private function authorizeRole(): void
    {
        abort_unless(in_array(app(CompanyContext::class)->membership()->role, [MembershipRole::Owner, MembershipRole::Admin], true), 403);
    }
}
