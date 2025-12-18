<?php

namespace App\Http\Controllers\Bill;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

class BillController extends Controller
{
    public function fetchByFarmUuids(array $farmUuids): array
    {
        if (empty($farmUuids)) return [];

        return Bill::whereIn('farmUuid', $farmUuids)
            ->get()
            ->map(function (Bill $b) {
                return [
                    'id' => $b->id,
                    'uuid' => $b->uuid,
                    'billNo' => $b->billNo,
                    'farmUuid' => $b->farmUuid,
                    'extensionOfficerId' => $b->extensionOfficerId,
                    'farmerId' => $b->farmerId,
                    'subjectType' => $b->subjectType,
                    'subjectUuid' => $b->subjectUuid,
                    'quantity' => (int) $b->quantity,
                    'amount' => (string) $b->amount,
                    'status' => $b->status,
                    'notes' => $b->notes,
                    'createdAt' => optional($b->created_at)->toIso8601String(),
                    'updatedAt' => optional($b->updated_at)->toIso8601String(),
                ];
            })
            ->toArray();
    }

    private function generateBillNoUnique(): string
    {
        // 7-char uppercase A-Z0-9; ensure uniqueness at DB level
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $len = strlen($chars);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $out = '';
            for ($i = 0; $i < 7; $i++) {
                $out .= $chars[random_int(0, $len - 1)];
            }
            if (! Bill::where('billNo', $out)->exists()) {
                return $out;
            }
        }
        // Fallback (let DB unique constraint enforce if collision still occurs)
        return substr(strtoupper(bin2hex(random_bytes(8))), 0, 7);
    }

    public function processBills(array $bills, int $extensionOfficerId): array
    {
        $synced = [];

        foreach ($bills as $data) {
            try {
                $uuid = $data['uuid'] ?? null;
                if (! $uuid) continue;

                $syncAction = $data['syncAction'] ?? 'create';
                $farmUuid = $data['farmUuid'] ?? null;
                if (! $farmUuid) {
                    Log::warning('Bill missing farmUuid', ['uuid' => $uuid]);
                    continue;
                }

                // Resolve farmerId from farmUuid
                $farmerId = null;
                $farm = \App\Models\Farm::where('uuid', $farmUuid)->first();
                if ($farm) {
                    $farmerId = $farm->farmerId;
                } else {
                    Log::warning('Bill farmUuid not found', ['uuid' => $uuid, 'farmUuid' => $farmUuid]);
                    continue;
                }

                $createdAt = isset($data['createdAt'])
                    ? \Carbon\Carbon::parse($data['createdAt'])->format('Y-m-d H:i:s')
                    : now()->format('Y-m-d H:i:s');
                $updatedAt = isset($data['updatedAt'])
                    ? \Carbon\Carbon::parse($data['updatedAt'])->format('Y-m-d H:i:s')
                    : now()->format('Y-m-d H:i:s');

                switch ($syncAction) {
                    case 'create':
                        $existing = Bill::where('uuid', $uuid)->first();
                        $payload = [
                            'billNo' => !empty($data['billNo']) ? $data['billNo'] : $this->generateBillNoUnique(),
                            'farmUuid' => $farmUuid,
                            'extensionOfficerId' => $extensionOfficerId,
                            'farmerId' => $farmerId,
                            'subjectType' => $data['subjectType'] ?? '',
                            'subjectUuid' => $data['subjectUuid'] ?? '',
                            'quantity' => (int)($data['quantity'] ?? 1),
                            'amount' => (string)($data['amount'] ?? '0'),
                            'status' => $data['status'] ?? 'pending',
                            'notes' => $data['notes'] ?? null,
                        ];
                        if ($existing) {
                            // Upsert if local newer
                            if (\Carbon\Carbon::parse($updatedAt)->greaterThan(\Carbon\Carbon::parse($existing->updated_at))) {
                                $existing->update($payload + ['updated_at' => $updatedAt]);
                            }
                        } else {
                            $bill = Bill::create(['uuid' => $uuid] + $payload + [
                                'created_at' => $createdAt,
                                'updated_at' => $updatedAt,
                            ]);
                            
                            // Send SMS notification to farmer
                            $this->sendBillNotificationToFarmer($bill, $farm);
                        }
                        $synced[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        $existing = Bill::where('uuid', $uuid)->first();
                        if ($existing) {
                            if (\Carbon\Carbon::parse($updatedAt)->greaterThan(\Carbon\Carbon::parse($existing->updated_at))) {
                                $existing->update([
                                    'billNo' => !empty($data['billNo']) ? $data['billNo'] : ($existing->billNo ?? $this->generateBillNoUnique()),
                                    'farmUuid' => $farmUuid,
                                    'extensionOfficerId' => $extensionOfficerId,
                                    'farmerId' => $farmerId,
                                    'subjectType' => $data['subjectType'] ?? $existing->subjectType,
                                    'subjectUuid' => $data['subjectUuid'] ?? $existing->subjectUuid,
                                    'quantity' => (int)($data['quantity'] ?? $existing->quantity ?? 1),
                                    'amount' => (string)($data['amount'] ?? $existing->amount ?? '0'),
                                    'status' => $data['status'] ?? $existing->status,
                                    'notes' => $data['notes'] ?? $existing->notes,
                                    'updated_at' => $updatedAt,
                                ]);
                            }
                            $synced[] = ['uuid' => $uuid];
                        }
                        break;

                    case 'deleted':
                        $existing = Bill::where('uuid', $uuid)->first();
                        if ($existing) {
                            $existing->delete();
                        }
                        $synced[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning('Unknown bill syncAction', ['uuid' => $uuid, 'syncAction' => $syncAction]);
                        break;
                }
            } catch (\Throwable $e) {
                Log::error('Error processing bill', ['error' => $e->getMessage(), 'bill' => $data ?? null]);
                continue;
            }
        }

        return $synced;
    }

    /**
     * Send SMS notification to farmer about new bill
     *
     * @param Bill $bill
     * @param \App\Models\Farm $farm
     * @return void
     */
    private function sendBillNotificationToFarmer(Bill $bill, $farm): void
    {
        try {
            // Get farmer details
            $farmer = \App\Models\Farmer::find($bill->farmerId);
            if (!$farmer || empty($farmer->phone1)) {
                Log::info("📱 Skipping SMS: Farmer not found or no phone number for farmerId: {$bill->farmerId}");
                return;
            }

            // Build SMS message
            $message = $this->buildBillSmsMessage($bill, $farm, $farmer);

            // Send SMS
            $smsService = new SmsService();
            $result = $smsService->sendSms($message, $farmer->phone1);

            Log::info("📱 Bill SMS notification sent to farmer", [
                'billNo' => $bill->billNo,
                'farmerId' => $bill->farmerId,
                'phone' => $farmer->phone1,
                'result' => is_array($result) ? 'success' : $result
            ]);
        } catch (\Exception $e) {
            Log::error("❌ Failed to send bill SMS notification", [
                'billNo' => $bill->billNo ?? 'N/A',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Build SMS message for bill notification
     *
     * @param Bill $bill
     * @param \App\Models\Farm $farm
     * @param \App\Models\Farmer $farmer
     * @return string
     */
    private function buildBillSmsMessage(Bill $bill, $farm, $farmer): string
    {
        $farmerName = $farmer->firstName ?? 'Farmer';
        $farmName = $farm->name ?? 'Your Farm';
        $billNo = $bill->billNo ?? 'N/A';
        $amount = number_format((float)$bill->amount, 2);
        $quantity = $bill->quantity ?? 1;
        $subjectType = ucfirst($bill->subjectType ?? 'service');
        $status = ucfirst($bill->status ?? 'pending');

        $message = "Hello {$farmerName},\n\n";
        $message .= "New Bill Created\n";
        $message .= "Farm: {$farmName}\n";
        $message .= "Bill No: {$billNo}\n";
        $message .= "Service: {$subjectType}\n";
        $message .= "Quantity: {$quantity}\n";
        $message .= "Amount: TZS {$amount}\n";
        $message .= "Status: {$status}\n\n";
        
        if (!empty($bill->notes)) {
            $message .= "Notes: {$bill->notes}\n\n";
        }
        
        $message .= "Thank you for using MyNg'ombe - Tag and Seal!";

        return $message;
    }
}
