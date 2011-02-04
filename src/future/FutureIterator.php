<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @group futures
 */
function Futures($futures) {
  return new FutureIterator($futures);
}

/**
 * FutureIterator aggregates @{class:Future}s and allows you to respond to them
 * in the order they resolve. This is useful because it minimizes the amount of
 * time your program spends waiting on parallel processes.
 *
 *  $futures = array(
 *    'a.txt' => new ExecFuture('wc -c a.txt'),
 *    'b.txt' => new ExecFuture('wc -c b.txt'),
 *    'c.txt' => new ExecFuture('wc -c c.txt'),
 *  );
 *  foreach (Futures($futures) as $key => $future) {
 *    // IMPORTANT: keys are preserved but the order of elements is not. This
 *    // construct iterates over the futures in the order they resolve, so the
 *    // fastest future is the one you'll get first. This allows you to start
 *    // doing followup processing as soon as possible.
 *
 *    list($stdout) = $future->resolvex();
 *    do_some_processing($stdout);
 *  }
 *
 * @group futures
 */
class FutureIterator implements Iterator {

  protected $wait     = array();
  protected $work     = array();
  protected $futures  = array();
  protected $key;

  protected $limit;

  protected $timeout;
  protected $isTimeout = false;

  public function __construct(array $futures) {
    foreach ($futures as $future) {
      if (!$future instanceof Future) {
        throw new Exception('Futures must all be objects implementing Future.');
      }
    }
    $this->futures = $futures;
  }

  /**
   * Set a maximum amount of time you want to wait before the iterator will
   * yield a result. If no future has resolved yet, the iterator will yield
   * null for key and value. Among other potential uses, you can use this to
   * show some busy indicator:
   *
   *   foreach (Futures($futures)->setUpdateInterval(1) as $future) {
   *     if ($future === null) {
   *       echo "Still working...\n";
   *     } else {
   *       // ...
   *     }
   *   }
   *
   * This will echo "Still working..." once per second as long as futures are
   * resolving. By default, FutureIterator never yields null.
   *
   * @param float Maximum number of seconds to block waiting on futures before
   *              yielding null.
   * @return this
   */
  public function setUpdateInterval($interval) {
    $this->timeout = $interval;
    return $this;
  }

  public function rewind() {
    $this->wait = array_keys($this->futures);
    $this->work = null;
    $this->updateWorkingSet();
    $this->next();
  }

  protected function getWorkingSet() {
    if ($this->work === null) {
      return $this->wait;
    }

    return $this->work;
  }

  protected function updateWorkingSet() {
    if (!$this->limit) {
      return;
    }

    $old = $this->work;
    $this->work = array_slice($this->wait, 0, $this->limit, true);

    //  If we're using a limit, our futures are sleeping and need to be polled
    //  to begin execution, so poll any futures which weren't in our working set
    //  before.
    foreach ($this->work as $work => $key) {
      if (!isset($old[$work])) {
        $this->futures[$key]->isReady();
      }
    }
  }

  public function next() {
    $this->key = null;
    if (!count($this->wait)) {
      return;
    }

    $read_sokcets = array();
    $write_sockets = array();

    $start = microtime(true);
    $wait_time = 1;
    $timeout = $this->timeout;
    $this->isTimeout = false;

    $check = $this->getWorkingSet();
    $resolve = null;
    do {
      $read_sockets    = array();
      $write_sockets   = array();
      $can_use_sockets = true;
      foreach ($check as $wait => $key) {
        $future = $this->futures[$key];
        try {
          if ($future->getException()) {
            $resolve = $wait;
            continue;
          }
          if ($future->isReady()) {
            if ($resolve === null) {
              $resolve = $wait;
            }
            continue;
          }

          $got_sockets = false;
          $socks = $future->getReadSockets();
          if ($socks) {
            $got_sockets = true;
            foreach ($socks as $socket) {
              $read_sockets[] = $socket;
            }
          }

          $socks = $future->getWriteSockets();
          if ($socks) {
            $got_sockets = true;
            foreach ($socks as $socket) {
              $write_sockets[] = $socket;
            }
          }

          // If any currently active future had neither read nor write sockets,
          // we can't wait for the current batch of items using sockets.
          if (!$got_sockets) {
            $can_use_sockets = false;
          }
        } catch (Exception $ex) {
          $this->futures[$key]->setException($ex);
          $resolve = $wait;
          break;
        }
      }
      if ($resolve === null) {
        if ($can_use_sockets) {

          if ($timeout !== null) {
            $elapsed = microtime(true) - $start;
            if ($elapsed > $timeout) {
              $this->isTimeout = true;
              return;
            } else {
              $wait_time = $timeout - $elapsed;
            }
          }

          Future::waitForSockets($read_sockets, $write_sockets, $wait_time);
        } else {
          usleep(1000);
        }
      }
    } while ($resolve === null);

    $this->key = $this->wait[$resolve];
    unset($this->wait[$resolve]);
    $this->updateWorkingSet();
  }


  public function current() {
    if ($this->isTimeout) {
      return null;
    }
    return $this->futures[$this->key];
  }

  public function key() {
    if ($this->isTimeout) {
      return null;
    }
    return $this->key;
  }

  public function valid() {
    if ($this->isTimeout) {
      return true;
    }
    return ($this->key !== null);
  }

  public function resolveAll() {
    foreach ($this as $_) {
      // This implicitly forces all the futures to resolve.
    }
  }

  /**
   * Limits the number of simultaneous tasks.
   *
   * @param int Maximum number of simultaneous jobs allowed.
   * @return this
   */
  public function limit($max) {
    $this->limit = $max;
    return $this;
  }

}
