<?php

namespace ClarionApp\LifeLogBackend\Commands;

use ClarionApp\LifeLogBackend\Google\Api\GoogleHealthClient;
use ClarionApp\LifeLogBackend\Google\Mapping\AggregateSource;
use ClarionApp\LifeLogBackend\Google\Probe\NotWornExclusionProbe;
use ClarionApp\LifeLogBackend\Models\ConnectedAccount;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use Illuminate\Console\Command;
use GuzzleHttp\Client;

/**
 * Probe whether the provider's rollup excludes not-worn hours.
 *
 * Thin shell: resolves the account, delegates to NotWornExclusionProbe,
 * renders the returned diff. Names no provider-specific endpoint,
 * parameter, or JSON key.
 *
 * This command is excluded from the test suite — it requires network
 * access and a real connected account.
 */
class ProbeNotWornExclusionCommand extends Command
{
    protected $signature = 'life-log:probe-not-worn-exclusion {account} {type} {--since=} {--until=}';

    protected $description = 'Compare provider rollup vs local rollup for not-worn exclusion';

    public function handle(): int
    {
        $account = ConnectedAccount::find($this->argument('account'));

        if (!$account) {
            $this->error("Account {$this->argument('account')} not found.");
            return self::FAILURE;
        }

        $typeString = $this->argument('type');
        $type = $this->resolveType($typeString);

        if ($type === null) {
            $this->error("Unknown measurement type: {$typeString}");
            return self::FAILURE;
        }

        $since = $this->option('since')
            ? \Carbon\CarbonImmutable::parse($this->option('since'))
            : \Carbon\CarbonImmutable::now()->subDays(1);

        $until = $this->option('until')
            ? \Carbon\CarbonImmutable::parse($this->option('until'))
            : \Carbon\CarbonImmutable::now();

        // Build two clients: one for provider rollup, one for local rollup
        $auth = $account->authorization;
        if (!$auth || !($auth->access_token)) {
            $this->error('Account has no valid authorization.');
            return self::FAILURE;
        }

        $httpClient = new Client();

        $rollUpClient = new GoogleHealthClient(
            $httpClient,
            $auth->access_token,
            AggregateSource::ProviderRollUp,
        );

        $listClient = new GoogleHealthClient(
            $httpClient,
            $auth->access_token,
            AggregateSource::LocalRollup,
        );

        $probe = new NotWornExclusionProbe($rollUpClient, $listClient);

        $this->info("Probing {$typeString} for account {$account->id} ({$since->format('Y-m-d')} → {$until->format('Y-m-d')})...");

        try {
            $results = $probe->run($account, $type, $since, $until);
        } catch (\Throwable $e) {
            $this->error("Probe failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        if (empty($results)) {
            $this->info('No data returned for the specified window.');
            return self::SUCCESS;
        }

        $mismatches = 0;
        $noDataHours = 0;

        foreach ($results as $result) {
            $status = $result->match ? '✓' : '✗';

            if (!$result->match) {
                $mismatches++;
            }

            if ($result->providerRollUpValue === null && $result->localRollUpValue === null) {
                $noDataHours++;
                $providerVal = '—';
                $localVal = '—';
            } else {
                $providerVal = $result->providerRollUpValue ?? '—';
                $localVal = $result->localRollUpValue ?? '—';
            }

            $this->line(
                sprintf('%s %s | Provider: %-12s | Local: %-12s',
                    $status,
                    $result->hour->format('Y-m-d H:i'),
                    $providerVal,
                    $localVal,
                )
            );
        }

        $this->newLine();
        $this->info("Total hours: " . count($results));
        $this->info("Matches: " . (count($results) - $mismatches));
        $this->info("Mismatches: {$mismatches}");
        $this->info("No-data hours: {$noDataHours}");

        if ($mismatches > 0) {
            $this->warn('Mismatches detected — the provider may exclude not-worn hours differently than local aggregation.');
        }

        return self::SUCCESS;
    }

    private function resolveType(string $typeString): ?MeasurementType
    {
        foreach (MeasurementType::cases() as $case) {
            if ($case->value === $typeString) {
                return $case;
            }
        }
        return null;
    }
}
