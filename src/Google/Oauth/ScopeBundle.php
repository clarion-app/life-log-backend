<?php

namespace ClarionApp\LifeLogBackend\Google\Oauth;

use ClarionApp\LifeLogBackend\Google\Api\ApiVersion;
use ClarionApp\LifeLogBackend\Vocabulary\MeasurementType;
use ClarionApp\LifeLogBackend\Vocabulary\SessionType;

/**
 * The three scope bundles Google Health exposes (FR-002, FR-003).
 *
 * A user cannot decline heart rate while keeping steps — the bundle is the
 * atomic grant unit. The authorize URL requests exactly these three and
 * nothing else.
 *
 * FR-003a: the connection payload exposes granted bundles, each naming the
 * types it implies — never a per-type grant map.
 */
enum ScopeBundle: string
{
    case ActivityAndFitness = 'activity_and_fitness';
    case HealthMetrics      = 'health_metrics';
    case Sleep              = 'sleep';

    /**
     * The full OAuth scope string for this bundle.
     */
    public function scopeString(): string
    {
        return match ($this) {
            self::ActivityAndFitness => ApiVersion::BASE_URL . '/auth/googlehealth.activity_and_fitness.readonly',
            self::HealthMetrics      => ApiVersion::BASE_URL . '/auth/googlehealth.health_metrics_and_measurements.readonly',
            self::Sleep              => ApiVersion::BASE_URL . '/auth/googlehealth.sleep.readonly',
        };
    }

    /**
     * The vocabulary types this bundle covers.
     *
     * @return list<MeasurementType|SessionType>
     */
    public function covers(): array
    {
        return match ($this) {
            self::ActivityAndFitness => [
                MeasurementType::Steps,
                MeasurementType::HeartRate,
                MeasurementType::CaloriesBurned,
                SessionType::Workout,
            ],
            self::HealthMetrics => [
                MeasurementType::Weight,
            ],
            self::Sleep => [
                SessionType::Sleep,
            ],
        };
    }

    /**
     * All three bundles, in the order requested on the authorize URL.
     *
     * @return list<Self>
     */
    public static function all(): array
    {
        return [self::ActivityAndFitness, self::HealthMetrics, self::Sleep];
    }

    /**
     * Parse a scope string back to the bundle, or null if not recognised.
     */
    public static function fromScopeString(string $scopeString): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->scopeString() === $scopeString) {
                return $case;
            }
        }
        return null;
    }

    /**
     * Flat-map the granted bundles to the vocabulary types they cover.
     *
     * @param  list<MeasurementType|SessionType>  $supportedTypes  the service's full type list (dedup guard)
     * @return list<MeasurementType|SessionType>
     */
    public static function typesFor(array $grantedBundles, array $supportedTypes): array
    {
        $supportedValues = array_map(fn ($t) => match (true) {
            $t instanceof MeasurementType => $t->value,
            $t instanceof SessionType     => $t->value,
        }, $supportedTypes);

        $seen = [];
        $result = [];

        foreach ($grantedBundles as $granted) {
            $bundle = $granted instanceof self ? $granted : self::from($granted);
            foreach ($bundle->covers() as $type) {
                $typeValue = match (true) {
                    $type instanceof MeasurementType => $type->value,
                    $type instanceof SessionType     => $type->value,
                };
                if (!in_array($typeValue, $seen, true) && in_array($typeValue, $supportedValues, true)) {
                    $seen[] = $typeValue;
                    $result[] = $type;
                }
            }
        }

        return $result;
    }
}
