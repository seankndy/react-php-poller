<?php

namespace SeanKndy\Poller\Commands;

use React\ChildProcess\Process;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use React\EventLoop\LoopInterface;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Ping implements CommandInterface
{
    private LoopInterface $loop;

    private LoggerInterface $logger;

    protected string $fpingBin = '';

    public function __construct(
        LoopInterface $loop,
        ?LoggerInterface $logger = null,
        string $fpingBin = null
    ) {
        $this->loop = $loop;
        $this->logger = $logger === null ? new NullLogger() : $logger;

        try {
            if (!$fpingBin) {
                $bins = [
                    '/usr/bin/fping', '/usr/local/bin/fping',
                    '/usr/sbin/fping', '/sbin/fping',
                    '/usr/local/sbin/fping', '/opt/homebrew/bin/fping'
                ];
                foreach ($bins as $bin) {
                    if (\file_exists($bin)) {
                        $fpingBin = $bin;
                        break;
                    }
                }
                if (!$fpingBin) {
                    throw new \RuntimeException("fping binary could not be found.");
                }
                $this->fpingBin = $fpingBin;
            } else if (\file_exists($fpingBin)) {
                $this->fpingBin = $fpingBin;
            } else {
                throw new \RuntimeException("fping binary '$fpingBin' could not be found.");
            }
        } catch (\RuntimeException $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }
    }

    public function getProducableMetrics(array $attributes): array
    {
        return [
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'loss'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'avg'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'jitter')
        ];
    }
    
    public function run(Check $check): PromiseInterface
    {
        $lastResult = $check->getResult();
        // set default metrics
        $attributes = \array_merge([
            'ip' => '',
            'interval' => 25,
            'size' => 64,
            'loss_threshold' => 0,
            'avg_threshold' => 0,
            'try_count' => 1,
            'count' => 5,
            'jitter_threshold' => 0
        ], $check->getAttributes());

        if ($attributes['try_count'] <= 0) {
            $attributes['try_count'] = 1;
        }

        $command = $this->fpingBin . " -C {$attributes['count']} -q -b " .
            "{$attributes['size']} -B1 -r1 -i{$attributes['interval']} -p 500 " .
            "{$attributes['ip']}";

        $process = new Process($command);
        $process->start($this->loop);

        $deferred = new Deferred();
        $stderrBuffer = '';
        $process->stderr->on('data', function ($chunk) use (&$stderrBuffer) {
            $stderrBuffer .= $chunk;
        });
        $process->on('exit', function($exitCode, $termSignal) use ($deferred,
            $attributes, $command, &$stderrBuffer) {

            $this->logger->debug("Ping: $command --> $stderrBuffer");

            $last = \array_slice(\explode("\n", \trim($stderrBuffer)), -1);
            [$host, $measurements] = \explode(' : ', $last);
            $measurements = \explode(' ', \trim($measurements));
            $cntNoResponse = 0;
            $realMeasurements = [];
            foreach ($measurements as $m) {
                if ($m == '-') {
                    $cntNoResponse++;
                } else {
                    $realMeasurements[] = $m;
                }
            }
            $loss = ($cntNoResponse / \count($measurements)) * 100;

            $metrics = [];
            $metrics[] = new ResultMetric(
                ResultMetric::TYPE_GAUGE, 'loss', $loss
            );

            $this->logger->debug("Ping: calculated loss = $loss");

            if ($loss == 100) {
                $state = Result::STATE_CRIT;
                $stateReason = 'Host down';
            } else {
                $avg = \array_sum($realMeasurements) / \count($realMeasurements);
                $jitter = \round(\sqrt(\array_sum(\array_map(function ($x, $mean) {
                    return \pow($x - $mean,2);
                }, $realMeasurements, \array_fill(
                    0, \count($realMeasurements),
                    (\array_sum($realMeasurements) / \count($realMeasurements))
                ))) / (\count($realMeasurements)-1)), 2);

                $this->logger->debug("Ping: calculated avg,jitter = $avg,$jitter");

                if ($loss > $attributes['loss_threshold']) {
                    $state = Result::STATE_CRIT;
                    $stateReason = 'Hit packet loss threshold';
                } else if ($attributes['avg_threshold'] > 0 && $avg > $attributes['avg_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = 'Hit latency threshold';
                } else if ($attributes['jitter_threshold'] > 0 && $jitter > $attributes['jitter_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = 'Hit jitter threshold';
                } else {
                    $state = Result::STATE_OK;
                    $stateReason = '';
                }

                $metrics[] = new ResultMetric(
                    ResultMetric::TYPE_GAUGE, 'avg', $avg
                );
                $metrics[] = new ResultMetric(
                    ResultMetric::TYPE_GAUGE, 'jitter', $jitter
                );
            }

            $result = new Result($state, $stateReason, $metrics);
            $deferred->resolve($result);
        });

        return $deferred->promise();
    }
}
