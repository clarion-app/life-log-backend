<?php

namespace ClarionApp\LifeLogBackend\Commands;

use ClarionApp\LifeLogBackend\Services\SessionPromoter;
use Illuminate\Console\Command;

class PromoteSessionsCommand extends Command
{
    protected $signature = 'life-log:promote-sessions';
    protected $description = 'Promote raw health sessions into permanent, replicated session history';

    public function handle(SessionPromoter $promoter): int
    {
        $this->info('Starting session promotion...');

        $result = $promoter->run();

        $this->info(sprintf(
            'Promotion complete: %d sessions promoted, %d skipped',
            $result['promoted'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
