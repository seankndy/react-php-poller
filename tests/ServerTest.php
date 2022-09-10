<?php

namespace SeanKndy\Poller\Tests;

use React\EventLoop\Loop;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\MemoryQueue;
use SeanKndy\Poller\Checks\QueueInterface;
use SeanKndy\Poller\Commands\CommandInterface;
use SeanKndy\Poller\Server;

class ServerTest extends TestCase
{
    private Server $server;

    private QueueInterface $queue;

    public function setUp(): void
    {
        parent::setUp();

        $this->server = new Server(Loop::get(), ($this->queue = new MemoryQueue()));
        Loop::futureTick(fn() => Loop::stop());
    }

    /** @test */
    public function it_executes_due_check(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $command
            ->expects($this->once())
            ->method('run')
            ->willReturn(\React\Promise\resolve([]));

        $check = new Check(1, $command, [], \time(), 10);

        $this->queue->enqueue($check);

        Loop::run();
        Loop::futureTick(fn() => $this->assertNotNull($check->getResult()));
    }
}