<?php

namespace Model\Exceptions;

use Exception;
use Throwable;

/**
 * StopException
 * 
 * Used when we simply want to gracefully stop execution.
 */
class StopException extends Exception
{
	public function __toString()
	{
		return $this->message;
	}
}
