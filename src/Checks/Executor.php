<?php
namespace SeanKndy\Poller\Checks;

use SeanKndy\Poller\Commands\CommandInterface;
use SeanKndy\Poller\Results\Result;
use Evenement\EventEmitter;
/**
 *
 */
class Executor extends EventEmitter
{
    /**
     * Executes a Check
     *
     * @param Check $check Check to run
     *
     * @return \React\Promise\PromiseInterface
     */
    public function execute(Check $check)
    {
        $command = $check->getCommand();
        if (!($command instanceof CommandInterface)) {
            return \React\Promise\reject(new \RuntimeException("Cannot execute this Check (ID=" .
                $check->getId() . ") because a Command is not defined for it!"));
        }

        $check->setState(Check::STATE_EXECUTING);
        $check->setLastCheck();

        return $command->run($check)->then(function ($result) use ($check) {
            // make new incident if necessary and mark prior incident as resolved
            // if necessary
            $newIncident = null;
            if ($check->isNewIncident($result)) {
                $newIncident = Incident::fromResults($check->getResult(), $result);
            }
            if (Result::isOK($result) || $newIncident) {
                if ($incident = $check->getIncident()) { // existing incident
                    if (!$incident->isResolved()) {
                        // resolve it since we are now OK or have new incident
                        $incident->setResolvedTime();
                    } else {
                        // already resolved(old incident), discard it
                        $check->setIncident(null);
                    }
                }
            }

            return $this->runHandlers(
                $check, $result, $newIncident
            )->always(function() use ($check, $result, $newIncident) {
                $check->setState(Check::STATE_IDLE);
                $check->setLastCheck();
                $check->setResult($result);
                if ($newIncident) {
                    $check->setIncident($newIncident);
                }
            });
        }, function ($e) use ($check) {
            // no handlers called here because the Check Command completely failed.
            $check->setState(Check::STATE_ERRORED);
            $check->setLastCheck();

            return \React\Promise\reject($e);
        });
    }

    /**
     * Run handlers for Check $check and Result $result
     *
     * @return \React\Promise\PromiseInterface
     */
    private function runHandlers(Check $check, Result $result, Incident $incident = null)
    {
        // run mutate() calls in order and in sequence
        return \array_reduce(
            $check->getHandlers(),
            function ($prev, $cur) use ($result, $check, $incident) {
                return $prev->then(
                    function () use ($cur, $result, $check, $incident) {
                        return $cur->mutate(
                            $check, $result, $incident
                        )->otherwise(function (\Throwable $e) use ($cur) {
                            $this->emit('error', [$cur, $e]);
                        });
                    },
                    function ($e) {
                        return \React\Promise\reject($e);
                    }
                );
            },
            \React\Promise\resolve([])
        // then run process() calls async
        )->then(function () use ($result, $check, $incident) {
            $clonedCheck = clone $check;
            $clonedResult = clone $result;
            $clonedIncident = $incident === null ? null : clone $incident;
            foreach ($check->getHandlers() as $handler) {
                $handler->process($clonedCheck, $clonedResult, $clonedIncident)->otherwise(
                    function (\Throwable $e) use ($handler) {
                        $this->emit('error', [$handler, $e]);
                    }
                );
            }
        });
    }
}
