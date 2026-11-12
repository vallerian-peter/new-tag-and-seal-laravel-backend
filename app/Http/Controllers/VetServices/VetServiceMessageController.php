<?php

namespace App\Http\Controllers\VetServices;

use App\Http\Controllers\Controller;
use App\Http\Controllers\VetServices\Concerns\RespondsVetServices;
use App\Models\VetServiceMessage;
use App\Models\VetServiceRequest;
use App\Services\VetServices\VetServiceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VetServiceMessageController extends Controller
{
    use RespondsVetServices;

    public function __construct(private readonly VetServiceRequestService $service)
    {
    }

    /** GET vet-services/requests/{uuid}/messages?afterId= — returns only new messages when afterId is given. */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $req = VetServiceRequest::where('uuid', $uuid)->first();
        if (! $req || ! $this->service->canView($user, $req)) {
            return $this->fail('Request not found', 404, 'NOT_FOUND');
        }

        $afterId = (int) $request->query('afterId', 0);
        $q = VetServiceMessage::where('requestId', $req->id)->orderBy('id');
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        } else {
            $q = VetServiceMessage::where('requestId', $req->id)->orderByDesc('id')->limit(100);
        }
        $messages = $q->get();
        if ($afterId === 0) {
            $messages = $messages->reverse()->values();
        }

        return $this->ok('Messages', [
            'messages' => $messages->map(fn (VetServiceMessage $m) => $m->toWire($user->id))->values()->all(),
            'lastId' => (int) ($messages->last()?->id ?? $afterId),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    /** POST vet-services/requests/{uuid}/messages {body, uuid?} */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'body' => 'required|string|min:1|max:2000',
            'uuid' => 'nullable|string|max:100',
        ]);
        if ($validator->fails()) {
            return $this->fail('Validation failed', 422, 'VALIDATION', $validator->errors());
        }

        return $this->guard(function () use ($request, $uuid) {
            $req = VetServiceRequest::where('uuid', $uuid)->firstOrFail();
            $message = $this->service->sendMessage($request->user(), $req, trim($request->body), $request->input('uuid'));

            return $this->ok('Message sent', $message->toWire($request->user()->id), 201);
        });
    }

    /** POST vet-services/requests/{uuid}/messages/read {uptoId?} */
    public function markRead(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $req = VetServiceRequest::where('uuid', $uuid)->first();
        if (! $req || ! $this->service->canView($user, $req)) {
            return $this->fail('Request not found', 404, 'NOT_FOUND');
        }
        $count = $this->service->markRead($user, $req, $request->filled('uptoId') ? (int) $request->uptoId : null);

        return $this->ok('Marked as read', ['updated' => $count]);
    }
}
