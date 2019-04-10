<?php
namespace SeanKndy\Poller\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use React\EventLoop\LoopInterface;
use React\Dns\Model\Message;

class DNS implements CommandInterface
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
            'ip' => '8.8.8.8',
            'lookup_hostname' => 'google.com',
            'type' => 'A',
            'port' => 53,
            'timeout' => 5
        ], $check->getAttributes());

		$factory = new \React\Dns\Resolver\Factory();
		$dns = $factory->create($attributes['ip'], $this->loop);

		$type = null;
		switch ($attributes['type']) {
			default:
			case 'A':
				$type = Message::TYPE_A;
				break;
			case 'CNAME':
				$type = Message::TYPE_CNAME;
				break;
			case 'MX':
				$type = Message::TYPE_MX;
				break;
			case 'AAAA':
				$type = Message::TYPE_AAAA;
				break;
			case 'TXT':
				$type = Message::TYPE_TXT;
				break;
		}

		$startTime = \microtime(true);
        $deferred = new \React\Promise\Deferred();
		$dns->resolveAll($attributes['lookup_hostname'], $type)->then(
			function ($data) use ($startTime, $deferred) {
				$respTime = sprintf('%.3f', \microtime(true) - $startTime);

				$status = Result::STATE_OK;
				$statusReason = '';
	            $metrics = [new ResultMetric(
					ResultMetric::TYPE_GAUGE, 'resp', $respTime
				)];

		        $deferred->resolve(new Result($status, $statusReason, $metrics));
			},
			function (\Exception $e) use ($deferred) {
				$status = Result::STATE_CRIT;
				$statusReason = "Failed lookup: " . $e->getMessage();
				$deferred->resolve(new Result($status, $statusReason));
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
