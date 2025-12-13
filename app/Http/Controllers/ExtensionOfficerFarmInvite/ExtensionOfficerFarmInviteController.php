<?php

namespace App\Http\Controllers\ExtensionOfficerFarmInvite;

use App\Http\Controllers\Controller;
use App\Models\ExtensionOfficer;
use App\Models\ExtensionOfficerFarmInvite;
use App\Models\Farmer;
use App\Models\Farm;
use App\Enums\UserRole;
use App\Services\SmsService;
use App\Mail\ExtensionOfficerInvitationMail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ExtensionOfficerFarmInviteController extends Controller
{
    private SmsService $smsService;

    public function __construct()
    {
        $this->smsService = new SmsService();
    }
    /**
     * Search for extension officer by email
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function searchByEmail(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $email = $request->input('email');
            $extensionOfficer = ExtensionOfficer::where('email', $email)->first();

            if (!$extensionOfficer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Extension officer not found with this email',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Extension officer found',
                'data' => [
                    'id' => $extensionOfficer->id,
                    'firstName' => $extensionOfficer->firstName,
                    'middleName' => $extensionOfficer->middleName,
                    'lastName' => $extensionOfficer->lastName,
                    'email' => $extensionOfficer->email,
                    'phone' => $extensionOfficer->phone,
                    'specialization' => $extensionOfficer->specialization,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error searching extension officer: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to search extension officer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new extension officer farm invite
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $authenticatedUser = $request->user();

            // Verify user is a farmer
            if ($authenticatedUser->role !== UserRole::FARMER) {
                return response()->json([
                    'status' => false,
                    'message' => 'Only farmers can invite extension officers',
                ], 403);
            }

            $farmerId = $authenticatedUser->roleId;

            $validator = Validator::make($request->all(), [
                'extensionOfficerEmail' => 'required|email',
                'access_code' => [
                    'required',
                    'string',
                    'unique:extension_officer_farm_invites,access_code',
                    'regex:/^ACODE-\d{5}[A-Z]{3}=7-\d{2}$/',
                ],
            ], [
                'access_code.regex' => 'The access code format is invalid. Expected format: ACODE-{5numbers}{3letters}=7-{2numbers}',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $email = $request->input('extensionOfficerEmail');
            
            // Find extension officer by email
            $extensionOfficer = ExtensionOfficer::where('email', $email)->first();

            if (!$extensionOfficer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Extension officer not found with this email',
                ], 404);
            }

            // Check if invite already exists
            $existingInvite = ExtensionOfficerFarmInvite::where('extensionOfficerId', $extensionOfficer->id)
                ->where('farmerId', $farmerId)
                ->first();

            if ($existingInvite) {
                return response()->json([
                    'status' => false,
                    'message' => 'Extension officer has already been invited to your farm',
                    'data' => [
                        'access_code' => $existingInvite->access_code,
                    ],
                ], 409);
            }

            // Get access code from request (generated by frontend)
            $accessCode = $request->input('access_code');

            // Create new invite with access code from frontend
            $invite = ExtensionOfficerFarmInvite::create([
                'extensionOfficerId' => $extensionOfficer->id,
                'farmerId' => $farmerId,
                'access_code' => $accessCode,
            ]);

            Log::info("✅ Extension officer farm invite created: Officer ID {$extensionOfficer->id}, Farmer ID {$farmerId}, Access Code: {$invite->access_code}");

            // Get farmer (owner) details
            $farmer = Farmer::find($farmerId);
            if ($farmer) {
                // Get first farm for context (if available)
                $farm = Farm::where('farmerId', $farmerId)->first();
                $farmName = $farm ? ($farm->name ?? null) : null;

                // Send SMS invitation
                $this->sendExtensionOfficerInvitationSms($extensionOfficer, $farmer, $accessCode, $farmName);

                // Send email invitation
                $this->sendExtensionOfficerInvitationEmail($extensionOfficer, $farmer, $accessCode, $farmName);
            }

            return response()->json([
                'status' => true,
                'message' => 'Extension officer invited successfully',
                'data' => [
                    'id' => $invite->id,
                    'access_code' => $invite->access_code,
                    'extensionOfficer' => [
                        'id' => $extensionOfficer->id,
                        'firstName' => $extensionOfficer->firstName,
                        'middleName' => $extensionOfficer->middleName,
                        'lastName' => $extensionOfficer->lastName,
                        'email' => $extensionOfficer->email,
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating extension officer farm invite: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create extension officer invite',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send SMS invitation to extension officer
     */
    private function sendExtensionOfficerInvitationSms(
        ExtensionOfficer $extensionOfficer,
        Farmer $farmer,
        string $accessCode,
        ?string $farmName = null
    ): void {
        try {
            $farmerName = trim(($farmer->firstName ?? '') . ' ' . ($farmer->middleName ?? '') . ' ' . ($farmer->surname ?? ''));
            $farmerName = $farmerName ?: 'Farm Owner';

            $extensionOfficerName = trim(($extensionOfficer->firstName ?? '') . ' ' . ($extensionOfficer->middleName ?? '') . ' ' . ($extensionOfficer->lastName ?? ''));
            $extensionOfficerName = $extensionOfficerName ?: 'Extension Officer';

            // Build SMS message
            $message = "Hello {$extensionOfficerName},\n\n";
            $message .= "You have been invited to join ";
            if ($farmName) {
                $message .= "the farm: {$farmName}";
            } else {
                $message .= "a farm";
            }
            $message .= " by {$farmerName}.\n\n";
            $message .= "Access Code: {$accessCode}\n\n";
            $message .= "Login Credentials:\n";
            $message .= "Email: {$extensionOfficer->email}\n";
            if ($extensionOfficer->password) {
                $message .= "Password: {$extensionOfficer->password}\n\n";
            }
            $message .= "Farm Owner Contact:\n";
            if ($farmer->phone1) {
                $message .= "Phone: {$farmer->phone1}\n";
            }
            if ($farmer->email) {
                $message .= "Email: {$farmer->email}\n";
            }
            $message .= "\nThank you!";

            // Send SMS to extension officer's phone
            $phoneNumber = $extensionOfficer->phone;
            if (empty($phoneNumber)) {
                Log::warning("⚠️ No phone number found for extension officer: {$extensionOfficer->email}");
                return;
            }

            $result = $this->smsService->sendSms($message, $phoneNumber);

            if (is_string($result)) {
                Log::warning("⚠️ Failed to send SMS to {$phoneNumber}: {$result}");
            } else {
                Log::info("✅ SMS invitation sent successfully to: {$phoneNumber}");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error sending SMS invitation to extension officer {$extensionOfficer->email}: " . $e->getMessage());
        }
    }

    /**
     * Send email invitation to extension officer
     */
    private function sendExtensionOfficerInvitationEmail(
        ExtensionOfficer $extensionOfficer,
        Farmer $farmer,
        string $accessCode,
        ?string $farmName = null
    ): void {
        try {
            Log::info("📧 Sending extension officer invitation email to: {$extensionOfficer->email}");

            Mail::to($extensionOfficer->email)->send(
                new ExtensionOfficerInvitationMail($extensionOfficer, $farmer, $accessCode, $farmName)
            );

            Log::info("✅ Email invitation sent successfully to: {$extensionOfficer->email}");
        } catch (\Exception $e) {
            Log::error("❌ Error sending email invitation to extension officer {$extensionOfficer->email}: " . $e->getMessage());
            Log::error("❌ Stack trace: " . $e->getTraceAsString());
        }
    }
}
