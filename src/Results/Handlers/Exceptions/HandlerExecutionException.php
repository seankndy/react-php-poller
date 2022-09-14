<?php

namespace SeanKndy\Poller\Results\Handlers\Exceptions;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Handlers\HandlerInterface;

class HandlerExecutionException extends \Exception
{
    public Check $check;

    private HandlerInterface $handler;

    public static function create(Check $check, HandlerInterface $handler, \Throwable $previous): self
    {
        $ex = new self(sprintf(
            "Handler %s errored for Check ID=<%s>: %s",
            get_class($handler),
            $check->getId(),
            $previous->getMessage()
        ), 0, $previous);

        $ex->setCheck($check);
        $ex->setHandler($handler);

        return $ex;
    }

    public function setCheck(Check $check)
    {
        $this->check = $check;
    }

    public function setHandler(HandlerInterface $handler)
    {
        $this->handler = $handler;
    }

    public function getCheck(): Check
    {
        return $this->check;
    }

    public function getHandler(): HandlerInterface
    {
        return $this->handler;
    }
}