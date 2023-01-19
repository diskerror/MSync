<?php

namespace Logic;

use Laminas\Json\Json;

/**
 * Schema.
 * $files = ['fname(file name 1)' => [
 *                 'ftype'     => d|f|l,
 *                 'modts'     => seconds from 1/1/1970  midnight UMT,
 *                 'sizeb'     => bytes,
 *                 'hashval'   => hash as binary
 *             ],
 *             ['fname(file name 2) => ...],
 *             ...
 *         ];
 */
class Manifest
{
	protected string $manifestPath;

	public function __construct(string $manifestPath)
	{
		$this->manifestPath = $manifestPath;
		$dir                = dirname($this->manifestPath);
		if (!file_exists($dir) || !is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
	}

	public function read(): array
	{
		return Json::decode(file_get_contents($this->manifestPath), Json::TYPE_ARRAY);
	}

	public function write(array $files): void
	{
		/**
		 * Perform "safe save".
		 * New data is written to storage media before old data is deleted.
		 * (Is this necessary?)
		 */
		$tempFileName = $this->manifestPath . '.temp';
		file_put_contents($tempFileName, Json::encode($files));
		rename($tempFileName, $this->manifestPath);
	}


	public function getChanged(array $newList): array
	{
		$lastSync = $this->read();

		$diffs = [];
		foreach ($newList as $newFname => $newInfo) {
			if (
				$newInfo['ftype'] === 'f' && (
					!array_key_exists($newFname, $lastSync) ||
					(
						($newInfo['hashval'] === '' || $lastSync[$newFname]['hashval'] === '') &&
						(
							$newInfo['ftype'] !== $lastSync[$newFname]['ftype'] ||
							$newInfo['sizeb'] !== $lastSync[$newFname]['sizeb'] ||
							$newInfo['modts'] !== $lastSync[$newFname]['modts']
						)
					) ||
					$newInfo['hashval'] !== $lastSync[$newFname]['hashval']
				)
			) {
				$diffs[$newFname] = $newInfo;
			}
		}

		return $diffs;
	}

	public function update(array $newList): void
	{
		if (count($newList) > 0) {
			$lastSync = $this->read();

			foreach ($newList as $newFname => $newInfo) {
				$lastSync[$newFname] = $newInfo;
			}

			$this->write($lastSync);
		}
	}

	public function delete(array $newList): void
	{
		if (count($newList) > 0) {
			$lastSync = $this->read();

			foreach ($newList as $newFname => $newInfo) {
				unset($lastSync[$newFname]);
			}

			$this->write($lastSync);
		}
	}

}
