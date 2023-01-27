<?php

namespace Logic;

class FileListLocal extends FileList
{

	protected function init(): void
	{
		$path        = $this->opts->localPath;
		$plength     = strlen($this->opts->localPath);
		$regexIgnore = $this->opts->regexIgnore;
		$regexNoHash = $this->opts->regexNoHash;
		$hashAlgo    = Options::HASH_ALGO;

		require 'find.php';

		$this->fileList = $ret;
	}

}
