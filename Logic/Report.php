<?php

namespace Logic;

class Report
{
	protected bool   $doPrint;
	protected bool   $beQuiet;
	protected int    $ct;
	protected string $of_total;

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

	public function statusReset(int $total)
	{
		$this->ct       = 0;
		$this->of_total = ' of ' . $total;
	}

	public function status()
	{
		if ($this->doPrint) {
			if (++$this->ct % 29 === 0) {
				fprintf(STDOUT, "\r" . $this->ct . $this->of_total);
			}
		}
	}

	public function statusLast()
	{
		echo "\r" . $this->ct . $this->of_total . PHP_EOL;
	}

	public function error(string $str)
	{
		if (!$this->beQuiet) {
			fprintf(STDERR, $str . PHP_EOL);
		}
	}
}
