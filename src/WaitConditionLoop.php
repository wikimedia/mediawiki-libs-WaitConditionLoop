<?php
/**
 * Wait loop that reaches a condition or times out.
 *
 * Copyright (C) 2016 Aaron Schulz <aschulz@wikimedia.org>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Aaron Schulz
 */

namespace Wikimedia;

/**
 * Wait loop that reaches a condition or times out
 */
class WaitConditionLoop {
	/** @var callable */
	private $condition;
	/** @var callable[] */
	private $busyCallbacks = [];
	/** @var float Seconds */
	private $timeout;
	/** @var float Seconds */
	private $lastWaitTime;
	/** @var int|null */
	private $rusageMode;

	public const CONDITION_REACHED = 1;
	// evaluates as falsey
	public const CONDITION_CONTINUE = 0;
	public const CONDITION_FAILED = -1;
	public const CONDITION_TIMED_OUT = -2;
	public const CONDITION_ABORTED = -3;

	/**
	 * @param callable $condition Callback that returns a WaitConditionLoop::CONDITION_ constant
	 * @param float $timeout Timeout in seconds
	 * @param array &$busyCallbacks List of callbacks to do useful work (by reference)
	 */
	public function __construct( callable $condition, $timeout = 5.0, &$busyCallbacks = [] ) {
		$this->condition = $condition;
		$this->timeout = $timeout;
		$this->busyCallbacks =& $busyCallbacks;

		// @codeCoverageIgnoreStart
		if ( function_exists( 'getrusage' ) ) {
			// RUSAGE_SELF
			$this->rusageMode = 0;
		}
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Invoke the loop and continue until either:
	 *   - a) The condition callback returns neither CONDITION_CONTINUE nor false
	 *   - b) The timeout is reached
	 * Thus a condition callback can return true (stop) or false (continue) for convenience.
	 * In such cases, the halting result of "true" will be converted to CONDITION_REACHED.
	 *
	 * If $timeout is 0, then only the condition callback will be called (no busy callbacks),
	 * and this will immediately return CONDITION_FAILED if the condition was not met.
	 *
	 * Exceptions in callbacks will be caught and the callback will be swapped with
	 * one that simply rethrows that exception back to the caller when invoked.
	 *
	 * @return int WaitConditionLoop::CONDITION_* constant
	 * @throws \Exception Any error from the condition callback
	 */
	public function invoke() {
		// seconds
		$elapsed = 0.0;
		// microseconds to sleep each time
		$sleepUs = 0;
		$lastCheck = false;
		$finalResult = self::CONDITION_TIMED_OUT;
		do {
			$checkStartTime = $this->getWallTime();
			// Check if the condition is met yet
			$realStart = $this->getWallTime();
			$cpuStart = $this->getCpuTime();
			$checkResult = ( $this->condition )();
			$cpu = $this->getCpuTime() - $cpuStart;
			$real = $this->getWallTime() - $realStart;
			// Exit if the condition is reached, an error occurs, or this is non-blocking
			if ( $this->timeout <= 0 ) {
				// Accepts boolean true or self::CONDITION_REACHED
				$finalResult = $checkResult > 0 ? self::CONDITION_REACHED : self::CONDITION_FAILED;
				break;
			} elseif ( (int)$checkResult !== self::CONDITION_CONTINUE ) {
				if ( is_int( $checkResult ) ) {
					$finalResult = $checkResult;
				} else {
					$finalResult = self::CONDITION_REACHED;
				}
				break;
			} elseif ( $lastCheck ) {
				// timeout reached
				break;
			}
			// Detect if condition callback seems to block or if justs burns CPU
			$conditionUsesInterrupts = ( $real > 0.100 && $cpu <= $real * 0.03 );
			if ( !$this->popAndRunBusyCallback() && !$conditionUsesInterrupts ) {
				// 10 queries = 10(10+100)/2 ms = 550ms, 14 queries = 1050ms
				// stop incrementing at ~1s
				$sleepUs = min( $sleepUs + 10 * 1e3, 1e6 );
				$this->usleep( $sleepUs );
			}
			$checkEndTime = $this->getWallTime();
			// The max() protects against the clock getting set back
			$elapsed += max( $checkEndTime - $checkStartTime, 0.010 );
			// Do not let slow callbacks timeout without checking the condition one more time
			$lastCheck = ( $elapsed >= $this->timeout );
		} while ( true );

		$this->lastWaitTime = $elapsed;

		return $finalResult;
	}

	/**
	 * @return float Seconds
	 */
	public function getLastWaitTime() {
		return $this->lastWaitTime;
	}

	/**
	 * @param int $microseconds
	 * @codeCoverageIgnore
	 */
	protected function usleep( $microseconds ) {
		usleep( $microseconds );
	}

	/**
	 * @return float
	 * @codeCoverageIgnore
	 */
	protected function getWallTime() {
		return microtime( true );
	}

	/**
	 * @return float Returns 0.0 if not supported (Windows on PHP < 7)
	 * @codeCoverageIgnore
	 */
	protected function getCpuTime() {
		if ( $this->rusageMode === null ) {
			// assume worst case (all time is CPU)
			return microtime( true );
		}

		$ru = getrusage( $this->rusageMode );
		$time = $ru['ru_utime.tv_sec'] + $ru['ru_utime.tv_usec'] / 1e6;
		$time += $ru['ru_stime.tv_sec'] + $ru['ru_stime.tv_usec'] / 1e6;

		return $time;
	}

	/**
	 * Run one of the callbacks that does work ahead of time for another caller
	 *
	 * @return bool Whether a callback was executed
	 */
	private function popAndRunBusyCallback() {
		if ( $this->busyCallbacks ) {
			reset( $this->busyCallbacks );
			$key = key( $this->busyCallbacks );
			/** @var callable $workCallback */
			$workCallback =& $this->busyCallbacks[$key];
			try {
				$workCallback();
			} catch ( \Exception $e ) {
				/** @return never */
				$workCallback = static function () use ( $e ) {
					throw $e;
				};
			}
			// consume
			unset( $this->busyCallbacks[$key] );

			return true;
		}

		return false;
	}
}
