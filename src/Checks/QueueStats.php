<?php

namespace SeanKndy\Poller\Checks;

use React\Promise\PromiseInterface;

class QueueStats
{
    /**
     * Fetch a QueueInterface's stats
     *
     * @return PromiseInterface Returns a Promise<array,void>
     */
    public static function get(QueueInterface $queue): PromiseInterface
    {
        return $queue->getQueued()->then(function ($queued) {
            $counts = [];
            foreach ($queued as $check) {
                if (!isset($counts[$check->timeOfNextCheck()])) {
                    $counts[$check->timeOfNextCheck()] = 0;
                }
                $counts[$check->timeOfNextCheck()]++;
            }

            $stats = [
                'total' => \count($queued),
                // due in => count_in_queue
                '<=60s' => 0,
                '<=180s' => 0,
                '>180s' => 0,
            ];
            $time = time();
            foreach ($counts as $nextCheckTime => $count) {
                $diff = $nextCheckTime-$time;
                if ($diff <= 60) {
                    $stats['<=60s'] += $count;
                } else if ($diff <= 180) {
                    $stats['<=180s'] += $count;
                } else {
                    $stats['>180s'] += $count;
                }
            }
            return $stats;
        });
    }
}
