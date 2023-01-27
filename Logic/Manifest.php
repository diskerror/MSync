<?php

namespace Logic;

use RuntimeException;

class Manifest extends FileList
{
	protected function init(): void
	{
		if (!file_exists($this->opts->appDataPath)) {
			mkdir($this->opts->appDataPath, 0755, true);
		}
		elseif (!is_dir($this->opts->appDataPath)) {
			throw new RuntimeException('Bad app data directory.');
		}

		if (file_exists($this->opts->manifestFile)) {
			$this->fileList = json_decode(file_get_contents($this->opts->manifestFile),
				JSON_OBJECT_AS_ARRAY | JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
		}
	}

	public function __destruct()
	{
		$this->write();
	}

	public function write(): void
	{
		/**
		 * Perform "safe save".
		 * New data is written to storage media before old data is deleted.
		 * (Is this necessary?)
		 */
		$tempFileName = $this->opts->manifestFile . Options::TEMP_SUFFIX;
		file_put_contents($tempFileName, json_encode($this->fileList, JSON_THROW_ON_ERROR));
		rename($tempFileName, $this->opts->manifestFile);
	}


	public function update(FileList $newList): void
	{
		foreach ($newList as $newFname => $newInfo) {
			$this->fileList[$newFname] = $newInfo;
		}
	}

}
