<?php

declare(strict_types=1);

namespace Modules\DevMode\Tests\Support;

use Illuminate\Contracts\Queue\Job;

final class FakeJob implements Job
{
    public function __construct(
        private readonly string $name = 'App\\Jobs\\ExampleJob',
        private readonly string $queue = 'default',
        private readonly int $attempts = 1,
        private readonly ?string $uuid = 'fake-uuid-1',
    ) {}

    public function uuid()
    {
        return $this->uuid;
    }

    public function getJobId()
    {
        return '99';
    }

    public function getRawBody()
    {
        return '{}';
    }

    public function fire() {}

    public function payload()
    {
        return [];
    }

    public function resolveQueuedJobClass()
    {
        return $this->name;
    }

    public function release($delay = 0) {}

    public function isReleased()
    {
        return false;
    }

    public function isDeleted()
    {
        return false;
    }

    public function delete() {}

    public function isDeletedOrReleased()
    {
        return false;
    }

    public function attempts()
    {
        return $this->attempts;
    }

    public function hasFailed()
    {
        return false;
    }

    public function markAsFailed() {}

    public function fail($e = null) {}

    public function maxTries()
    {
        return null;
    }

    public function maxExceptions()
    {
        return null;
    }

    public function backoff()
    {
        return null;
    }

    public function retryUntil()
    {
        return null;
    }

    public function timeout()
    {
        return null;
    }

    public function getName()
    {
        return $this->name;
    }

    public function resolveName()
    {
        return $this->name;
    }

    public function getConnectionName()
    {
        return 'database';
    }

    public function getQueue()
    {
        return $this->queue;
    }

    public function getRawConnection()
    {
        return null;
    }
}
