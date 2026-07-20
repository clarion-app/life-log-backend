<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\CarbonInterface;

/**
 * The provider authorization backing one connected account.
 *
 * One row per connected account. Not bridged (FR-010 — revocable, refreshes
 * frequently, stays on the node that obtained it).
 */
class AccountAuthorization extends Model
{
    protected $table = 'life_log_account_authorizations';

    protected $keyType = 'uuid';
    public $incrementing = false;

    protected $fillable = [
        'connected_account_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'credential_version',
        'refreshed_at',
    ];

    protected $casts = [
        'access_token'    => 'encrypted',
        'refresh_token'   => 'encrypted',
        'expires_at'      => 'datetime',
        'credential_version' => 'integer',
        'refreshed_at'    => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (! $model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Whether this authorization is usable through the given time.
     *
     * A null expires_at is treated as usable (unknown lifetime — renew
     * reactively on AccessExpired). The $until parameter lets the caller
     * ask "usable 60 seconds from now?" for proactive renewal.
     */
    public function isUsable(?CarbonInterface $until = null): bool
    {
        return $this->expires_at === null
            || $this->expires_at->isAfter($until ?? now());
    }

    /**
     * Redact tokens for debug output (dd(), stack traces, queue dumps).
     */
    public function __debugInfo(): array
    {
        return [
            'connected_account_id' => $this->connected_account_id,
            'expires_at'           => $this->expires_at,
            'credential_version'   => $this->credential_version,
            'access_token'         => '[redacted]',
            'refresh_token'        => '[redacted]',
        ];
    }
}
