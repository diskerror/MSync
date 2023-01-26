<?php

namespace Logic;

class FileListLocal extends FileList
{

	protected function setStatsArray(): void
	{
		$path        = $this->opts->localPath;
		$plength     = strlen($this->opts->localPath);
		$regexIgnore = $this->regexIgnore;
		$regexNoHash = $this->regexNoHash;
		$hashAlgo    = Opts::HASH_ALGO;

		require 'find.php';

		$this->fileStatsArray = $ret;
	}

}
