<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\PhoneAuthController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OtpVerificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,verified,expired,locked'],
            'channel' => ['nullable', 'in:sms,email'],
            'sort' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:10,30,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $now = now()->toDateTimeString();
        // Select metadata explicitly: never return OTPs, code hashes or proof tokens.
        $legacy = DB::table('otp_verifications')->selectRaw("id, 'legacy' as source, COALESCE(phone, email) as recipient, CASE WHEN phone IS NOT NULL THEN 'sms' ELSE 'email' END as channel, 'password-recovery' as purpose, NULL as attempts, expires_at, NULL as verified_at, created_at, updated_at, CASE WHEN verified = 1 THEN 'verified' WHEN expires_at <= ? THEN 'expired' ELSE 'pending' END as status", [$now]);
        if (Schema::hasTable('phone_auth_challenges')) {
            $phone = DB::table('phone_auth_challenges')->selectRaw("id, 'phone' as source, phone as recipient, 'sms' as channel, purpose, attempts, expires_at, verified_at, created_at, updated_at, CASE WHEN verified_at IS NOT NULL THEN 'verified' WHEN attempts >= 5 THEN 'locked' WHEN expires_at <= ? THEN 'expired' ELSE 'pending' END as status", [$now]);
            $legacy->unionAll($phone);
        }
        $query = DB::query()->fromSub($legacy, 'verifications');
        $stats = ['total' => (clone $query)->count()];
        foreach (['pending', 'verified', 'expired', 'locked'] as $status) {
            $stats[$status] = (clone $query)->where('status', $status)->count();
        }
        if (! empty($data['search'])) {
            $search = '%'.$data['search'].'%';
            $query->where(function ($query) use ($search): void {
                $query->where('recipient', 'like', $search)->orWhere('purpose', 'like', $search);
            });
        }
        foreach (['status', 'channel'] as $filter) {
            if (! empty($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        $page = $query->orderBy('created_at', $data['sort'] ?? 'desc')->orderBy('source')->orderBy('id', 'desc')->paginate($data['per_page'] ?? 10);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
            'stats' => $stats,
            'canManage' => $request->user()->hasPlatformPermission('accounts.manage'),
        ]);
    }

    public function store(Request $request, PhoneAuthController $phones): JsonResponse
    {
        // Reuse mobile OTP validation, flow restrictions, cooldown and delivery.
        $response = $phones->sendOtp($request);
        if ($response->isSuccessful()) {
            return response()->json(['message' => 'OTP created and sent successfully.'], 201);
        }

        return $response;
    }

    public function destroy(string $source, int $record): JsonResponse
    {
        $table = match ($source) {
            'phone' => 'phone_auth_challenges',
            'legacy' => 'otp_verifications',
            default => abort(404),
        };
        abort_unless(Schema::hasTable($table), 404);
        abort_unless(DB::table($table)->where('id', $record)->delete(), 404);

        return response()->json(['message' => 'OTP record deleted successfully.']);
    }
}
