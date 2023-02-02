<?php

namespace SeanKndy\Poller\Commands;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use React\EventLoop\LoopInterface;

class HTTP implements CommandInterface
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
            'host' => '', // if 'ip' empty, use this
            'port' => null,
            'ssl' => null, // boolean
            'verify_ssl' => false,
            'dns' => true, // if overridden with string, then this should be server IP
            'send' => 'HEAD / HTTP/1.0', // dont use, old
            'method' => 'HEAD',
            'path' => '/',
            'receive' => '/.*(302 Found|200 OK)$/', //dont use, old
            'response_code' => 200,
            'timeout' => 10,
            'resp_warn_threshold' => 1.0,
            'resp_crit_threshold' => 2.0
        ], $check->getAttributes());

        if ($attributes['port'] === null) {
            $attributes['port'] = $attributes['ssl'] === null ? 80 : ($attributes['ssl'] ? 443 : 80);
        }
        if ($attributes['ssl'] === null) {
            $attributes['ssl'] = $attributes['port'] == 443;
        }

        $connector = new \React\Socket\Connector($this->loop, [
            'tls' => [
                'verify_peer' => $attributes['verify_ssl'],
                'verify_peer_name' => $attributes['verify_ssl']
            ],
            'dns' => $attributes['dns'],
            'timeout' => $attributes['timeout']
        ]);
        $client = new \React\HttpClient\Client($this->loop, $connector);
        $deferred = new \React\Promise\Deferred();

        $url = \sprintf(
            '%s://%s:%d%s',
            $attributes['ssl'] ? 'https' : 'http',
            !empty($attributes['ip']) ? $attributes['ip'] : $attributes['host'],
            $attributes['port'],
            $attributes['path']
        );

        $startTime = \microtime(true);
        $request = $client->request($attributes['method'], $url);
        $request->on('response', function ($response) use ($startTime, $attributes, $deferred) {
            $respTime = sprintf('%.3f', \microtime(true) - $startTime);
            $status = Result::STATE_UNKNOWN;
            $status_reason = '';
            $metrics = [new ResultMetric(ResultMetric::TYPE_GAUGE, 'resp', $respTime)];

            if ($response->getCode() == $attributes['response_code']) {
                $status = Result::STATE_OK;

                if ($respTime >= $attributes['resp_crit_threshold']) {
                    $status = Result::STATE_CRIT;
                    $status_reason = 'RESP_TIME_EXCEEDED';
                } else if ($respTime >= $attributes['resp_warn_threshold']) {
                    $status = Result::STATE_WARN;
                    $status_reason = 'RESP_TIME_EXCEEDED';
                }
            } else {
                $status = Result::STATE_CRIT;
                $status_reason = 'HTTP_UNEXPECTED_RESP_CODE';
            }
            $deferred->resolve(new Result($status, $status_reason, $metrics));
        });
        $request->on('error', function (\Exception $e) use ($startTime, $deferred) {
            $status = Result::STATE_CRIT;
            if (stristr($e->getMessage(), 'verify failed') || stristr($e->getMessage(), 'tls handshake')) {
                $statusReason = 'HTTP_SSL_FAILURE';
            } else {
                $statusReason = 'UNREACHABLE';
            }

            $deferred->resolve(new Result($status, $statusReason));
        });
        $request->end();

        return $deferred->promise();
    }
}
