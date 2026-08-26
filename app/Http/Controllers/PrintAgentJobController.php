<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompletePrintJobRequest;
use App\Http\Requests\FailPrintJobRequest;
use App\Models\PrintAgent;
use App\Services\PrintAgentQueueService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintAgentJobController extends Controller
{
    public function claim(Request $request, PrintAgentQueueService $queue): JsonResponse
    {
        $claim = $queue->claim($this->agent($request));
        if (! $claim) {
            return response()->json(status: 204);
        }

        $job = $claim['job'];

        return response()->json([
            'job' => [
                'id' => $job->ulid,
                'type' => $job->purpose->value,
                'logical_printer' => $job->windows_printer_name,
                'copies' => $job->copies,
                'payload_base64' => $job->document_payload,
                'claim_token' => $claim['claim_token'],
                'attempt' => $job->attempts,
            ],
        ]);
    }

    public function printed(CompletePrintJobRequest $request, string $job, PrintAgentQueueService $queue): JsonResponse
    {
        try {
            $attempt = $queue->printed($this->agent($request), $job, $request->validated('claim_token'));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['job' => ['id' => $attempt->ulid, 'status' => $attempt->status->value]]);
    }

    public function failed(FailPrintJobRequest $request, string $job, PrintAgentQueueService $queue): JsonResponse
    {
        try {
            $attempt = $queue->failed($this->agent($request), $job, $request->validated('claim_token'), $request->validated('error'));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['job' => ['id' => $attempt->ulid, 'status' => $attempt->status->value]]);
    }

    private function agent(Request $request): PrintAgent
    {
        return $request->attributes->get('print_agent');
    }
}
