<?php

namespace Logic;

use RuntimeException;

class Manifest
{
	protected string $manifestFile;
	protected array  $manifest;

	public function __construct(string $manifestFile)
	{
		$this->manifestFile = $manifestFile;
		$dir                = dirname($this->manifestFile);
		if (!file_exists($dir)) {
			mkdir($dir, 0755, true);
		}
		elseif (!is_dir($dir)) {
			throw new RuntimeException('Bad .msync directory.');
		}
	}

	public function __destruct()
	{
		$this->write();
	}

	public function read(): ?array
	{
		if (!isset($this->manifest)) {
			if (file_exists($this->manifestFile)) {
				$this->manifest = json_decode(file_get_contents($this->manifestFile),
					JSON_OBJECT_AS_ARRAY | JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
			}
		}

		return $this->manifest;
	}

	public function __invoke()
	{
		return $this->read();
	}


	public function write(?array $fileList = null): void
	{
		if (is_array($fileList)) {
			$this->manifest = $fileList;
		}

		if (!isset($this->manifest)) {
			throw new RuntimeException('Manifest file was never read.');
		}

		/**
		 * Perform "safe save".
		 * New data is written to storage media before old data is deleted.
		 * (Is this necessary?)
		 */
		$tempFileName = $this->manifestFile . Opts::TEMP_SUFFIX;
		file_put_contents($tempFileName, json_encode($this->manifest, JSON_THROW_ON_ERROR));
		rename($tempFileName, $this->manifestFile);
	}


	public function update(array $newList): void
	{
		if (count($newList) > 0) {
			$lastSync = $this->read();

			foreach ($newList as $newFname => $newInfo) {
				$this->manifest[$newFname] = $newInfo;
			}

			$this->write($lastSync);    //	?
		}
	}

	public function delete(array $newList): void
	{
		if (count($newList) > 0) {
			$lastSync = $this->read();

			foreach ($newList as $newFname => $newInfo) {
				unset($lastSync[$newFname]);
			}

			$this->write($lastSync);    //	?
		}
	}

}
