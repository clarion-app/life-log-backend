<?php

namespace Tests\Support;

class RecordingMultiChain
{
    public array $published = [];

    public function publish($stream, $key, $value)
    {
        $this->published[] = ['stream' => $stream, 'key' => $key];
        return 'test-txid';
    }

    /**
     * Returns a hit so createStream() takes the already-exists path.
     */
    public function liststreams($stream)
    {
        return [['name' => $stream]];
    }

    public function __call($method, $arguments)
    {
        return null;
    }
}
