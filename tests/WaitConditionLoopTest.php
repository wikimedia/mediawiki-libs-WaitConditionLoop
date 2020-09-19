<?php
/**
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
 * @author Aaron Schulz <aschulz@wikimedia.org>
 */

namespace Wikimedia\WaitConditionLoop\Test;

use Wikimedia\WaitConditionLoop;

/**
 * @covers \Wikimedia\WaitConditionLoop
 */
class WaitConditionLoopTest extends \PHPUnit\Framework\TestCase {
	public function testCallbackReached() {
		$wallClock = microtime( true );

		$count = 0;
		$status = new \stdClass();
		$loop = new WaitConditionLoopFakeTime(
			function () use ( &$count, $status ) {
				++$count;
				$status->value = 'cookie';

				return WaitConditionLoop::CONDITION_REACHED;
			},
			10.0,
			$this->newBusyWork( $x, $y, $z )
		);
		$this->assertEquals( $loop::CONDITION_REACHED, $loop->invoke() );
		$this->assertSame( 1, $count );
		$this->assertEquals( 'cookie', $status->value );
		$this->assertEquals( [ 0, 0, 0 ], [ $x, $y, $z ], "No busy work done" );

		$count = 0;
		$loop = new WaitConditionLoopFakeTime(
			function () use ( &$count, &$wallClock ) {
				$wallClock += 1;
				++$count;

				return $count >= 2 ? WaitConditionLoop::CONDITION_REACHED : false;
			},
			7.0,
			$this->newBusyWork( $x, $y, $z, $wallClock )
		);
		$this->assertEquals( $loop::CONDITION_REACHED, $loop->invoke(),
			"Busy work did not cause timeout" );
		$this->assertEquals( [ 1, 0, 0 ], [ $x, $y, $z ] );

		$count = 0;
		$loop = new WaitConditionLoopFakeTime(
			function () use ( &$count, &$wallClock ) {
				$wallClock += 0.1;
				++$count;

				return $count > 80 ? true : false;
			},
			50.0,
			$this->newBusyWork( $x, $y, $z, $wallClock, $dontCallMe, $badCalls )
		);
		$this->assertSame( 0, $badCalls, "Callback exception not yet called" );
		$this->assertEquals( $loop::CONDITION_REACHED, $loop->invoke() );
		$this->assertEquals( [ 1, 1, 1 ], [ $x, $y, $z ], "Busy work done" );
		$this->assertSame( 1, $badCalls, "Bad callback ran and was exception caught" );

		try {
			$e = null;
			$dontCallMe();
		} catch ( \Exception $e ) {
		}

		$this->assertInstanceOf( 'RunTimeException', $e );
		$this->assertSame( 1, $badCalls, "Callback exception cached" );
	}

	public function testCallbackTimeout() {
		$count = 0;
		$wallClock = microtime( true );
		$loop = new WaitConditionLoopFakeTime(
			function () use ( &$count, &$wallClock ) {
				$wallClock += 3;
				++$count;

				return $count > 300 ? true : false;
			},
			50.0,
			$this->newBusyWork( $x, $y, $z, $wallClock )
		);
		$loop->setWallClock( $wallClock );
		$this->assertEquals( $loop::CONDITION_TIMED_OUT, $loop->invoke() );
		$this->assertEquals( [ 1, 1, 1 ], [ $x, $y, $z ], "Busy work done" );

		$loop = new WaitConditionLoopFakeTime(
			function () use ( &$count, &$wallClock ) {
				$wallClock += 3;
				++$count;

				return true;
			},
			0.0,
			$this->newBusyWork( $x, $y, $z, $wallClock )
		);
		$this->assertEquals( $loop::CONDITION_REACHED, $loop->invoke() );

		$count = 0;
		$loop = new WaitConditionLoopFakeTime(
			function () use ( &$count, &$wallClock ) {
				$wallClock += 3;
				++$count;

				return $count > 10 ? true : false;
			},
			0,
			$this->newBusyWork( $x, $y, $z, $wallClock )
		);
		$this->assertEquals( $loop::CONDITION_FAILED, $loop->invoke() );
	}

	public function testCallbackAborted() {
		$x = 0;
		$wallClock = microtime( true );
		$loop = new WaitConditionLoopFakeTime(
			function () use ( &$x, &$wallClock ) {
				$wallClock += 2;
				++$x;

				return $x > 2 ? WaitConditionLoop::CONDITION_ABORTED : false;
			},
			10.0,
			$this->newBusyWork( $x, $y, $z, $wallClock )
		);
		$loop->setWallClock( $wallClock );
		$this->assertEquals( $loop::CONDITION_ABORTED, $loop->invoke() );
	}

	public function testLastWaitTime() {
		$list = [];
		$wallClock = microtime( true );
		$count = 0;
		$loop = new WaitConditionLoopFakeTime(
			function () use ( &$count, &$wallClock ) {
				$wallClock += 1.0;
				$count++;
				return ( $count > 2 );
			},
			10.0,
			$list
		);
		$loop->setWallClock( $wallClock );
		$this->assertEquals( $loop::CONDITION_REACHED, $loop->invoke() );
		$this->assertEquals( 2.0, $loop->getLastWaitTime() );
	}

	private function newBusyWork(
		&$x, &$y, &$z, &$wallClock = 1, &$dontCallMe = null, &$badCalls = 0
	) {
		$x = $y = $z = 0;
		$badCalls = 0;

		$list = [];
		$list[] = function () use ( &$x, &$wallClock ) {
			$wallClock += 1;

			return ++$x;
		};
		$dontCallMe = function () use ( &$badCalls ) {
			++$badCalls;
			throw new \RuntimeException( "TrollyMcTrollFace" );
		};
		$list[] =& $dontCallMe;
		$list[] = function () use ( &$y, &$wallClock ) {
			$wallClock += 15;

			return ++$y;
		};
		$list[] = function () use ( &$z, &$wallClock ) {
			$wallClock += 0.1;

			return ++$z;
		};

		return $list;
	}
}
