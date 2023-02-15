<?php

namespace Logic;

use Throwable;

class FileListLocal extends FileList
{

	protected function init(): void
	{
		$path        = $this->opts->localPath;
		$plength     = strlen($this->opts->localPath);
		$regexIgnore = $this->opts->regexIgnore;
		$regexNoHash = $this->opts->regexNoHash;
		$hashAlgo    = Options::HASH_ALGO;

		try {
			require 'find.php';
		}
		catch (Throwable $t) {
			throw $t;
		}

		$this->fileList = $ret;
	}

}
