<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A durable record of something a service reported that could not be mapped
 * into the vocabulary.
 *
 * Non-bridged and not user-scoped: this is maintainer diagnostics about a
 * service's catalogue, not a person's health history, so it neither replicates
 * to the chain nor belongs to a user.
 */
class UnmappedTypeRecord extends Model
{
    protected $table = 'life_log_unmapped_type_records';

    protected $fillable = [
        'external_service', 'service_type_name', 'sample_value', 'sample_unit',
        'first_seen_at', 'last_seen_at', 'occurrence_count',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'occurrence_count' => 'integer',
    ];
}
