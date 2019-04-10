<?php
namespace SeanKndy\Poller;

use SeanKndy\Poller\Checks\QueueInterface;
use SeanKndy\Poller\Checks\Executor;
use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
/**
 * Server dequeues Checks from a QueueInterface object, executes it's Command,
 * fires the Result through the Check's Handlers, then enqueues it to the
 * QueueInterface object again.
 */
class Server extends EventEmitter
{
    /**
     * @var LoopInterface
     */
    private $loop;
    /**
     * @var QueueInterface
    */
    private $checkQueue;
    /**
     * @var int
     */
    private $maxConcurrentChecks = 100;
    /**
     * @var Executor
     */
    private $executor;
    /**
     * [[Check, float], ...]
     * @var array
     */
    private $checksExecuting = [];
    /**
     * @var stdObject
     */
    private $avgRunTime;
    /**
     * If server is running
     * @var bool
     */
    private $running = true;
    /**
     * @var array
     */
    private $timers = [];

    /**
     * @const float Time to wait between runDueChecks calls
     */
    const QUIET_TIME = 0.5;

    public function __construct(LoopInterface $loop = null, QueueInterface $queue = null)
    {
        $this->loop = $loop;
        $this->checkQueue = $queue;

        // structure for tracking average runtime data
        $this->avgRunTime = new class {
            public $total;
            public $counter;
            public $startTime;
            public $max;
            public $maxId;
            public function reset() {
                $this->counter = 0;
                $this->total = 0.0;
                $this->max = 0;
                $this->maxId = 0;
                $this->startTime = time();
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
        $this->loop->futureTick(function() {
            $this->runDueChecks();
        });

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
            $this->emit('runtime.stats', [
                'since' => \sprintf("%.1f", (\time()-$this->avgRunTime->startTime) / 60.0) . 'm ago',
                'num-checks' => $this->avgRunTime->counter,
                'average-runtime' => \sprintf("%.3f", $this->avgRunTime->total/$this->avgRunTime->counter),
                'max-runtime' => \sprintf("%.3f", $this->avgRunTime->max) . "s (id=" . $this->maxId . ")"
            ]);
            $this->avgRunTime->reset();
        });
    }

    /**
     * Set the maximum Checks to execute at once
     *
     * @var int $max
     *
     * @return $this;
     */
    public function setMaxConcurrentChecks(int $max)
    {
        $this->maxConcurrentChecks = $max;
        return $this;
    }

    /**
     * Stop server from running any more checks
     *
     */
    public function stop()
    {
        $this->checkQueue->flush()->otherwise(function(\Throwable $e) {
            $this->emit('error', [new \Exception('Failed to flush check queue: ' .
                $e->getMessage())]);
        });

        foreach ($this->timers as $timer) {
            $this->loop->cancelTimer($timer);
        }

        $this->running = false;
    }

    /**
     * Run any Checks that are due
     *
     * @return void
     */
    private function runDueChecks()
    {
        if (!$this->running) return;

        if (count($this->checksExecuting) >= $this->maxConcurrentChecks) {
            $this->loop->addTimer(self::QUIET_TIME, function() { $this->runDueChecks(); });
            return;
        }

        $this->checkQueue->dequeue()->then(function ($check) {
            if ($check === null) {
                $this->loop->addTimer(self::QUIET_TIME, function() { $this->runDueChecks(); });
                return;
            }

            $this->checksExecuting[$check->getId()] = [$check, \microtime(true)];
            $this->emit('check.start', [$check]);
            $this->executor->execute(
                $check
            )->then(function () use ($check) {
                // check succeeded, emit event, and
                // let always() handle enqueuing
                $this->emit('check.finish', [$check]);
            })->otherwise(function (\Throwable $e) use ($check) {
                // check crash and burned, emit error
                $this->emit('check.error', [$check, $e]);
            })->always(function () use ($check) {
                // calc runtime, remove check from executing array,
                // requeue check

                $runtime = \microtime(true) - $this->checksExecuting[$check->getId()][1];
                $this->avgRunTime->counter++;
                $this->avgRunTime->total += $runtime;
                if (($this->avgRunTime->max = \max($runtime, $this->avgRunTime->max)) === $runtime) {
                    $this->maxId = $check->getId();
                }

                unset($this->checksExecuting[$check->getId()]);
                $this->checkQueue->enqueue($check)->otherwise(function(\Throwable $e) {
                    $this->emit('error', [new \Exception("Failed to enqueue() Check ID=<" .
                        $check->getId() . ">: " . $e->getMessage())]);
                });
            });

            $this->loop->futureTick(function() { $this->runDueChecks(); });
        }, function (\Throwable $e) {
            $this->emit('error', [new \Exception("Failed to dequeue() a Check: " . $e->getMessage())]);
            $this->loop->addTimer(self::QUIET_TIME*4, function() { $this->runDueChecks(); });
        });
    }
}
