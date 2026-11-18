<?php

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/** A cache store whose operations throw, to simulate memcached being unreachable. */
class FailingCacheStore implements Store
{
    public function __construct(private readonly bool $failReads = true, private readonly bool $failWrites = true) {}

    public function get($key)
    {
        throw_if($this->failReads, new RuntimeException('cache read failed for secret-key-material'));

        return null;
    }

    public function many(array $keys)
    {
        throw_if($this->failReads, new RuntimeException('cache read failed for secret-key-material'));

        return array_fill_keys($keys, null);
    }

    public function put($key, $value, $seconds)
    {
        throw_if($this->failWrites, new RuntimeException('cache write failed'));

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        throw_if($this->failWrites, new RuntimeException('cache write failed'));

        return true;
    }

    public function touch($key, $seconds)
    {
        return true;
    }

    public function increment($key, $value = 1)
    {
        return false;
    }

    public function decrement($key, $value = 1)
    {
        return false;
    }

    public function forever($key, $value)
    {
        return true;
    }

    public function forget($key)
    {
        return true;
    }

    public function flush()
    {
        return true;
    }

    public function getPrefix()
    {
        return '';
    }
}
