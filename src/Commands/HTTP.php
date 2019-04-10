<?php
namespace SeanKndy\Poller\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use React\EventLoop\LoopInterface;

class HTTP implements CommandInterface
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
            'port' => 80,
            'send' => 'HEAD / HTTP/1.0', // dont use, old
			'method' => 'HEAD',
			'path' => '/',
            'receive' => '/.*(302 Found|200 OK)$/', //dont use, old
            'response_code' => 200,
            'timeout' => 10
        ], $check->getAttributes());

		$client = new \React\HttpClient\Client($this->loop);
		$deferred = new \React\Promise\Deferred();

		$startTime = \microtime(true);
		$request = $client->request($attributes['method'], 'http://' . $attributes['ip'] . $attributes['path']);
		$request->on('response', function ($response) use ($startTime, $attributes, $deferred) {
            $respTime = sprintf('%.3f', \microtime(true) - $startTime);
            $status = Result::STATE_UNKNOWN;
            $status_reason = '';
            $metrics = [new ResultMetric(ResultMetric::TYPE_GAUGE, 'resp', $respTime)];

            if ($response->getCode() == $attributes['response_code']) {
                $status = Result::STATE_OK;
            } else {
                $status = Result::STATE_CRIT;
                $status_reason = 'Unexpected response code:  ' . $response->getCode() . '.';
            }
            $deferred->resolve(new Result($status, $status_reason, $metrics));
		});
		$request->on('error', function (\Exception $e) use ($startTime, $deferred) {
			$respTime = sprintf('%.3f', microtime(true) - $startTime);
			$status = Result::STATE_CRIT;
			$status_reason = 'Connection error after ' . $respTime . 's: ' . $e->getMessage();

		    $deferred->resolve(new Result($status, $status_reason));
		});
		$request->end();

		return $deferred->promise();
    }

    public function getProducableMetrics(array $attributes)
	{
        return [
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'resp')
        ];
    }
}
