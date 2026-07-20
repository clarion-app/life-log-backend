<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\ServiceCredential;
use ClarionApp\LifeLogBackend\Models\ConnectionAttempt;
use ClarionApp\LifeLogBackend\Models\AccountAuthorization;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;

class BridgeExclusionTest extends TestCase
{
    /** @test T012 — ServiceCredential publishes to the bridge (it is bridged) */
    public function serviceCredentialPublishesToBridge(): void
    {
        $chain = $this->enableRecordingBridge();

        // Add data_stream_registries entry for ServiceCredential
        \Illuminate\Support\Facades\DB::table('data_stream_registries')->insertOrIgnore([
            [
                'class_name' => ServiceCredential::class,
                'data_stream' => 'life_log_service_credentials',
            ],
        ]);

        $credential = new ServiceCredential();
        $credential->external_service = 'test-service';
        $credential->client_id = 'app-123';
        $credential->client_secret = 'secret-value';
        $credential->redirect_uri = 'https://example.com/callback';
        $credential->save();

        $this->assertCount(1, $chain->published);
        $this->assertEquals('life_log_service_credentials', $chain->published[0]['stream']);
    }

    /** @test T012 — ConnectionAttempt publishes nothing to the bridge (not bridged) */
    public function connectionAttemptPublishesNothingToBridge(): void
    {
        $chain = $this->enableRecordingBridge();

        $attempt = new ConnectionAttempt();
        $attempt->user_id = '00000000-0000-0000-0000-000000000001';
        $attempt->external_service = 'test-service';
        $attempt->state_hash = hash('sha256', 'some-state');
        $attempt->redirect_uri = 'https://example.com/callback';
        $attempt->expires_at = now()->addHour();
        $attempt->save();

        $this->assertCount(0, $chain->published);
    }

    /** @test T012 — AccountAuthorization publishes nothing to the bridge (not bridged) */
    public function accountAuthorizationPublishesNothingToBridge(): void
    {
        $chain = $this->enableRecordingBridge();

        // Add data_stream_registries entry for ConnectedAccount (it is bridged)
        \Illuminate\Support\Facades\DB::table('data_stream_registries')->insertOrIgnore([
            [
                'class_name' => ConnectedAccount::class,
                'data_stream' => 'life_log_connected_accounts',
            ],
        ]);

        $account = ConnectedAccount::create([
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'external_service' => 'test-service',
        ]);

        $auth = new AccountAuthorization();
        $auth->connected_account_id = $account->id;
        $auth->access_token = 'access-token';
        $auth->refresh_token = 'refresh-token';
        $auth->credential_version = 1;
        $auth->save();

        // ConnectedAccount is bridged so it publishes one event.
        // AccountAuthorization is NOT bridged so it publishes nothing.
        $this->assertCount(1, $chain->published);
        $this->assertEquals('life_log_connected_accounts', $chain->published[0]['stream']);
    }
}
