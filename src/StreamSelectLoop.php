<?php

namespace React\EventLoop;

use KUBE\Engine\Context\Context;
use KUBE\KUBE;
use React\EventLoop\Tick\FutureTickQueue;
use React\EventLoop\Tick\NextTickQueue;
use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\TimerInterface;
use React\EventLoop\Timer\Timers;

/**
 * A stream_select() based event-loop.
 */
class StreamSelectLoop implements LoopInterface
{
    const MICROSECONDS_PER_SECOND = 1000000;

    private $nextTickQueue;
    private $futureTickQueue;
    private $timers;
    private $readStreams = [];
    private $readListeners = [];
    private $writeStreams = [];
    private $writeListeners = [];
    private $running;

    public function __construct()
    {
        $this->nextTickQueue = new NextTickQueue($this);
        $this->futureTickQueue = new FutureTickQueue($this);
        $this->timers = new Timers();
    }

    private $readSockets = [];
    private $readSocketListeners = [];
    private $writeSockets = [];
    private $writeSocketListeners = [];
    public function addSocketRead($socket,callable $listener){
        if(is_resource($socket)){
            $key = (int)$socket;
            $this->readSockets[$key] = $socket;
            $this->readSocketListeners[$key] = $listener;
        }
    }

    public function addSocketWrite($socket,callable $listener){
        if(is_resource($socket)){
            $key = (int)$socket;
            if(!isset($this->writeSocketListeners[$key])){
                $this->writeSockets[$key] = $socket;
                $this->writeSocketListeners[$key] = $listener;
            }
            else{

            }
        }
    }

    public function socketTick(){
        if(!empty($this->readSockets) || !empty($this->writeSockets)){
            $reads = $this->readSockets;
            $writes = $this->writeSockets;
            $x = [];
            echo time().' :BEFORE'.PHP_EOL;
            socket_select($reads,$writes,$x,0,0);
            echo time().' :AFTER'.PHP_EOL;
            if(!empty($writes)){
                foreach($writes as $s){
                    $key = (int)$s;
                    if(isset($this->writeSocketListeners[$key])){
                        $call = $this->writeSocketListeners[$key];
                        unset($this->writeSocketListeners[$key]);
                        unset($this->writeSockets[$key]);
                        $call();
                    }
                }
            }

            if(!empty($reads)){
                foreach($reads as $s){
                    $key = (int)$s;
                    if(isset($this->readSocketListeners[$key])){
                        $call = $this->readSocketListeners[$key];
                        $call();
                    }
                }
            }
        }
    }

    public function removeSocket($socket){
        if(is_resource($socket)){
            $key = (int)$socket;
            unset($this->readSockets[$key]);
            unset($this->writeSockets[$key]);
            unset($this->readSocketListeners[$key]);
            unset($this->writeSocketListeners[$key]);
            return true;
        }
    }


    /**
     * {@inheritdoc}
     */
    public function addReadStream($stream, callable $listener)
    {
        $key = (int) $stream;

        if (!isset($this->readStreams[$key])) {
            $this->readStreams[$key] = $stream;
            $this->readListeners[$key] = $listener;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addWriteStream($stream, callable $listener)
    {
        $key = (int) $stream;

        if (!isset($this->writeStreams[$key])) {
            $this->writeStreams[$key] = $stream;
            $this->writeListeners[$key] = $listener;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeReadStream($stream)
    {
        $key = (int) $stream;

        unset(
            $this->readStreams[$key],
            $this->readListeners[$key]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        unset(
            $this->writeStreams[$key],
            $this->writeListeners[$key]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function removeStream($stream)
    {
        $this->removeReadStream($stream);
        $this->removeWriteStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer($interval, callable $callback)
    {
        $timer = new Timer($this, $interval, $callback, false);

        $this->timers->add($timer);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function addPeriodicTimer($interval, callable $callback)
    {
        $timer = new Timer($this, $interval, $callback, true);

        $this->timers->add($timer);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(TimerInterface $timer)
    {
        $this->timers->cancel($timer);
    }

    /**
     * {@inheritdoc}
     */
    public function isTimerActive(TimerInterface $timer)
    {
        return $this->timers->contains($timer);
    }

    /**
     * {@inheritdoc}
     */
    public function nextTick(callable $listener)
    {
        $this->nextTickQueue->add($listener);
    }

    /**
     * {@inheritdoc}
     */
    public function futureTick(callable $listener)
    {
        $this->futureTickQueue->add($listener);
    }

    /**
     * {@inheritdoc}
     */
    public function tick()
    {
        $this->nextTickQueue->tick();

        $this->futureTickQueue->tick();

        $this->timers->tick();

        //usleep(0);
        //$this->waitForStreamActivity(0);
        $this->waitForSocketActivity(0.01);
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->running = true;

        while ($this->running) {
            $this->nextTickQueue->tick();

            $this->futureTickQueue->tick();

            $this->timers->tick();

            // Next-tick or future-tick queues have pending callbacks ...
            if (!$this->running || !$this->nextTickQueue->isEmpty() || !$this->futureTickQueue->isEmpty()) {
                $timeout = 0.01;

            // There is a pending timer, only block until it is due ...
            } elseif ($scheduledAt = $this->timers->getFirst()) {
                $timeout = $scheduledAt - $this->timers->getTime();
                if ($timeout < 0.01) {
                    $timeout = 0.01;
                } else {
                    $timeout *= self::MICROSECONDS_PER_SECOND;
                }

            // The only possible event is stream activity, so wait forever ...
            } elseif ($this->readSockets || $this->writeSockets) {
                $timeout = null;

            // There's nothing left to do ...
            } else {
                break;
            }


//            $this->socketTick();
//            echo $timeout.PHP_EOL;
//            usleep(0);

            $this->waitForSocketActivity($timeout);

            //$this->waitForStreamActivity($timeout);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->running = false;
    }

    /**
     * Wait/check for stream activity, or until the next timer is due.
     */
    private function waitForStreamActivity($timeout)
    {
        $read  = $this->readStreams;
        $write = $this->writeStreams;

        $this->streamSelect($read, $write, $timeout);

        foreach ($read as $stream) {
            $key = (int) $stream;

            if (isset($this->readListeners[$key])) {
                call_user_func($this->readListeners[$key], $stream, $this);
            }
        }

        foreach ($write as $stream) {
            $key = (int) $stream;

            if (isset($this->writeListeners[$key])) {
                call_user_func($this->writeListeners[$key], $stream, $this);
            }
        }
    }


    private function waitForSocketActivity($timeout)
    {
        $read  = $this->readSockets;
        $write = $this->writeSockets;

        $this->socketSelect($read, $write, $timeout);

        foreach ($read as $stream) {
            $key = (int) $stream;

            if (isset($this->readSocketListeners[$key])) {
                call_user_func($this->readSocketListeners[$key], $stream, $this);
            }
        }

        foreach ($write as $stream) {
            $key = (int) $stream;

            if (isset($this->writeSocketListeners[$key])) {
                call_user_func($this->writeSocketListeners[$key], $stream, $this);
            }
        }
    }

    /**
     * Emulate a stream_select() implementation that does not break when passed
     * empty stream arrays.
     *
     * @param array        &$read   An array of read streams to select upon.
     * @param array        &$write  An array of write streams to select upon.
     * @param integer|null $timeout Activity timeout in microseconds, or null to wait forever.
     *
     * @return integer The total number of streams that are ready for read/write.
     */
    protected function streamSelect(array &$read, array &$write, $timeout)
    {
        if ($read || $write) {
            $except = null;

            return stream_select($read, $write, $except, $timeout === null ? null : 0, $timeout);
        }

        usleep($timeout);

        return 0;
    }

    protected function socketSelect(array &$read, array &$write, $timeout)
    {
        if ($read || $write) {
            $except = null;

            return socket_select($read, $write, $except, $timeout === null ? null : 0.01, $timeout);
        }

        usleep($timeout);

        return 0;
    }
}
