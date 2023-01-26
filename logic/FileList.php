<?php

namespace Logic;

abstract class FileList
{
	protected Opts   $opts;
	protected string $regexIgnore;
	protected string $regexNoHash;

	protected $fileStatsArray;

	public function __construct(Opts $opts, string $regexIgnore, string $regexNoHash)
	{
		$this->opts        = $opts;
		$this->regexIgnore = $regexIgnore;
		$this->regexNoHash = $regexNoHash;
	}

	final public function __invoke()
	{
		if (!isset($this->fileStatsArray)) {
			chdir($this->opts->localPath);
			$this->setStatsArray();
		}

		return $this->fileStatsArray;
	}

	abstract protected function setStatsArray(): void;

}
