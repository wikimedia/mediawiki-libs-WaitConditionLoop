<?php
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
	/**
	 * @var int
	 */
	protected $wallClock = 1;

	/**
	 * @inheritDoc
	 */
	public function __construct( callable $condition, $timeout, array $busyCallbacks ) {
		parent::__construct( $condition, $timeout, $busyCallbacks );
	}

	/**
	 * @inheritDoc
	 */
	protected function usleep( $microseconds ) {
		$this->wallClock += $microseconds / 1e6;
	}

	/**
	 * @inheritDoc
	 */
	protected function getCpuTime() {
		return 0.0;
	}

	/**
	 * @inheritDoc
	 */
	protected function getWallTime() {
		return $this->wallClock;
	}

	/**
	 * @inheritDoc
	 */
	public function setWallClock( &$timestamp ) {
		$this->wallClock =& $timestamp;
	}
}
