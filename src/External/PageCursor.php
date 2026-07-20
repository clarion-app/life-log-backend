<?php

namespace ClarionApp\LifeLogBackend\External;

use InvalidArgumentException;

/**
 * An opaque marker for where the next page of a fetch resumes (FR-012).
 *
 * The contents are the issuing service's business — an offset, a continuation
 * token, a high-water timestamp — but the container is deliberately just a
 * string, because a cursor has to survive being written to a database column and
 * read back days later. A Phase 1.3 backfill spans years, gets interrupted, and
 * resumes from whatever was persisted.
 *
 * That is why fromArray() rejects anything that is not a JSON-serializable
 * scalar. A closure, a hydrated model, or an open connection would work
 * perfectly in the same process and then fail only on the crash-recovery path,
 * which is the single hardest path to notice being broken.
 */
final readonly class PageCursor
{
    public function __construct(public string $value)
    {
        if ($this->value === '') {
            throw new InvalidArgumentException(
                'A page cursor cannot be empty — an empty cursor is indistinguishable from no cursor, '
                . 'which means "start the range over".'
            );
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Build a cursor from named scalar parts.
     *
     * @param  array<string, scalar|null>  $parts
     */
    public static function fromArray(array $parts): self
    {
        foreach ($parts as $key => $part) {
            if ($part !== null && !is_scalar($part)) {
                throw new InvalidArgumentException(
                    "Cursor key '{$key}' holds a " . get_debug_type($part) . '. A cursor must be storable '
                    . 'and re-supplied after a process restart, so its contents are limited to '
                    . 'JSON-serializable scalars.'
                );
            }
        }

        return new self(json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * The named parts back out again, for the service that issued them.
     *
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        $decoded = json_decode($this->value, true);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('This cursor was not built from named parts.');
        }

        return $decoded;
    }
}
