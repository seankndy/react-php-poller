<?php
namespace SeanKndy\Poller\Commands;

use SeanKndy\Poller\Checks\Check;
use React\EventLoop\LoopInterface;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;

class Ping implements CommandInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;

    protected $fpingBin = '';

    public function __construct(LoopInterface $loop, $fpingBin = '/usr/bin/fping')
    {
        $this->loop = $loop;

        if (\file_exists($fpingBin)) {
            $this->fpingBin = $fpingBin;
        } else {
            throw new \RuntimeException("fping binary '$fpingBin' could not be found.");
        }
    }

    public function run(Check $check)
    {
        $lastResult = $check->getResult();
        // set default metrics
        $attributes = array_merge([
            'ip' => '',
            'interval' => 25,
            'size' => 64,
            'loss_threshold' => 0,
            'avg_threshold' => 0,
            'try_count' => 1,
            'count' => 5
        ], $check->getAttributes());

        if ($attributes['try_count'] <= 0) {
            $attributes['try_count'] = 1;
        }

        $command = $this->fpingBin . " -q -p 100 -b {$attributes['size']} -i " .
            "{$attributes['interval']} -c {$attributes['count']} {$attributes['ip']}";
        $process = new \React\ChildProcess\Process($command);
        $process->start($this->loop);

        $deferred = new \React\Promise\Deferred();
        $stderrBuffer = '';
        $process->stderr->on('data', function ($chunk) use (&$stderrBuffer) {
            $stderrBuffer .= $chunk;
        });
        $process->on('exit', function($exitCode, $termSignal) use ($deferred,
            $attributes, $command, &$stderrBuffer) {
            $state = Result::STATE_UNKNOWN;
            $stateReason = '';

            $matched = preg_match('#.*? ([0-9\.]+)/([0-9\.]+)/([0-9\.]+)\%(.*?/([0-9\.]+)/.+)?#', $stderrBuffer, $m);
            if ($matched) {
                if (!isset($m[4])) {
                    $state = Result::STATE_CRIT;
                    $stateReason = 'Host down';
                } else if ($m[3] > $attributes['loss_threshold']) {
                    $state = Result::STATE_CRIT;
                    $stateReason = 'Hit packet loss threshold';
                } else if (isset($m[5]) && $attributes['avg_threshold'] > 0 && $m[5] > $attributes['avg_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = 'Hit latency threshold';
                } else {
                    $state = Result::STATE_OK;
                }
            } else {
                $state = Result::STATE_CRIT;
                $stateReason = 'Unknown command output: '.$stderrBuffer;
            }

            $result = new Result($state, $stateReason);
            if ($m) {
                $result->addMetric(new ResultMetric(
                    ResultMetric::TYPE_GAUGE, 'loss', $m[3]
                ));
                if (isset($m[5])) { // avg
                    $result->addMetric(new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'avg', $m[5]
                    ));
                }
            }
            $deferred->resolve($result);
        });

        return $deferred->promise();
    }

    public function getProducableMetrics(array $attributes)
    {
        return [
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'loss'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'avg')
        ];
    }
}
