<?php

namespace Tests\Unit;

use Tests\TestCase;
use ClarionApp\LifeLogBackend\Models\HealthMetric;
use ClarionApp\Backend\Models\User;
use Illuminate\Support\Facades\Auth;
use Carbon\CarbonImmutable;

/**
 * T037-T038: Characterization tests for manual entry backward compatibility.
 *
 * These tests cover the full manual lifecycle using ONLY pre-feature fields
 * (type, value, recorded_at). From Phase 5 onward, this file is frozen —
 * it must pass unmodified through Phases 6-9 (SC-007).
 */
class ManualEntryBackwardCompatibilityTest extends TestCase
{
    protected string $userId;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = '00000000-0000-0000-0000-000000000001';
    }

    /** @test scenario 6.1: create returns 201 with all previously-returned fields */
    public function createReturns201WithAllFields(): void
    {
        $response = $this->actingAsUser()->postJson('/api/clarion-app/life-log/health-metric', [
            'type' => 'blood_pressure',
            'value' => 120.5,
            'recorded_at' => '2026-07-19 09:00:00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'type', 'value', 'recorded_at', 'created_at', 'updated_at']);
        $this->assertEquals(1, HealthMetric::count());
    }

    /** @test scenario 6.2: index returns user metrics */
    public function indexReturnsUserMetrics(): void
    {
        $this->actingAsUser();
        HealthMetric::create([
            'user_id' => $this->userId,
            'type' => 'blood_pressure',
            'value' => 120.5,
            'recorded_at' => CarbonImmutable::parse('2026-07-19 09:00:00'),
        ]);

        $response = $this->getJson('/api/clarion-app/life-log/health-metric');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    }

    /** @test scenario 6.2: show returns the metric */
    public function showReturnsMetric(): void
    {
        $this->actingAsUser();
        $metric = HealthMetric::create([
            'user_id' => $this->userId,
            'type' => 'blood_pressure',
            'value' => 120.5,
            'recorded_at' => CarbonImmutable::parse('2026-07-19 09:00:00'),
        ]);

        $response = $this->getJson('/api/clarion-app/life-log/health-metric/' . $metric->id);

        $response->assertStatus(200);
        $response->assertJsonFragment(['type' => 'blood_pressure', 'value' => '120.5000']);
    }

    /** @test scenario 6.2: update modifies the metric */
    public function updateModifiesMetric(): void
    {
        $this->actingAsUser();
        $metric = HealthMetric::create([
            'user_id' => $this->userId,
            'type' => 'blood_pressure',
            'value' => 120.5,
            'recorded_at' => CarbonImmutable::parse('2026-07-19 09:00:00'),
        ]);

        $response = $this->putJson('/api/clarion-app/life-log/health-metric/' . $metric->id, [
            'type' => 'blood_pressure',
            'value' => 130.0,
            'recorded_at' => '2026-07-19 10:00:00',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['value' => '130.0000']);
        $this->assertEquals('130.0000', HealthMetric::find($metric->id)->value);
    }

    /** @test scenario 6.2: destroy removes the metric */
    public function destroyRemovesMetric(): void
    {
        $this->actingAsUser();
        $metric = HealthMetric::create([
            'user_id' => $this->userId,
            'type' => 'blood_pressure',
            'value' => 120.5,
            'recorded_at' => CarbonImmutable::parse('2026-07-19 09:00:00'),
        ]);

        $response = $this->deleteJson('/api/clarion-app/life-log/health-metric/' . $metric->id);

        $response->assertStatus(204);
        $this->assertEquals(0, HealthMetric::withoutTrashed()->count());
    }

    /** @test FR-022 scenario 6.3: free-form type like blood_pressure is accepted */
    public function freeFormTypeIsAccepted(): void
    {
        $response = $this->actingAsUser()->postJson('/api/clarion-app/life-log/health-metric', [
            'type' => 'blood_pressure',
            'value' => 120.5,
            'recorded_at' => '2026-07-19 09:00:00',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('blood_pressure', HealthMetric::first()->type);
    }

    /** @test scenario 6.4: everything works with an empty raw store */
    public function worksWithEmptyRawStore(): void
    {
        // Raw store is empty by default in the test harness
        $response = $this->actingAsUser()->postJson('/api/clarion-app/life-log/health-metric', [
            'type' => 'cholesterol',
            'value' => 200.0,
            'recorded_at' => '2026-07-19 09:00:00',
        ]);

        $response->assertStatus(201);
    }

    /** @test T038: decimal:4 cast does not change create/show response shape */
    public function decimalCastDoesNotChangeResponseShape(): void
    {
        $response = $this->actingAsUser()->postJson('/api/clarion-app/life-log/health-metric', [
            'type' => 'weight',
            'value' => 75.5,
            'recorded_at' => '2026-07-19 09:00:00',
        ]);

        $response->assertStatus(201);
        $responseData = $response->json();

        // value should be present and readable
        $this->assertArrayHasKey('value', $responseData);
        $this->assertIsString($responseData['value']);
        $this->assertEquals('75.5000', $responseData['value']);
    }

    /**
     * Helper: create and return an authenticated test user, then act as them.
     */
    protected function actingAsUser()
    {
        $user = \ClarionApp\Backend\Models\User::find($this->userId);

        if (!$user) {
            $user = \ClarionApp\Backend\Models\User::forceCreate([
                'id' => $this->userId,
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => bcrypt('password'),
            ]);
        }

        $this->actingAs($user, 'api');

        return $this;
    }
}
