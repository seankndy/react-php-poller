<?php
namespace SeanKndy\Poller;

use SeanKndy\Poller\Checks\QueueInterface;
use SeanKndy\Poller\Checks\QueueStats;
use SeanKndy\Poller\Checks\Executor;
use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Psr\Container\ContainerInterface;
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
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var int
     */
    private $maxConcurrentChecks = 100;
    /**
     * @var ContainerInterface
     */
    private $container;
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
     * @const float Time to wait between runDueChecks calls
     */
    const QUIET_TIME = 0.5;

    public function __construct(LoopInterface $loop = null, QueueInterface $queue = null,
        LoggerInterface $logger, Executor $executor)
    {
        $this->loop = $loop === null ? \React\EventLoop\Factory::create() : $loop;
        $this->logger = $logger;
        $this->executor = $executor;
        $this->checkQueue = $queue;
        $this->pid = \getmypid();

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

        $this->loop->addSignal(SIGINT, function (int $signal) {
            $this->logger->info('Caught user interrupt signal, flushing check queue.');
            $this->checkQueue->flush()->then(function() {
                $this->logger->info('Flush succeeded');
            })->otherwise(function(\Throwable $e) {
                $this->logger->error('Failed to flush check queue: ' .
                    $e->getMessage());
            })->always(function() {
                die("Goodbye.");
            });
        });
        $this->loop->futureTick(function() {
            $this->runDueChecks();
        });
        // log stats and report any checks that have run for > 30sec
        $this->loop->addPeriodicTimer(30.0, function () {
/*
            QueueStats::get($this->checkQueue)->then(function ($queueStats) {
                $this->logger->info(
                    "Queue Stats: executing=<" . count($this->checksExecuting) . ">; " .
                    "queue-total=<" . $queueStats['total'] . ">; " .
                    "queue-<60sec=<" . $queueStats['<=60s'] . ">; " .
                    "queue-<180sec=<" . $queueStats['<=180s'] . ">; " .
                    "queue->180sec+=<" . $queueStats['>180s'] . ">"
                );
            });
*/
            $this->logger->info("checks executing=" . count($this->checksExecuting));
            foreach ($this->checksExecuting as $id => $pair) {
                list($check,$startTime) = $pair;
                if (\microtime(true) - $startTime > 30.0) {
                    $this->logger->warning("Check ID=<$id>; command=<" .
                        \get_class($check->getCommand()) . "> -- Executing for > 30sec!");
                }
            }
        });
        $this->loop->addPeriodicTimer(300.0, function () {
            $this->logger->info(
                "Runtime stats: since=<" .
                \sprintf("%.1f", (\time()-$this->avgRunTime->startTime) / 60.0) . "m ago>; " .
                "num-checks=<" . $this->avgRunTime->counter . ">; average-runtime=<" .
                \sprintf("%.3f", $this->avgRunTime->total/$this->avgRunTime->counter) . "s>; " .
                "max=<" . \sprintf("%.3f", $this->avgRunTime->max) . "s (id=" . $this->maxId . ")>"
            );
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
     * Run any Checks that are due
     *
     * @return void
     */
    private function runDueChecks()
    {
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
            $this->logger->debug("Check ID=<" . $check->getId() . "> -- starting: last_check=<" .
                (\time()-$check->getLastCheck()) . "sec ago>; interval=<" . $check->getInterval() . ">");
            $this->executor->execute(
                $check
            )->then(function () use ($check) {
                // check succeeded, just log the success, emit event, and
                // let always() handle enqueuing

                $this->logger->debug(
                    "Check ID=<" . $check->getId() . "> -- finished: command=<" .
                    \get_class($check->getCommand()) . ">; state=<" .
                    $check->getResult()->getStateString() . ">; state_reason=<" .
                    $check->getResult()->getStateReason() . ">; " .
                    "metrics=<" . count($check->getResult()->getMetrics()) . ">; runtime=<" .
                    sprintf("%.4f", $check->getLastStateDuration()) . "s>"
                );
                $this->emit('check.finished', [$check]);
            })->otherwise(function (\Throwable $e) use ($check) {
                // check crash and burned, log this, emit error

                $this->logger->error('Check ID=<' . $check->getId() . '> -- fatal error: error=<' .
                    $e->getMessage() . '>');
                $this->emit('check.errored', [$check, $e]);
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
                    $this->logger->error("Failed to enqueue() Check ID " . $check->getId() . ": " . $e->getMessage());
                });
            });

            $this->loop->futureTick(function() { $this->runDueChecks(); });
        }, function (\Throwable $e) {
            $this->logger->error("Failed to dequeue() a Check: " . $e->getMessage());
            $this->loop->addTimer(self::QUIET_TIME*4, function() { $this->runDueChecks(); });
        });
    }
}
