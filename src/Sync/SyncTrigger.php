<?php

namespace ClarionApp\LifeLogBackend\Sync;

enum SyncTrigger: string
{
    case Scheduled = 'scheduled';
    case OnDemand = 'on_demand';
}
