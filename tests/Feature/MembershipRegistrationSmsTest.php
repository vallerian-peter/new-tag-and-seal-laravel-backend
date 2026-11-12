<?php

namespace Tests\Feature;

use App\Jobs\SendHiringSms;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MembershipRegistrationSmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_feature_registration_queues_one_kiswahili_sms(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $user = User::factory()->create(['role' => 'appUser', 'roleId' => 0]);
        $details = ['name' => 'Asha Juma', 'phone' => '0712345678', 'countryId' => $country, 'gender' => 'female'];

        $this->actingAs($user)->postJson('/api/v2/memberships', ['feature' => 'farmer', 'details' => $details])->assertOk();
        $this->actingAs($user)->postJson('/api/v2/memberships', [
            'feature' => 'farmer', 'details' => [...$details, 'name' => 'Asha Rehema'],
        ])->assertOk();

        $farmerNo = DB::table('farmers')->value('farmerNo');
        $sms = DB::table('hiring_notifications')->where('kind', 'membership_registered')->get();
        $this->assertCount(1, $sms);
        $this->assertSame("membership_registered:{$user->id}:farmer", $sms[0]->event_key);
        $this->assertSame('0712345678', $sms[0]->phone);
        $this->assertSame(
            "Tag & Seal: Hongera Asha Juma! Umesajiliwa kikamilifu kwenye uanachama wa Mkulima. Namba yako ya mkulima ni {$farmerNo}. Sasa unaweza kutumia huduma hii kwenye programu ya Tag & Seal.",
            $sms[0]->message_sw,
        );
        $this->assertSame($sms[0]->message_sw, $sms[0]->message_en);
    }

    public function test_each_feature_gets_its_own_registration_sms(): void
    {
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $user = User::factory()->create();
        $details = ['name' => 'Juma', 'phone' => '0712345678', 'countryId' => $country, 'skills' => ['Dairy']];

        $this->actingAs($user)->postJson('/api/v2/memberships', ['feature' => 'jobSeeker', 'details' => $details])->assertOk();
        $this->actingAs($user)->postJson('/api/v2/memberships', ['feature' => 'milking', 'details' => $details])->assertOk();

        $messages = DB::table('hiring_notifications')->orderBy('id')->pluck('message_sw')->all();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('uanachama wa Mtafuta Kazi.', $messages[0]);
        $this->assertStringContainsString('uanachama wa Maziwa.', $messages[1]);
    }

    public function test_kiswahili_only_notification_is_sent_without_a_second_language(): void
    {
        $message = 'Tag & Seal: Hongera! Umesajiliwa.';
        $id = DB::table('hiring_notifications')->insertGetId([
            'event_key' => 'membership_registered:test', 'phone' => '0712345678', 'kind' => 'membership_registered',
            'payload' => '{}', 'message_en' => $message, 'message_sw' => $message, 'sms_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendSms')->once()->with($message, '0712345678')->andReturn(['status' => 'ok']);

        (new SendHiringSms($id))->handle($sms);

        $this->assertDatabaseHas('hiring_notifications', ['id' => $id, 'sms_status' => 'sent']);
    }
}
