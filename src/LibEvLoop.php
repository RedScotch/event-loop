<?php

namespace React\EventLoop;

use libev\EventLoop;
use libev\IOEvent;
use libev\TimerEvent;
use React\EventLoop\Tick\FutureTickQueue;
use React\EventLoop\Tick\NextTickQueue;
use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\TimerInterface;
use SplObjectStorage;

/**
 * @see https://github.com/m4rw3r/php-libev
 * @see https://gist.github.com/1688204
 */
class LibEvLoop implements LoopInterface
{
    private $loop;
    private $nextTickQueue;
    private $futureTickQueue;
    private $timerEvents;
    private $readEvents = [];
    private $writeEvents = [];
    private $running;

    public function __construct()
    {
        echo 'USING LIBEVLOOP'.PHP_EOL;
        $this->loop = new EventLoop();
        $this->nextTickQueue = new NextTickQueue($this);
        $this->futureTickQueue = new FutureTickQueue($this);
        $this->timerEvents = new SplObjectStorage();
        $this->addPeriodicTimer(0.016,function(){
            $this->socketTick();
        });
    }

    private $readSockets = [];
    private $readSocketListeners = [];
    private $writeSockets = [];
    private $writeSocketListeners = [];
    public function addReadSocket($socket,callable $listener){
        if(is_resource($socket)){
            $key = (int)$socket;
            $this->readSockets[$key] = $socket;
            $this->readSocketListeners[$key] = $listener;
        }
    }

    public function addWriteSocket($socket,callable $listener){
        if(is_resource($socket)){
            $key = (int)$socket;
            if(!isset($this->writeSocketListeners[$key])){
                $this->writeSockets[$key] = $socket;
                $this->writeSocketListeners[$key] = $listener;
            }
        }
    }

    public function socketTick(){
        if(!empty($this->readSockets) || !empty($this->writeSockets)){
            $reads = $this->readSockets;
            $writes = $this->writeSockets;
            $x = [];
            socket_select($reads,$writes,$x,0);
            if(!empty($writes)){
                foreach($writes as $s){
                    $key = (int)$s;
                    if(isset($this->writeSocketListeners[$key])){
                        $call = $this->writeSocketListeners[$key];
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
        $callback = function () use ($stream, $listener) {
            call_user_func($listener, $stream, $this);
        };

        $event = new IOEvent($callback, $stream, IOEvent::READ);
        $this->loop->add($event);

        $this->readEvents[(int) $stream] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function addWriteStream($stream, callable $listener)
    {
        $callback = function () use ($stream, $listener) {
            call_user_func($listener, $stream, $this);
        };

        $event = new IOEvent($callback, $stream, IOEvent::WRITE);
        $this->loop->add($event);

        $this->writeEvents[(int) $stream] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function removeReadStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->readEvents[$key])) {
            $this->readEvents[$key]->stop();
            unset($this->readEvents[$key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->writeEvents[$key])) {
            $this->writeEvents[$key]->stop();
            unset($this->writeEvents[$key]);
        }
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

        $callback = function () use ($timer) {
            call_user_func($timer->getCallback(), $timer);

            if ($this->isTimerActive($timer)) {
                $this->cancelTimer($timer);
            }
        };

        $event = new TimerEvent($callback, $timer->getInterval());
        $this->timerEvents->attach($timer, $event);
        $this->loop->add($event);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function addPeriodicTimer($interval, callable $callback)
    {
        $timer = new Timer($this, $interval, $callback, true);

        $callback = function () use ($timer) {
            call_user_func($timer->getCallback(), $timer);
        };

        $event = new TimerEvent($callback, $interval, $interval);
        $this->timerEvents->attach($timer, $event);
        $this->loop->add($event);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(TimerInterface $timer)
    {
        if (isset($this->timerEvents[$timer])) {
            $this->loop->remove($this->timerEvents[$timer]);
            $this->timerEvents->detach($timer);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isTimerActive(TimerInterface $timer)
    {
        return $this->timerEvents->contains($timer);
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

        $this->loop->run(EventLoop::RUN_ONCE | EventLoop::RUN_NOWAIT);
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

            $flags = EventLoop::RUN_ONCE;
            if (!$this->running || !$this->nextTickQueue->isEmpty() || !$this->futureTickQueue->isEmpty()) {
                $flags |= EventLoop::RUN_NOWAIT;
            } elseif (!$this->readEvents && !$this->writeEvents && !$this->timerEvents->count()) {
                break;
            }

            $this->loop->run($flags);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->running = false;
    }
}
