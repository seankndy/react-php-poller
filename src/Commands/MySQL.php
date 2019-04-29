<?php
namespace SeanKndy\Poller\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use React\EventLoop\LoopInterface;
use React\MySQL\QueryResult;
use React\MySQL\ConnectionInterface;

class MySQL implements CommandInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function run(Check $check)
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

                    $status = Result::STATE_UNKNOWN;
                    $status_reason = '';
                    $metrics = [];

                    if ($total_time >= $attributes['response_time_crit_threshold']) {
                        $status = Result::STATE_CRIT;
                        $status_reason = 'Response time hit max threshold';
                    } else if ($total_time >= $attributes['response_time_warn_threshold']) {
                        $status = Result::STATE_WARN;
                        $status_reason = 'Response time hit max threshold';
                    } else {
                        $status = Result::STATE_OK;
                        $status_reason = "Connection succeeded in " . $total_time . "s";
                    }
                    $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, 'resp', $total_time);
                    $deferred->resolve(new Result($status, $status_reason, $metrics));
                }, function (\Exception $error) use ($startTime, $deferred) {
                    $total_time = sprintf('%.3f', \microtime(true) - $startTime);
                    $status = Result::STATE_CRIT;
                    $status_reason = "Query failed after " .
                    $total_time . "s with error: " . $error->getMessage();
                    $deferred->resolve(new Result($status, $status_reason));
                });
                $mysqlConn->quit();
            }, function (\Exception $error) use ($startTime, $deferred) {
                $total_time = sprintf('%.3f', \microtime(true)-$startTime);
                $status = Result::STATE_CRIT;
                $status_reason = "Connection failed after " .
                $total_time . "s with error: " . $error->getMessage();
                $deferred->resolve(new Result($status, $status_reason));
            }
        );

        return $deferred->promise();
    }

    public function getProducableMetrics(array $attributes)
    {
        return [
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'resp')
        ];
    }
}
