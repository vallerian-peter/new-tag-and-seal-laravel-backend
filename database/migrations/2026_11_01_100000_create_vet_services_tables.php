<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-demand veterinary services ("Bolt ya Madaktari wa Mifugo").
 *
 * Extends the existing `vets` table into a real professional profile linked to a
 * user account, and adds the request / offer / timeline / message / rating tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vets', function (Blueprint $table) {
            $table->string('uuid', 100)->nullable()->unique()->after('id');
            $table->foreignId('userId')->nullable()->unique()->after('uuid')
                ->constrained('users')->nullOnDelete();

            // Professional credentials
            $table->string('licenseAuthority')->nullable()->after('medicalLicenseNo');
            $table->date('licenseExpiryDate')->nullable()->after('licenseAuthority');
            $table->string('licenseDocumentPath')->nullable()->after('licenseExpiryDate');
            $table->json('specializations')->nullable()->after('licenseDocumentPath');
            $table->unsignedTinyInteger('yearsOfExperience')->nullable()->after('specializations');
            $table->string('clinicName')->nullable()->after('yearsOfExperience');
            $table->text('bio')->nullable()->after('clinicName');

            // Service coverage and live position
            $table->json('serviceAreaDistrictIds')->nullable()->after('bio');
            $table->unsignedSmallInteger('serviceRadiusKm')->default(30)->after('serviceAreaDistrictIds');
            $table->decimal('latitude', 10, 7)->nullable()->after('serviceRadiusKm');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('locationUpdatedAt')->nullable()->after('longitude');
            $table->boolean('isAvailable')->default(false)->after('locationUpdatedAt');

            // Verification workflow (admin)
            $table->enum('verificationStatus', ['pending', 'verified', 'rejected'])
                ->default('pending')->after('isAvailable');
            $table->timestamp('verifiedAt')->nullable()->after('verificationStatus');
            $table->foreignId('verifiedBy')->nullable()->after('verifiedAt')
                ->constrained('users')->nullOnDelete();
            $table->text('verificationNotes')->nullable()->after('verifiedBy');

            // Reputation aggregates (denormalised for fast nearby queries)
            $table->decimal('ratingAverage', 3, 2)->default(0)->after('verificationNotes');
            $table->unsignedInteger('ratingCount')->default(0)->after('ratingAverage');
            $table->unsignedInteger('completedJobsCount')->default(0)->after('ratingCount');

            $table->index(['latitude', 'longitude'], 'vets_position_idx');
            $table->index(['verificationStatus', 'isAvailable'], 'vets_dispatchable_idx');
        });

        // Self-registration does not collect everything the admin form did.
        Schema::table('vets', function (Blueprint $table) {
            $table->enum('gender', ['male', 'female'])->nullable()->change();
            $table->date('dateOfBirth')->nullable()->change();
            $table->unsignedBigInteger('countryId')->nullable()->change();
            $table->unsignedBigInteger('regionId')->nullable()->change();
            $table->unsignedBigInteger('districtId')->nullable()->change();
        });

        Schema::create('vet_service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->foreignId('farmerUserId')->constrained('users')->cascadeOnDelete();
            $table->string('farmUuid', 100)->index();
            $table->string('livestockUuid', 100)->nullable()->index();
            // Copy of the animal's identity so the vet UI never needs farm access.
            $table->json('livestockSnapshot')->nullable();

            $table->foreignId('vetId')->nullable()->constrained('vets')->nullOnDelete();
            $table->foreignId('preferredVetId')->nullable()->constrained('vets')->nullOnDelete();

            $table->string('problemCategory', 50);
            $table->text('description');
            $table->enum('urgency', ['low', 'normal', 'high', 'emergency'])->default('normal');
            $table->json('symptoms')->nullable();
            $table->json('photos')->nullable();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('locationAddress')->nullable();
            $table->string('contactPhone', 30)->nullable();

            $table->enum('status', [
                'pending', 'accepted', 'travelling', 'arrived', 'in_progress',
                'completed', 'cancelled', 'expired',
            ])->default('pending');

            $table->unsignedSmallInteger('searchRadiusKm')->default(30);
            $table->unsignedTinyInteger('dispatchRound')->default(0);
            $table->unsignedSmallInteger('offersCount')->default(0);

            $table->timestamp('acceptedAt')->nullable();
            $table->timestamp('travellingAt')->nullable();
            $table->timestamp('arrivedAt')->nullable();
            $table->timestamp('startedAt')->nullable();
            $table->timestamp('completedAt')->nullable();
            $table->timestamp('cancelledAt')->nullable();
            $table->timestamp('expiresAt')->nullable();
            $table->foreignId('cancelledByUserId')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelReason')->nullable();

            $table->string('treatmentUuid', 100)->nullable();
            $table->json('treatmentSummary')->nullable();

            $table->unsignedTinyInteger('farmerRating')->nullable();
            $table->text('farmerReview')->nullable();
            $table->timestamp('ratedAt')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamp('lastActivityAt')->nullable()->index();
            $table->timestamps();

            $table->index(['farmerUserId', 'status']);
            $table->index(['vetId', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('vet_service_request_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requestId')->constrained('vet_service_requests')->cascadeOnDelete();
            $table->foreignId('vetId')->constrained('vets')->cascadeOnDelete();
            $table->decimal('distanceKm', 6, 2)->nullable();
            $table->unsignedTinyInteger('round')->default(1);
            $table->enum('status', ['offered', 'accepted', 'declined', 'expired', 'withdrawn'])->default('offered');
            $table->string('declineReason')->nullable();
            $table->timestamp('notifiedAt')->nullable();
            $table->timestamp('respondedAt')->nullable();
            $table->timestamps();

            $table->unique(['requestId', 'vetId']);
            $table->index(['vetId', 'status']);
        });

        Schema::create('vet_service_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requestId')->constrained('vet_service_requests')->cascadeOnDelete();
            $table->string('type', 40);
            $table->foreignId('actorUserId')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fromStatus', 20)->nullable();
            $table->string('toStatus', 20)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['requestId', 'id']);
        });

        Schema::create('vet_service_messages', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->foreignId('requestId')->constrained('vet_service_requests')->cascadeOnDelete();
            $table->foreignId('senderUserId')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('attachmentPath')->nullable();
            $table->timestamp('readAt')->nullable();
            $table->timestamps();

            $table->index(['requestId', 'id']);
        });

        Schema::create('vet_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requestId')->unique()->constrained('vet_service_requests')->cascadeOnDelete();
            $table->foreignId('vetId')->constrained('vets')->cascadeOnDelete();
            $table->foreignId('farmerUserId')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('review')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index('vetId');
        });

        Schema::table('treatments', function (Blueprint $table) {
            $table->foreignId('vetId')->nullable()->after('remarks')->constrained('vets')->nullOnDelete();
            $table->string('serviceRequestUuid', 100)->nullable()->after('vetId')->index();
        });
    }

    public function down(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vetId');
            $table->dropIndex(['serviceRequestUuid']);
            $table->dropColumn('serviceRequestUuid');
        });

        Schema::dropIfExists('vet_ratings');
        Schema::dropIfExists('vet_service_messages');
        Schema::dropIfExists('vet_service_request_events');
        Schema::dropIfExists('vet_service_request_offers');
        Schema::dropIfExists('vet_service_requests');

        Schema::table('vets', function (Blueprint $table) {
            $table->dropIndex('vets_position_idx');
            $table->dropIndex('vets_dispatchable_idx');
            $table->dropConstrainedForeignId('verifiedBy');
            $table->dropConstrainedForeignId('userId');
            $table->dropColumn([
                'uuid', 'licenseAuthority', 'licenseExpiryDate', 'licenseDocumentPath',
                'specializations', 'yearsOfExperience', 'clinicName', 'bio',
                'serviceAreaDistrictIds', 'serviceRadiusKm', 'latitude', 'longitude',
                'locationUpdatedAt', 'isAvailable', 'verificationStatus', 'verifiedAt',
                'verificationNotes', 'ratingAverage', 'ratingCount', 'completedJobsCount',
            ]);
        });
    }
};
