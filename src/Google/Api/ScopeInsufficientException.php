<?php

namespace ClarionApp\LifeLogBackend\Google\Api;

/**
 * Thrown when Google returns 403 ACCESS_TOKEN_SCOPE_INSUFFICIENT.
 *
 * This is a skip signal — the type should be skipped rather than treated
 * as a failure. The sync remains healthy; only the affected type is excluded.
 */
final class ScopeInsufficientException extends \RuntimeException
{
    public function __construct(string $dataTypeName)
    {
        parent::__construct("Scope insufficient for {$dataTypeName}");
    }
}
