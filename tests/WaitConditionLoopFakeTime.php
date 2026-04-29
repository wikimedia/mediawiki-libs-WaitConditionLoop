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

use Wikimedia\WaitConditionLoop;

class WaitConditionLoopFakeTime extends WaitConditionLoop {
	protected float $wallClock = 1;

	public function __construct( callable $condition, float $timeout, array $busyCallbacks ) {
		parent::__construct( $condition, $timeout, $busyCallbacks );
	}

	protected function usleep( int $microseconds ): void {
		$this->wallClock += $microseconds / 1e6;
	}

	protected function getCpuTime(): float {
		return 0.0;
	}

	protected function getWallTime(): float {
		return $this->wallClock;
	}

	public function setWallClock( float &$timestamp ): void {
		$this->wallClock =& $timestamp;
	}
}
