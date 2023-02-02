<?php
namespace SeanKndy\Poller\Commands;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use React\EventLoop\LoopInterface;
use React\MySQL\QueryResult;
use React\MySQL\ConnectionInterface;

class MySQL implements CommandInterface
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
            'port' => 3306,
            'user' => 'root',
            'password' => '***',
            'db' => 'mysql',
            'query' => 'select 1',
            'response_time_warn_threshold' => 5,
            'response_time_crit_threshold' => 15
        ], $check->getAttributes());

        $deferred = new \React\Promise\Deferred();
        $uri = $attributes['user'] . ':' . $attributes['password'] . '@' .
        $attributes['ip'] . '/' . $attributes['db'];
        $startTime = \microtime(true);
        (new \React\MySQL\Factory($this->loop))->createConnection($uri)->then(
            function (ConnectionInterface $mysqlConn) use ($startTime,
                $deferred, $attributes) {
                $mysqlConn->query(
                    $attributes['query']
                )->then(function (QueryResult $command) use ($startTime,
                    $attributes, $deferred) {
                    $total_time = sprintf('%.3f', \microtime(true) - $startTime);

                    $status_reason = '';
                    $metrics = [];

                    if ($total_time >= $attributes['response_time_crit_threshold']) {
                        $status = Result::STATE_CRIT;
                        $status_reason = 'RESP_TIME_EXCEEDED';
                    } else if ($total_time >= $attributes['response_time_warn_threshold']) {
                        $status = Result::STATE_WARN;
                        $status_reason = 'RESP_TIME_EXCEEDED';
                    } else {
                        $status = Result::STATE_OK;
                    }
                    $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, 'resp', $total_time);

                    $deferred->resolve(new Result($status, $status_reason, $metrics));
                }, function (\Exception $error) use ($deferred) {
                    $status = Result::STATE_CRIT;
                    $status_reason = 'SQL_QRY_FAILURE';

                    $deferred->resolve(new Result($status, $status_reason));
                });
                $mysqlConn->quit();
            },
            function (\Exception $error) use ($deferred) {
                $status = Result::STATE_CRIT;
                $status_reason = 'UNREACHABLE';

                $deferred->resolve(new Result($status, $status_reason));
            }
        );

        return $deferred->promise();
    }
}
