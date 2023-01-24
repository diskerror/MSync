<?php

namespace Logic;

class FileCompareAlgorithm
{
	protected array $oldStatsArray;

	/**
	 * Pass in list of files to compare against.
	 *
	 * @param array $statsArray
	 */
	public function __construct(array $statsArray)
	{
		$this->oldStatsArray = $statsArray;
	}

	public function isDifferent(string $fname, array $info): bool
	{
		//	If there's no destination file then of course we transfer it.
		if (!isset($this->oldStatsArray[$fname])) {
			return true;
		}

		$oldInfo = $this->oldStatsArray[$fname];

		//	If the type has changed transfer it.
		if ($info['ftype'] !== $oldInfo['ftype']) {
			return true;
		}

		//	If the source was not hashed...
		if ($info['hashval'] === '') {
			//	then check size and modification time.
			if ($info['sizeb'] != $oldInfo['sizeb'] || $info['modts'] != $oldInfo['modts']) {
				return true;
			}
		}
		//	If it was hashed then use that.
		elseif ($info['hashval'] !== $oldInfo['hashval']) {
			return true;
		}

		return false;
	}

}