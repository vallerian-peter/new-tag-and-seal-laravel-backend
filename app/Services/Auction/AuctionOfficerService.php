<?php

namespace App\Services\Auction;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\SendVetServiceSms;
use App\Models\AuctionCentre;
use App\Models\AuctionCentreMember;
use App\Models\AuctionOfficer;
use App\Models\User;
use App\Services\VetServices\VetServiceException as AuctionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Officer lifecycle: self-registration, portal creation, verification and
 * the gate that keeps unverified officers from conducting auctions.
 */
class AuctionOfficerService
{
    public function officerForUser(User $user): ?AuctionOfficer
    {
        return AuctionOfficer::where('userId', $user->id)->first();
    }

    /**
     * True when the account may conduct auctions as far as officer
     * verification is concerned. Accounts without an officer profile
     * (farmers, admins) are not gated here.
     */
    public function clearedToConduct(User $user): bool
    {
        $officer = $this->officerForUser($user);

        return $officer === null || $officer->isVerified();
    }

    /** An existing account applies to become an officer at a centre. */
    public function apply(User $user, array $data): AuctionOfficer
    {
        if ($this->officerForUser($user)) {
            throw new AuctionException('You already have an auction officer profile.', 409, 'ALREADY_APPLIED');
        }
        $centre = AuctionCentre::where('uuid', $data['centreUuid'])->first();
        if (! $centre || ! $centre->isActive()) {
            throw new AuctionException('Auction centre not found.', 404, 'CENTRE_NOT_FOUND');
        }

        return DB::transaction(function () use ($user, $data, $centre) {
            $officer = AuctionOfficer::create([
                'userId' => $user->id,
                'firstName' => $data['firstName'],
                'middleName' => $data['middleName'] ?? null,
                'lastName' => $data['lastName'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? $user->email,
                'nationalId' => $data['nationalId'] ?? null,
                'licenceNo' => $data['licenceNo'] ?? null,
                'address' => $data['address'] ?? null,
                'regionId' => $data['regionId'] ?? null,
                'districtId' => $data['districtId'] ?? null,
                'requestedCentreId' => $centre->id,
                'bio' => $data['bio'] ?? null,
                'verificationStatus' => AuctionOfficer::PENDING,
                'status' => 'active',
            ]);
            AuctionCentreMember::updateOrCreate(
                ['centreId' => $centre->id, 'userId' => $user->id],
                ['role' => AuctionCentre::ROLE_AUCTIONEER, 'status' => AuctionCentreMember::STATUS_PENDING],
            );

            return $officer;
        });
    }

    /** Link a profile created during /auth/register to its new account. */
    public function attachRegisteredAccount(AuctionOfficer $officer, User $user): void
    {
        $officer->update(['userId' => $user->id]);
        if ($officer->requestedCentreId) {
            AuctionCentreMember::updateOrCreate(
                ['centreId' => $officer->requestedCentreId, 'userId' => $user->id],
                ['role' => AuctionCentre::ROLE_AUCTIONEER, 'status' => AuctionCentreMember::STATUS_PENDING],
            );
        }
    }

    /**
     * Portal creates a ready-to-work officer: account + verified profile +
     * active membership. The login details are texted to the officer.
     */
    public function createByAdmin(User $admin, array $data): AuctionOfficer
    {
        $centre = AuctionCentre::where('uuid', $data['centreUuid'])->first();
        if (! $centre) {
            throw new AuctionException('Auction centre not found.', 404, 'CENTRE_NOT_FOUND');
        }
        if (User::where('email', $data['email'])->exists()) {
            throw new AuctionException('An account with this email already exists. Attach it as a member instead.', 409, 'EMAIL_TAKEN');
        }
        $username = $data['username'] ?? strstr($data['email'], '@', true);
        if (User::where('username', $username)->exists()) {
            $username .= '_'.strtolower(Str::random(4));
        }
        $plainPassword = $data['password'] ?? Str::password(10, symbols: false);

        return DB::transaction(function () use ($admin, $data, $centre, $username, $plainPassword) {
            $officer = AuctionOfficer::create([
                'firstName' => $data['firstName'],
                'middleName' => $data['middleName'] ?? null,
                'lastName' => $data['lastName'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'nationalId' => $data['nationalId'] ?? null,
                'licenceNo' => $data['licenceNo'] ?? null,
                'address' => $data['address'] ?? null,
                'regionId' => $data['regionId'] ?? null,
                'districtId' => $data['districtId'] ?? null,
                'requestedCentreId' => $centre->id,
                'verificationStatus' => AuctionOfficer::VERIFIED,
                'verifiedBy' => $admin->id,
                'verifiedAt' => now(),
                'status' => 'active',
                'createdBy' => $admin->id,
            ]);
            $user = User::create([
                'username' => $username,
                'email' => $data['email'],
                'password' => Hash::make($plainPassword),
                'role' => UserRole::AUCTION_OFFICER,
                'roleId' => $officer->id,
                'status' => UserStatus::ACTIVE,
                'createdBy' => $admin->id,
            ]);
            $officer->update(['userId' => $user->id]);
            AuctionCentreMember::updateOrCreate(
                ['centreId' => $centre->id, 'userId' => $user->id],
                [
                    'role' => $data['role'] ?? AuctionCentre::ROLE_AUCTIONEER,
                    'status' => AuctionCentreMember::STATUS_ACTIVE,
                    'assignedBy' => $admin->id,
                ],
            );

            DB::afterCommit(function () use ($officer, $user, $plainPassword, $centre) {
                $msg = "Tag & Seal: You have been registered as an auction officer at {$centre->name}. "
                    ."Login: {$user->email}  Password: {$plainPassword}. Please change it after your first sign in.";
                SendVetServiceSms::dispatchAfterResponse([$officer->phone], $msg);
            });

            return $officer;
        });
    }

    /** Approve or reject an officer; approval activates the centre membership. */
    public function verify(AuctionOfficer $officer, User $admin, string $decision, ?string $notes = null, ?AuctionCentre $centre = null, ?string $role = null): AuctionOfficer
    {
        $approved = $decision === AuctionOfficer::VERIFIED;

        return DB::transaction(function () use ($officer, $admin, $approved, $decision, $notes, $centre, $role) {
            $officer->update([
                'verificationStatus' => $decision,
                'verificationNotes' => $notes,
                'verifiedBy' => $approved ? $admin->id : null,
                'verifiedAt' => $approved ? now() : null,
            ]);
            if ($officer->userId) {
                if ($approved) {
                    $target = $centre ?? $officer->requestedCentre;
                    if ($target) {
                        AuctionCentreMember::updateOrCreate(
                            ['centreId' => $target->id, 'userId' => $officer->userId],
                            [
                                'role' => $role ?? AuctionCentre::ROLE_AUCTIONEER,
                                'status' => AuctionCentreMember::STATUS_ACTIVE,
                                'assignedBy' => $admin->id,
                            ],
                        );
                    }
                    // Any other pending requests by the same officer become active too.
                    AuctionCentreMember::where('userId', $officer->userId)
                        ->where('status', AuctionCentreMember::STATUS_PENDING)
                        ->update(['status' => AuctionCentreMember::STATUS_ACTIVE, 'assignedBy' => $admin->id]);
                } else {
                    AuctionCentreMember::where('userId', $officer->userId)
                        ->update(['status' => AuctionCentreMember::STATUS_INACTIVE]);
                }
            }

            DB::afterCommit(function () use ($officer, $approved, $notes) {
                if (! $officer->phone) {
                    return;
                }
                $msg = $approved
                    ? 'Tag & Seal: Your auction officer application has been approved. Open the app to see auctions assigned to you.'
                    : 'Tag & Seal: Your auction officer application was not approved.'.($notes ? " Reason: {$notes}" : '');
                SendVetServiceSms::dispatchAfterResponse([$officer->phone], $msg);
            });

            return $officer->refresh();
        });
    }

    /** Deactivate an officer and freeze their centre memberships. */
    public function deactivate(AuctionOfficer $officer): AuctionOfficer
    {
        $officer->update(['status' => 'inactive']);
        if ($officer->userId) {
            AuctionCentreMember::where('userId', $officer->userId)->update(['status' => AuctionCentreMember::STATUS_INACTIVE]);
        }

        return $officer;
    }
}
