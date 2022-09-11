<?php
namespace SeanKndy\Poller\Commands;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Promise\Deferred;

class SMTP implements CommandInterface
{
    private LoopInterface $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function getProducableMetrics(array $attributes): array
    {
        return [
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'resp')
        ];
    }

    public function run(Check $check): PromiseInterface
    {
        $lastResult = $check->getResult();
        // set default metrics
        $attributes = \array_merge([
            'ip' => '',
            'port' => 25,
            'send' => 'HELO cmpollerd.local',
            'receive' => '/^250.*$/',
            'timeout' => 10
        ], $check->getAttributes());

        $deferred = new Deferred();

        $connector = new \React\Socket\Connector($this->loop);
        $connector = new \React\Socket\TimeoutConnector($connector, $attributes['timeout'], $this->loop);
        $timeStart = microtime(true);
        $connector->connect($attributes['ip'] . ':' . $attributes['port'])->then(
            function (ConnectionInterface $connection) use ($attributes, $deferred, $timeStart) {
                // when we get response from SMTP server, begin our speaking
                $phase = 1;
                $connection->on('data', function ($data) use (&$phase, $deferred,
                    $connection, $attributes, $timeStart) {
                    if ($phase == 1) {
                        foreach (\preg_split('/[\r\n]+/', trim($data)) as $line) {
                            if (substr($line, 0, 4) == '220 ') {
                                $phase++;
                                $connection->write("{$attributes['send']}\r\n");
                            }
                        }
                    } else if ($phase == 2) {
                        $timeEnd = microtime(true);
                        $respTime = sprintf('%.3f', $timeEnd - $timeStart);
                        $metrics = [];
                        $connection->close();
                        $connection = null;

                        if (\preg_match($attributes['receive'], \trim($data))) {
                            $state = Result::STATE_OK;
                            $stateReason = '';
                            $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, 'resp', $respTime);
                        } else {
                            $state = Result::STATE_CRIT;
                            $stateReason = 'Received data (' . $data . ') does not match expected value';
                        }

                        $deferred->resolve(new Result($state, $stateReason, $metrics));
                    }
                });
                $connection->on('error', function (\Exception $e) use ($deferred, $connection, $timeStart) {
                    $connection->close();
                    $connection = null;
                    $state = Result::STATE_CRIT;
                    $timeEnd = microtime(true);
                    $totalTime = sprintf('%.3f', $timeEnd - $timeStart);
                    $stateReason = 'Connection error after ' . $totalTime . 's: ' . $e->getMessage();
                    $deferred->resolve(new Result($state, $stateReason));
                });
            },
            function (\Exception $e) use ($deferred, $timeStart) {
                $state = Result::STATE_CRIT;
                $timeEnd = microtime(true);
                $totalTime = sprintf('%.3f', $timeEnd - $timeStart);
                $stateReason = 'Connection error after ' . $totalTime . 's: ' . $e->getMessage();
                $deferred->resolve(new Result($state, $stateReason));
            }
        );

        return $deferred->promise();
    }
}
