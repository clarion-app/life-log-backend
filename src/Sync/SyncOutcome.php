<?php

namespace ClarionApp\LifeLogBackend\Sync;

enum SyncOutcome: string
{
    case Success = 'success';
    case Partial = 'partial';
    case Failure = 'failure';
    case Skipped = 'skipped';
}
