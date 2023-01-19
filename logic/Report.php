<?php

namespace Logic;

class Report
{
	protected bool $doPrint;
	protected bool $beQuiet;

	public function __construct(bool $print = true, bool $quiet = false)
	{
		$this->doPrint = $print;
		$this->beQuiet = $quiet;
	}

	public function out(string $str, bool $nl = true)
	{
		if ($this->doPrint) {
			fprintf(STDOUT, $str . ($nl ? PHP_EOL : ''));
		}
	}

	public function shout(string $str, bool $nl = true)
	{
		if (!$this->beQuiet) {
			fprintf(STDOUT, $str . ($nl ? PHP_EOL : ''));
		}
	}

	public function scream(string $str, bool $nl = true)
	{
		fprintf(STDERR, $str . ($nl ? PHP_EOL : ''));
	}

	public function status(string $str)
	{
		if ($this->doPrint) {
//			static $oldLines = 0;
//			$newLines = substr_count($str, "\n");
//
//			if ($oldLines == 0) {
//				$oldLines = $newLines;
//			}
//
//			echo chr(27) . '[0G';
//			echo chr(27) . '[' . $oldLines . 'A' . PHP_EOL;
//
//			$oldLines = $newLines;
			echo "\r" . $str;
		}
	}

	public function error(string $str)
	{
		if (!$this->beQuiet) {
			fprintf(STDERR, $str . PHP_EOL);
		}
	}
}