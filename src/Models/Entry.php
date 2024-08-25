<?php

namespace ClarionApp\LifeLogBackend\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\ContactsBackend\Models\Contact;

class Entry extends Model
{
    use EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'life_log_entries';

    public function contacts()
    {
        return $this->belongsToMany(Contact::class, 'life_log_contact_entry');
    }
}
