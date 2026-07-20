<?php

namespace ClarionApp\LifeLogBackend\Exceptions;

use RuntimeException;

/**
 * Two packages tried to register a health service under the same name.
 *
 * This throws rather than letting the last registration win, which is a
 * deliberate divergence from every other registry in this codebase. Elsewhere a
 * replaced entry produces the same answers by a different route. Here it does
 * not: a silently replaced health service routes a user's readings through a
 * different type mapping and a different unit conversion, and the mis-mapped
 * values land in permanent, replicated history where nothing downstream can
 * distinguish them from correct ones.
 *
 * The collision is also load-bearing on the storage side. Rows are deduplicated
 * on (external_service, external_id), so two services sharing a name means one
 * service's readings overwrite the other's whenever their ids happen to
 * coincide.
 *
 * Failing at boot makes the conflict a deployment problem, which is where it
 * can still be fixed.
 */
class DuplicateServiceRegistrationException extends RuntimeException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct(
            "A health service is already registered as '{$name}'. Registration refuses to overwrite: "
            . 'the replaced service would keep writing rows under this name through a different type '
            . 'mapping, and the mis-mapped values are indistinguishable from correct ones once they '
            . 'reach permanent history. Give one of the two services a distinct name.'
        );
    }
}
