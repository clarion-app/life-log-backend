<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

/**
 * The credentials life-log presents to one external service.
 *
 * At most one live row per service per instance. Bridged for replication
 * across nodes (FR-008).
 */
class ServiceCredential extends Model
{
    use EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'life_log_service_credentials';

    protected $fillable = [
        'external_service',
        'client_id',
        'client_secret',
        'redirect_uri',
        'version',
        'secret_updated_at',
        'last_verified_at',
        'last_verification_outcome',
    ];

    protected $attributes = [
        'version' => 1,
    ];

    protected $casts = [
        'client_secret'         => 'encrypted',
        'version'               => 'integer',
        'secret_updated_at'     => 'datetime',
        'last_verified_at'      => 'datetime',
    ];

    // Deliberately NOT $hidden — see research.md §2. $hidden strips from toArray(),
    // which is what the bridge publishes, and FR-008 requires the secret to replicate.
    // The secret is kept out of responses by explicit shaping instead.

    /**
     * Scope to live (non-deleted) credentials for a given service.
     */
    public function scopeLiveByService(Builder $query, string $externalService): Builder
    {
        return $query->where('external_service', $externalService)->whereNull('deleted_at');
    }

    /**
     * Build a safe, secret-free array for API responses.
     *
     * This is an allow-list, not a deny-list. A future column is excluded
     * by default rather than included by default.
     */
    public function toPublicArray(): array
    {
        return [
            'external_service'          => $this->external_service,
            'client_id'                 => $this->client_id,
            'redirect_uri'              => $this->redirect_uri,
            'has_secret'                => $this->client_secret !== null && $this->client_secret !== '',
            'secret_updated_at'         => $this->secret_updated_at,
            'last_verified_at'          => $this->last_verified_at,
            'last_verification_outcome' => $this->last_verification_outcome,
            'version'                   => $this->version,
        ];
    }

    /**
     * Redact the secret for debug output (dd(), stack traces, queue dumps).
     */
    public function __debugInfo(): array
    {
        return $this->toPublicArray() + ['client_secret' => '[redacted]'];
    }
}
