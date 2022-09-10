<?php

namespace SeanKndy\Poller;

use React\EventLoop\TimerInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\QueueInterface;
use SeanKndy\Poller\Checks\Executor;
use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use Carbon\Carbon;

/**
 * Server dequeues Checks from a QueueInterface object, executes it's Command,
 * fires the Result through the Check's Handlers, then enqueues it to the
 * QueueInterface object again.
 */
class Server extends EventEmitter
{
    private LoopInterface $loop;

    private QueueInterface $checkQueue;

    private int $maxConcurrentChecks;

    private Executor $executor;

    /**
     * @var array<int, array{Check, float}>
     */
    private array $checksExecuting;

    private object $avgRunTime;

    private bool $running;

    /**
     * @var TimerInterface[]
     */
    private array $timers;

    /**
     * @const float Time to wait between runDueChecks calls
     */
    const QUIET_TIME = 0.5;

    public function __construct(LoopInterface $loop, QueueInterface $queue)
    {
        $this->loop = $loop;
        $this->checkQueue = $queue;
        $this->running = true;
        $this->checksExecuting = [];
        $this->maxConcurrentChecks = 100;
        $this->timers = [];

        // structure for tracking average runtime data
        $this->avgRunTime = new class {
            public float $total;
            public int $counter;
            public int $startTime;
            public float $max;
            public $maxId;
            public function reset() {
                $this->counter = 0;
                $this->total = 0.0;
                $this->max = 0;
                $this->maxId = null;
                $this->startTime = Carbon::now()->getTimestamp();
            }
        };
        $this->avgRunTime->reset();

        $this->executor = new Executor();
        $this->executor->on('error', function ($handler, $e) {
            $this->emit('error', [new \Exception("Handler " .
                get_class($handler) . " errored: file=<" .
                $e->getFile() . ">; line=<" . $e->getLine() . ">; " .
                "msg=<" . $e->getMessage() . ">")]);
        });

        // start server
        $this->loop->futureTick(fn() => $this->runDueChecks());

        //  report any checks that have run for > 30sec
        $this->timers[] = $this->loop->addPeriodicTimer(30.0, function () {
            foreach ($this->checksExecuting as $id => $pair) {
                list($check,$startTime) = $pair;
                if (\microtime(true) - $startTime > 30.0) {
                    $this->emit('check.error', [$check, new \Exception("Check has been executing for > 30sec!")]);
                }
            }
        });
        $this->timers[] = $this->loop->addPeriodicTimer(300.0, function () {
            if ($this->avgRunTime->counter > 0) {
                $this->emit('runtime.stats', [[
                    'since' => \sprintf("%.1f", (Carbon::now()->getTimestamp()-$this->avgRunTime->startTime) / 60.0) . 'm ago',
                    'num-checks' => $this->avgRunTime->counter,
                    'average-runtime' => \sprintf("%.3f", $this->avgRunTime->total/$this->avgRunTime->counter),
                    'max-runtime' => \sprintf("%.3f", $this->avgRunTime->max) . "s (id=" . $this->maxId . ")"
                ]]);
            }
            $this->avgRunTime->reset();
        });
    }

    public function setMaxConcurrentChecks(int $max): self
    {
        $this->maxConcurrentChecks = $max;

        return $this;
    }

    public function stop(): void
    {
        $this->checkQueue->flush()->otherwise(
            fn(\Throwable $e) => $this->emit(
                'error', [new \Exception('Failed to flush check queue: '.$e->getMessage())]
            )
        );

        foreach ($this->timers as $timer) {
            $this->loop->cancelTimer($timer);
        }

        $this->running = false;
    }

    private function runDueChecks(): void
    {
        if (!$this->running) {
            return;
        }

        if (count($this->checksExecuting) >= $this->maxConcurrentChecks) {
            $this->loop->addTimer(self::QUIET_TIME, fn() => $this->runDueChecks());
            return;
        }

        $this->checkQueue->dequeue()->then(function ($check) {
            if ($check === null) {
                $this->loop->addTimer(self::QUIET_TIME*2, fn() => $this->runDueChecks());
                return;
            }

            $this->checksExecuting[$check->getId()] = [$check, \microtime(true)];
            $this->emit('check.start', [$check]);

            $this->executor->execute($check)
                // check succeeded, emit event, and
                // let always() handle enqueuing
                ->then(fn() => $this->emit('check.finish', [$check]))

                // check crash and burned, emit error
                ->otherwise(fn (\Throwable $e) => $this->emit('check.error', [$check, $e]))

                // calc runtime, remove check from executing array,
                // requeue check
                ->always(function () use ($check) {
                    $runtime = \microtime(true) - $this->checksExecuting[$check->getId()][1];
                    $this->avgRunTime->counter++;
                    $this->avgRunTime->total += $runtime;
                    if (($this->avgRunTime->max = \max($runtime, $this->avgRunTime->max)) === $runtime) {
                        $this->avgRunTime->maxId = $check->getId();
                    }

                    unset($this->checksExecuting[$check->getId()]);

                    if ($check->getInterval() <= 0) {
                        return;
                    }

                    $this->checkQueue
                        ->enqueue($check)
                        ->otherwise(fn(\Throwable $e) => $this->emit(
                            'error',
                            [new \Exception("Failed to enqueue() Check ID=<".$check->getId().">: ".$e->getMessage())]
                        ));
                });

            $this->loop->futureTick(fn() => $this->runDueChecks());
        }, function (\Throwable $e) {
            $this->emit('error', [new \Exception("Failed to dequeue() a Check: " . $e->getMessage())]);
            $this->loop->addTimer(self::QUIET_TIME*4, fn() => $this->runDueChecks());
        });
    }
}
