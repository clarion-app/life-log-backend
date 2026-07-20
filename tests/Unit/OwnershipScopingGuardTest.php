<?php

namespace Tests\Unit;

use ClarionApp\Backend\Models\User;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ownership is enforced in the query builder, not by fetching a row and
 * then comparing its owner. The distinction matters: a fetched-then-checked
 * row is in memory, one early return away from being acted on.
 */
class OwnershipScopingGuardTest extends TestCase
{
    protected User $userA;
    protected User $userB;
    protected ConnectedAccount $accountB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'User A',
            'email' => 'a@example.com',
            'password' => 'hashed',
        ]);

        $this->userB = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'User B',
            'email' => 'b@example.com',
            'password' => 'hashed',
        ]);

        $this->accountB = ConnectedAccount::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userB->id,
            'external_service' => 'fake-step',
            'sync_state' => 'normal',
            'connected_at' => now()->subDay(),
        ]);

        $this->actingAs($this->userA);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
    }

    /**
     * Record the ids of every ConnectedAccount hydrated from the database
     * for the duration of the callback.
     *
     * @return array<int, string>
     */
    protected function retrievedAccountIdsDuring(callable $action): array
    {
        $retrieved = [];

        ConnectedAccount::retrieved(function (ConnectedAccount $account) use (&$retrieved) {
            $retrieved[] = $account->id;
        });

        try {
            $action();
        } finally {
            ConnectedAccount::flushEventListeners();
        }

        return $retrieved;
    }

    /** @test */
    public function showNeverHydratesAnotherUsersRow()
    {
        $retrieved = $this->retrievedAccountIdsDuring(function () {
            $this->getJson(
                "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}"
            )->assertStatus(404);
        });

        $this->assertNotContains(
            $this->accountB->id,
            $retrieved,
            'show() loaded another user\'s row into memory before refusing.'
        );
    }

    /** @test */
    public function syncNeverHydratesAnotherUsersRow()
    {
        Queue::fake();

        $retrieved = $this->retrievedAccountIdsDuring(function () {
            $this->postJson(
                "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}/sync"
            )->assertStatus(404);
        });

        $this->assertNotContains(
            $this->accountB->id,
            $retrieved,
            'sync() loaded another user\'s row into memory before refusing.'
        );
    }

    /** @test */
    public function theOwnerPredicateIsInTheSqlNotAppliedAfterwards()
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $this->getJson(
            "/api/clarion-app/life-log/connected-accounts/{$this->accountB->id}"
        )->assertStatus(404);

        $accountQueries = array_values(array_filter(
            $queries,
            fn (array $q) => str_contains($q['sql'], 'life_log_connected_accounts')
        ));

        $this->assertNotEmpty($accountQueries, 'Expected a query against the connected accounts table.');

        foreach ($accountQueries as $query) {
            $this->assertStringContainsString(
                'user_id',
                $query['sql'],
                'The connection lookup must carry a user_id predicate in SQL.'
            );

            $this->assertContains(
                $this->userA->id,
                array_map(fn ($b) => (string) $b, $query['bindings']),
                'The user_id predicate must be bound to the authenticated user.'
            );
        }
    }

    /** @test */
    public function theOwnerLookupStillHydratesTheOwnRow()
    {
        $accountA = ConnectedAccount::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userA->id,
            'external_service' => 'fake-step',
            'sync_state' => 'normal',
            'connected_at' => now()->subDay(),
        ]);

        $retrieved = $this->retrievedAccountIdsDuring(function () use ($accountA) {
            $this->getJson(
                "/api/clarion-app/life-log/connected-accounts/{$accountA->id}"
            )->assertStatus(200);
        });

        $this->assertContains($accountA->id, $retrieved);
    }
}
