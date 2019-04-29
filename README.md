# react-php-poller

react-php-poller is an single-threaded async I/O collector server written in PHP (w/ ReactPHP for Async I/O).

It's an object-oriented framework in which you can build a monitoring/collector system.

There are 4 key classes/objects in the system:

`\SeanKndy\Poller\Checks\Check` - object that represents a check to be performed (by a `\SeanKndy\Poller\Commands\CommandInterface` command) and thus collected.

`\SeanKndy\Poller\Results\Result` - object that represents the result of a check performed.

`\SeanKndy\Poller\Checks\Incident` - object that represents that the Check has went into a non-OK state

`\SeanKndy\Poller\Checks\QueueInterface` - interface for enqueueing and dequeuing Check objects

The `\SeanKndy\Poller\Server` object starts the server and expects a `\SeanKndy\Poller\Checks\QueueInterface` as it's source of Check objects.

`\SeanKndy\Poller\Checks\MemoryQueue` is an in-memory Check queue implementing `\SeanKndy\Poller\Checks\QueueInterface`.  `\SeanKndy\Poller\Checks\TrackedMemoryPool` is another in-memory queue that extends `\SeanKndy\Poller\Checks\MemoryQueue` and provides the concept of a Check being "tracked in the queue" but the Check may or may not be currently enqueued (i.e. the Check is currently executing/collecting). These 2 basic implementations are obviously limited by the fact that they are totally within memory so one must keep them updated somehow.

You will probably want to write your own `QueueInterface` that is database-backed (Postgres, MySQL, redis, etc) and perhaps use a `MemoryQueue` for caching.

Each `Check` object has a "command" (`\SeanKndy\Poller\Commands\CommandInterface`) that is what executes to run/collect/poll the check.  There are several commands shipped with the package, however most are very hacked together from a very old project and desperately need re-written.  I'd advise you to write own your commands.  It just needs to implement `\SeanKndy\Poller\Commands\CommandInterface` and use async I/O via promise-based interface (ReactPHP promises).

`Server` starts by dequeue()ing a `Check` from the `QueueInterface`.  It runs the check by calling it's `CommandInterface`.  When a command finishes, it produces a `\SeanKndy\Poller\Results\Result` object which contains `\SeanKndy\Poller\Results\Metric` objects representing metrics of the result (for example, latency, loss, bandwidth usage, whatever...).  The `Check`, `Result` and possibly an `Incident` object are passed to handlers for any processing, metric storage, alerting etc.  A handler implements `\SeanKndy\Poller\Result\Handlers\HandlerInterface`.  Once handling is done, the `Check` is enqueue()ed back to the queue.

## Basic Usage

Here is a very simple example which has no handlers so is likely of little use, but gives you an idea of getting the system bootstrapped.

```php
<?php
use \SeanKndy\Poller\Server;
use \SeanKndy\Poller\Commands\Ping;
use \SeanKndy\Poller\Checks\Check;

$loop = \React\EventLoop\Factory::create();

$ping = new Ping($loop);
$check = new Check(
    'check-id-12345', // ID of check, needs to be unique
    $ping, // CommandInterface
    ['ip' => '8.8.8.8'], // attributes (array) that are passed to the command
    300, // interval
    null, // last Result
    [], // HandlerInterface[]
    null, // Last Incident
    [] // Metadata ; can be anything you'd like to attach to the Check as it's passed around the system
);

// You would either want to populate the MemoryQueue with every Check you have to run
// or else write your own queue (that implements QueueInterface).
$queue = new MemoryQueue();
$queue->enqueue($check);

$server = new Server($loop, $queue);

$loop->run();
```

The package is really a framework in which to write your own poller/collector.  I've created a set of objects, interfaces and a flow for them.  If you find it useful, you'll probably need to write your own `QueueInterface`s, `CommandInterface`s and `HandlerInterface`s which is for fetching/storing checks, running checks, and handling those checks, respectfully.
