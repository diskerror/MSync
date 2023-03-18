<?php

namespace Logic;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

class FileList implements Countable, ArrayAccess, IteratorAggregate, JsonSerializable
{
	protected Options $opts;
	protected array   $fileList = [];

	final public function __construct(Options $opts)
	{
		$this->opts = $opts;
		$this->init();
	}

	protected function init(): void {}

	/**
	 * isDifferent
	 *
	 * An algorithm for detecting if a file has changed.
	 *
	 * The property "fileList" can be thought of as the relative path
	 *        in the destination directory (local) or in the manifest.
	 * Or "Is the passed file not in this list or is its file info
	 *        different from this."
	 *
	 * @param string $fnameIn
	 * @param array  $finfoIn
	 *
	 * @return bool
	 */
	public function isDifferent(string $fnameIn, array $finfoIn): bool
	{
		//	If there's no destination file then of course, it's different.
		if (!isset($this->fileList[$fnameIn])) {
			return true;
		}

		$thisFileInfo = $this->fileList[$fnameIn];

		//	If the type has changed transfer it.
		if ($finfoIn['ftype'] !== $thisFileInfo['ftype']) {
			return true;
		}

		//	If the source was not hashed...
		if ($finfoIn['hashval'] === '') {
			//	then check size and modification time.
			if ($finfoIn['sizeb'] != $thisFileInfo['sizeb'] || $finfoIn['mtime'] != $thisFileInfo['mtime']) {
				return true;
			}
		}
		//	If it was hashed then use that.
		elseif ($finfoIn['hashval'] !== $thisFileInfo['hashval']) {
			return true;
		}

		return false;
	}


	public function count(): int
	{
		return count($this->fileList);
	}

	public function &offsetGet($offset): array
	{
		return $this->fileList[$offset];
	}

	public function offsetSet($offset, $value): void
	{
		$this->fileList[$offset] = $value;
	}

	public function offsetExists($offset): bool
	{
		return isset($this->fileList[$offset]);
	}

	public function offsetUnset($offset): void
	{
		unset($this->fileList[$offset]);
	}

	public function getIterator(): ArrayIterator
	{
		return new ArrayIterator($this->fileList);
	}

	public function jsonSerialize(): array
	{
		return $this->fileList;
	}

}
