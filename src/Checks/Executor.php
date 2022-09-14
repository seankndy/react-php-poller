<?php

namespace SeanKndy\Poller\Checks;

use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;
use SeanKndy\Poller\Results\Handlers\Exceptions\HandlerExecutionException;
use SeanKndy\Poller\Results\Result;

final class Executor
{
    /**
     * Executes a Check command, create incidents if needed, run result handlers.
     */
    public static function execute(Check $check): PromiseInterface
    {
        $check->setLastCheckNow();

        if (! $check->getCommand()) {
            return \React\Promise\reject(new \RuntimeException("Cannot execute Check (ID=" .
                $check->getId() . ") because a Command is not defined for it!"));
        }

        return $check->runCommand()->then(function (Result $result) use ($check) {
            // make new incident (if necessary) and mark prior incident as resolved (if necessary)
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

            return self::executeHandlers(
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
     * Run result handlers for Check $check and Result $result
     */
    private static function executeHandlers(
        Check $check,
        Result $result,
        ?Incident $incident = null
    ): ExtendedPromiseInterface {
        // run mutate() calls in order and in sequence
        return \array_reduce(
            $check->getHandlers(),
            fn($carry, $handler) => $carry->then(
                function() use ($handler, $result, $check, $incident) {
                    try {
                        return $handler
                            ->mutate($check, $result, $incident)
                            ->otherwise(fn(\Throwable $e) => \React\Promise\reject(HandlerExecutionException::create($check, $handler, $e)));
                    } catch (\Throwable $e) {
                        return \React\Promise\reject(HandlerExecutionException::create($check, $handler, $e));
                    }
                },
                fn($e) => \React\Promise\reject(HandlerExecutionException::create($check, $handler, $e))
            ),
            \React\Promise\resolve([])

        // then run process() calls async
        )->then(function () use ($result, $check, $incident) {
            $promises = [];

            foreach ($check->getHandlers() as $handler) {
                try {
                    $promises[] = $handler
                        ->process($check, $result, $incident)
                        ->otherwise(fn(\Throwable $e) => \React\Promise\reject(HandlerExecutionException::create($check, $handler, $e)));
                } catch (\Throwable $e) {
                    $promises[] = \React\Promise\reject(HandlerExecutionException::create($check, $handler, $e));
                }
            }

            return \React\Promise\all($promises);
        });
    }
}
