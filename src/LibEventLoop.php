<?php

namespace React\EventLoop;

use Event;
use EventBase;
use KUBE\KUBE;
use React\EventLoop\Tick\FutureTickQueue;
use React\EventLoop\Tick\NextTickQueue;
use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\TimerInterface;
use SplObjectStorage;

/**
 * An ext-libevent based event-loop.
 */
class LibEventLoop implements LoopInterface
{
    const MICROSECONDS_PER_SECOND = 1000000;

    private $eventBase;
    private $nextTickQueue;
    private $futureTickQueue;
    private $timerCallback;
    private $timerEvents;
    private $streamCallback;
    private $streamEvents = [];
    private $streamFlags = [];
    private $readListeners = [];
    private $writeListeners = [];
    private $running;
    private $nextPoll = 0.016;

    public function __construct()
    {
        echo 'USING LIBEVENTLOOP'.PHP_EOL;
        $this->eventBase = event_base_new();
        $this->nextTickQueue = new NextTickQueue($this);
        $this->futureTickQueue = new FutureTickQueue($this);
        $this->timerEvents = new SplObjectStorage();

        $this->createTimerCallback();
        $this->createStreamCallback();
        $this->addTimer($this->nextPoll,function(){
            $this->socketTick();
        });
        $this->addPeriodicTimer(5,function(){
            //KUBE::Log([count($this->readSockets).":".count($this->readSocketListeners),count($this->writeSockets).":".count($this->writeSocketListeners)],'sockets');
            $this->adjustNextPoll();
            gc_collect_cycles();
        });
    }

    function adjustNextPoll(){
        if(count($this->readSockets) < 50){
            $this->nextPoll = 0.016;
        }
        elseif(count($this->readSockets) < 100){
            $this->nextPoll = 0.05;
        }
        elseif(count($this->readSockets) < 150){
            $this->nextPoll = 0.1;
        }
        elseif(count($this->readSockets) < 175){
            $this->nextPoll = 0.25;
        }
        elseif(count($this->readSockets) < 200){
            $this->nextPoll = 0.5;
        }
        elseif(count($this->readSockets) < 225){
            $this->nextPoll = 0.75;
        }
        else{
            $this->nextPoll = 1;
        }
    }

    //Socket wrappers
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

        $this->adjustNextPoll();
        $this->addTimer($this->nextPoll,function(){
            $this->socketTick();
        });
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


//    public function addReadSocket($socket,callable $listener){
//        $this->addReadStream($socket,$listener);
//    }
//
//    public function addWriteSocket($socket, callable $listener){
//        $this->addWriteStream($socket,$listener);
//    }
//
//    public function removeSocket($socket){
//        $this->removeStream($socket);
//        return true;
//    }


    /**
     * {@inheritdoc}
     */
    public function addReadStream($stream, callable $listener)
    {
        $key = (int) $stream;

        if (!isset($this->readListeners[$key])) {
            $this->readListeners[$key] = $listener;
            $this->subscribeStreamEvent($stream, EV_READ);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addWriteStream($stream, callable $listener)
    {
        $key = (int) $stream;

        if (!isset($this->writeListeners[$key])) {
            $this->writeListeners[$key] = $listener;
            $this->subscribeStreamEvent($stream, EV_WRITE);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeReadStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->readListeners[$key])) {
            unset($this->readListeners[$key]);
            $this->unsubscribeStreamEvent($stream, EV_READ);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->writeListeners[$key])) {
            unset($this->writeListeners[$key]);
            $this->unsubscribeStreamEvent($stream, EV_WRITE);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->streamEvents[$key])) {
            $event = $this->streamEvents[$key];

            event_del($event);
            event_free($event);

            unset(
                $this->streamFlags[$key],
                $this->streamEvents[$key],
                $this->readListeners[$key],
                $this->writeListeners[$key]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer($interval, callable $callback)
    {
        $timer = new Timer($this, $interval, $callback, false);

        $this->scheduleTimer($timer);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function addPeriodicTimer($interval, callable $callback)
    {
        $timer = new Timer($this, $interval, $callback, true);

        $this->scheduleTimer($timer);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(TimerInterface $timer)
    {
        if ($this->isTimerActive($timer)) {
            $event = $this->timerEvents[$timer];

            event_del($event);
            event_free($event);

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

        event_base_loop($this->eventBase, EVLOOP_ONCE | EVLOOP_NONBLOCK);
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

            $flags = EVLOOP_ONCE;
            if (!$this->running || !$this->nextTickQueue->isEmpty() || !$this->futureTickQueue->isEmpty()) {
                $flags |= EVLOOP_NONBLOCK;
            } elseif (!$this->streamEvents && !$this->timerEvents->count()) {
                break;
            }

            event_base_loop($this->eventBase, $flags);
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
     * Schedule a timer for execution.
     *
     * @param TimerInterface $timer
     */
    private function scheduleTimer(TimerInterface $timer)
    {
        $this->timerEvents[$timer] = $event = event_timer_new();

        event_timer_set($event, $this->timerCallback, $timer);
        event_base_set($event, $this->eventBase);
        event_add($event, $timer->getInterval() * self::MICROSECONDS_PER_SECOND);
    }

    /**
     * Create a new ext-libevent event resource, or update the existing one.
     *
     * @param resource $stream
     * @param integer  $flag   EV_READ or EV_WRITE
     */
    private function subscribeStreamEvent($stream, $flag)
    {
        $key = (int) $stream;

        if (isset($this->streamEvents[$key])) {
            $event = $this->streamEvents[$key];
            $flags = $this->streamFlags[$key] |= $flag;

            event_del($event);
            event_set($event, $stream, EV_PERSIST | $flags, $this->streamCallback);
        } else {
            $event = event_new();

            event_set($event, $stream, EV_PERSIST | $flag, $this->streamCallback);
            event_base_set($event, $this->eventBase);

            $this->streamEvents[$key] = $event;
            $this->streamFlags[$key] = $flag;
        }

        event_add($event);
    }

    /**
     * Update the ext-libevent event resource for this stream to stop listening to
     * the given event type, or remove it entirely if it's no longer needed.
     *
     * @param resource $stream
     * @param integer  $flag   EV_READ or EV_WRITE
     */
    private function unsubscribeStreamEvent($stream, $flag)
    {
        $key = (int) $stream;

        $flags = $this->streamFlags[$key] &= ~$flag;

        if (0 === $flags) {
            $this->removeStream($stream);

            return;
        }

        $event = $this->streamEvents[$key];

        event_del($event);
        event_set($event, $stream, EV_PERSIST | $flags, $this->streamCallback);
        event_add($event);
    }

    /**
     * Create a callback used as the target of timer events.
     *
     * A reference is kept to the callback for the lifetime of the loop
     * to prevent "Cannot destroy active lambda function" fatal error from
     * the event extension.
     */
    private function createTimerCallback()
    {
        $this->timerCallback = function ($_, $_, $timer) {
            call_user_func($timer->getCallback(), $timer);

            // Timer already cancelled ...
            if (!$this->isTimerActive($timer)) {
                return;

            // Reschedule periodic timers ...
            } elseif ($timer->isPeriodic()) {
                event_add(
                    $this->timerEvents[$timer],
                    $timer->getInterval() * self::MICROSECONDS_PER_SECOND
                );

            // Clean-up one shot timers ...
            } else {
                $this->cancelTimer($timer);
            }
        };
    }

    /**
     * Create a callback used as the target of stream events.
     *
     * A reference is kept to the callback for the lifetime of the loop
     * to prevent "Cannot destroy active lambda function" fatal error from
     * the event extension.
     */
    private function createStreamCallback()
    {
        $this->streamCallback = function ($stream, $flags) {
            $key = (int) $stream;

            if (EV_READ === (EV_READ & $flags) && isset($this->readListeners[$key])) {
                call_user_func($this->readListeners[$key], $stream, $this);
            }

            if (EV_WRITE === (EV_WRITE & $flags) && isset($this->writeListeners[$key])) {
                call_user_func($this->writeListeners[$key], $stream, $this);
            }
        };
    }
}
