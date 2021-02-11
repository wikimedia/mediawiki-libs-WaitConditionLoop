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
