<?php

namespace App\Http\Controllers\VetServices\Concerns;

use App\Services\VetServices\VetServiceException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

trait RespondsVetServices
{
    protected function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['status' => true, 'message' => $message, 'data' => $data], $status);
    }

    protected function fail(string $message, int $status = 422, ?string $code = null, mixed $errors = null): JsonResponse
    {
        $body = ['status' => false, 'message' => $message];
        if ($code) {
            $body['code'] = $code;
        }
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }

    /** Runs a closure and maps domain / unexpected errors to the JSON envelope. */
    protected function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (VetServiceException $e) {
            return $this->fail($e->getMessage(), $e->status, $e->errorCode);
        } catch (ModelNotFoundException) {
            return $this->fail('Request not found', 404, 'NOT_FOUND');
        } catch (Throwable $e) {
            Log::error('VetServices error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return $this->fail('Something went wrong. Please try again.', 500, 'SERVER_ERROR');
        }
    }
}
