<?php

namespace SeanKndy\Poller\Commands;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use React\EventLoop\LoopInterface;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use Psr\Log\LoggerInterface;

/**
 * Ookla official speedtest CLI
 *
 */
class SpeedTest implements CommandInterface
{
    private LoopInterface $loop;

    private LoggerInterface $logger;

    private string $speedTestBin = '';

    public function __construct(LoopInterface $loop, LoggerInterface $logger,
        string $speedTestBin = '')
    {
        $this->loop = $loop;
        $this->logger = $logger;

        try {
            if (!$speedTestBin) {
                $bins = ['/usr/bin/speedtest', '/usr/local/bin/speedtest'];
                foreach ($bins as $bin) {
                    if (\file_exists($bin)) {
                        $speedTestBin = $bin;
                        break;
                    }
                }
                if (!$speedTestBin) {
                    throw new \RuntimeException("speedtest binary could not be found.");
                }
                $this->speedTestBin = $speedTestBin;
            } else if (\file_exists($speedTestBin)) {
                $this->speedTestBin = $speedTestBin;
            } else {
                throw new \RuntimeException("speedtest binary '$speedTestBin' could not be found.");
            }
        } catch (\RuntimeException $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }
    }

    public function getProducableMetrics(array $attributes): array
    {
        return [
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'latency'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'jitter'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'loss'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'download_mbps'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'upload_mbps')
        ];
    }

    public function run(Check $check): PromiseInterface
    {
        $lastResult = $check->getResult();
        // set default metrics
        $attributes = array_merge([
            'server' => '', // host:port
            'download_threshold' => 0, // Mbit/sec
            'upload_threshold' => 0, // Mbit/sec
            'ping_threshold' => 0, // milliseconds
            'jitter_threshold' => 0, // milliseconds
            'loss_threshold' => 20 // %
        ], $check->getAttributes());

        $command = $this->speedTestBin . " -f json -u bps";
        if ($attributes['server']) {
            $command .= " -s {$attributes['server']}";
        }
        $this->logger->debug(__CLASS__ . ": executing process: ".$command);
        $process = new \React\ChildProcess\Process($command);
        $process->start($this->loop);

        $deferred = new \React\Promise\Deferred();
        $stdoutBuffer = '';
        // terminate process after 60sec unless this timer cancels by successful exit
        $terminateTimer = $this->loop->addTimer(60.0, function () use ($process) {
            foreach ($process->pipes as $pipe) {
                $pipe->close();
            }
            $process->terminate();
        });
        $process->stdout->on('data', function ($chunk) use (&$stdoutBuffer) {
            $stdoutBuffer .= $chunk;
        });
        $process->on('exit', function($exitCode, $termSignal) use ($deferred,
            $attributes, $command, &$stdoutBuffer, $terminateTimer) {
            $this->logger->debug(__CLASS__ . ": command exitCode=$exitCode; output=$stdoutBuffer");
            $this->loop->cancelTimer($terminateTimer);

            if (!($stResult = \json_decode($stdoutBuffer))) {
                $state = Result::STATE_UNKNOWN;
                $this->logger->error(__CLASS__ . ": unparseable command output: $stdoutBuffer");
                $stateReason = 'CMD_FAILURE';
                $metrics = [];
            } else {
                $downloadMbits = $stResult->download->bandwidth * 0.000008;
                $uploadMbits = $stResult->upload->bandwidth * 0.000008;

                if ($attributes['download_threshold'] && $downloadMbits < $attributes['download_threshold']) {
                    $state = Result::STATE_CRIT;
                    $stateReason = "DOWNSTREAM_THROUGHPUT_LOW";
                } else if ($attributes['upload_threshold'] && $uploadMbits < $attributes['download_threshold']) {
                    $state = Result::STATE_CRIT;
                    $stateReason = "UPSTREAM_THROUGHPUT_LOW";
                } else if ($attributes['ping_threshold'] && $stResult->ping->latency > $attributes['ping_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = "LATENCY_HIGH";
                } else if ($attributes['jitter_threshold'] && $stResult->ping->jitter > $attributes['jitter_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = "JITTER_HIGH";
                } else if ($attributes['loss_threshold'] >= 0 && $stResult->packetLoss > $attributes['loss_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = "PKT_LOSS_HIGH";
                } else {
                    $state = Result::STATE_OK;
                    $stateReason = '';
                }

                $metrics = [
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'latency', $stResult->ping->latency
                    ),
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'jitter', $stResult->ping->jitter
                    ),
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'loss', $stResult->packetLoss
                    ),
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'download_mbps', $downloadMbits
                    ),
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'upload_mbps', $uploadMbits
                    )
                ];
            }

            $result = new Result($state, $stateReason);
            $result->setMetrics($metrics);
            $deferred->resolve($result);
        });

        return $deferred->promise();
    }

}
