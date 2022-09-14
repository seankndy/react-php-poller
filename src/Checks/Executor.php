<?php

namespace SeanKndy\Poller\Checks;

use Carbon\Carbon;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use SeanKndy\Poller\Results\Result;
use Evenement\EventEmitter;

final class Executor extends EventEmitter
{
    /**
     * Executes a Check command, create incidents if needed, run result handlers.
     */
    public function execute(Check $check): PromiseInterface
    {
        if (! $check->getCommand()) {
            return \React\Promise\reject(new \RuntimeException("Cannot execute Check (ID=" .
                $check->getId() . ") because a Command is not defined for it!"));
        }

        $check->setLastCheck(Carbon::now()->getTimestamp());

        return $check->runCommand()->then(function ($result) use ($check) {
            // make new incident if necessary and mark prior incident as resolved
            // if necessary
            $newIncident = null;
            if ($result->justifiesNewIncidentForCheck($check)) {
                $newIncident = Incident::fromResults($check->getResult(), $result);
            }
            if ($result->ok() || $newIncident) {
                if ($existingIncident = $check->getIncident()) { // existing incident
                    if (! $existingIncident->isResolved()) {
                        // resolve it since we are now OK or have new incident
                        $existingIncident->resolve();
                    } else {
                        // already resolved(old incident), discard it
                        $check->setIncident(null);
                    }
                }
            }

            return $this->runHandlers(
                $check, $result, $newIncident
            )->always(function() use ($check, $result, $newIncident) {
                $check->setResult($result);

                if ($newIncident) {
                    $check->setIncident($newIncident);
                }
            });
        });
    }

    /**
     * Run handlers for Check $check and Result $result
     */
    private function runHandlers(Check $check, Result $result, ?Incident $incident = null): ExtendedPromiseInterface
    {
        // run mutate() calls in order and in sequence
        return \array_reduce(
            $check->getHandlers(),
            function ($prev, $cur) use ($result, $check, $incident) {
                return $prev->then(
                    function() use ($cur, $result, $check, $incident) {
                        try {
                            return $cur->mutate($check, $result, $incident)->otherwise(
                                fn($e) => $this->emit('error', [$cur, $e])
                            );
                        } catch (\Exception $e) {
                            $this->emit('error', [$cur, $e]);
                        }
                    },
                    fn($e) => \React\Promise\reject([$cur, $e])
                );
            },
            \React\Promise\resolve([])

        // then run process() calls async
        )->then(function () use ($result, $check, $incident) {
            $clonedCheck = clone $check;
            $clonedResult = clone $result;
            $clonedIncident = $incident === null ? null : clone $incident;

            foreach ($check->getHandlers() as $handler) {
                try {
                    $handler->process($clonedCheck, $clonedResult, $clonedIncident)->then(
                        null,
                        fn($e) => $this->emit('error', [$handler, $e])
                    );
                } catch (\Exception $e) {
                    $this->emit('error', [$handler, $e]);
                }
            }
        });
    }
}
