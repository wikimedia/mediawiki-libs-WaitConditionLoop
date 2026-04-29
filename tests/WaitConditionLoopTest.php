<?php
declare( strict_types = 1 );

/**
 * Copyright (C) 2016 Aaron Schulz <aschulz@wikimedia.org>
 *
 * @license GPL-2.0-or-later
 * @file
 * @author Aaron Schulz <aschulz@wikimedia.org>
 */

namespace Wikimedia\WaitConditionLoop\Test;

use RuntimeException;
use Wikimedia\WaitConditionLoop;

/**
 * @covers \Wikimedia\WaitConditionLoop
 */
class WaitConditionLoopTest extends \PHPUnit\Framework\TestCase {
	public function testCallbackReached(): void {
		$wallClock = microtime( true );

		$count = 0;
		$status = new \stdClass();
		$loop = new WaitConditionLoopFakeTime(
			static function () use ( &$count, $status ): int {
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
			static function () use ( &$count, &$wallClock ): int|false {
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
			static function () use ( &$count, &$wallClock ): bool {
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

		$this->assertInstanceOf( RuntimeException::class, $e );
		$this->assertSame( 1, $badCalls, "Callback exception cached" );
	}

	public function testCallbackTimeout(): void {
		$count = 0;
		$wallClock = microtime( true );
		$loop = new WaitConditionLoopFakeTime(
			static function () use ( &$count, &$wallClock ): bool {
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
			static function () use ( &$count, &$wallClock ): bool {
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
			static function () use ( &$count, &$wallClock ): bool {
				$wallClock += 3;
				++$count;

				return $count > 10 ? true : false;
			},
			0,
			$this->newBusyWork( $x, $y, $z, $wallClock )
		);
		$this->assertEquals( $loop::CONDITION_FAILED, $loop->invoke() );
	}

	public function testCallbackAborted(): void {
		$x = 0;
		$wallClock = microtime( true );
		$loop = new WaitConditionLoopFakeTime(
			static function () use ( &$x, &$wallClock ): int|false {
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

	public function testLastWaitTime(): void {
		$list = [];
		$wallClock = microtime( true );
		$count = 0;
		$loop = new WaitConditionLoopFakeTime(
			static function () use ( &$count, &$wallClock ): bool {
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

	public function testAbortedWithNoTimeout(): void {
		$loop = new WaitConditionLoop(
			static function (): int {
				return WaitConditionLoop::CONDITION_ABORTED;
			},
			0
		);

		$this->assertEquals( $loop::CONDITION_FAILED, $loop->invoke() );
	}

	private function newBusyWork(
		?int &$x, ?int &$y, ?int &$z, ?float &$wallClock = 1, ?callable &$dontCallMe = null, ?int &$badCalls = 0
	): array {
		$x = $y = $z = 0;
		$badCalls = 0;

		$list = [];
		$list[] = static function () use ( &$x, &$wallClock ): int {
			$wallClock += 1;

			return ++$x;
		};
		$dontCallMe = static function () use ( &$badCalls ): never {
			++$badCalls;
			throw new RuntimeException( "TrollyMcTrollFace" );
		};
		$list[] =& $dontCallMe;
		$list[] = static function () use ( &$y, &$wallClock ): int {
			$wallClock += 15;

			return ++$y;
		};
		$list[] = static function () use ( &$z, &$wallClock ): int {
			$wallClock += 0.1;

			return ++$z;
		};

		return $list;
	}
}
