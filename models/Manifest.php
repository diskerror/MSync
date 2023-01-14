<?php

namespace Model;

use Model\Exceptions\SqliteException;
use SQLite3;

class Manifest
{
	protected SQLite3 $sqlite;
	protected Opts    $opts;

	public function __construct(string $manifestFile, Opts $opts)
	{
		$this->opts   = $opts;
		$this->sqlite = new SQLite3($manifestFile);

		$this->sqlite->enableExceptions(true);
		$this->sqlite->exec('PRAGMA encoding = \'UTF-8\'');

		$this->sqlite->exec(
			'CREATE TABLE IF NOT EXISTS last_sync (
				fname TEXT UNIQUE ON CONFLICT REPLACE,
				ftype TEXT DEFAULT "",
				sizeb INTEGER DEFAULT 0, 
				modts INTEGER DEFAULT 0,
				hashval BLOB DEFAULT "",
				init_sync INTEGER DEFAULT 0,
				last_sync INTEGER DEFAULT 0,
				conflict INTEGER DEFAULT 0
			)'
		);
	}

	public function firstWrite(array $files)
	{
		$report = new Report($this->opts->verbose);
		$i      = 1;
		$of_ct  = ' of ' . count($files);

		$t = $_SERVER['REQUEST_TIME'];

		foreach ($files as $f) {
			$report->status($i++ . $of_ct);

			$fname = SQLite3::escapeString($f['fname']);
			$res   = $this->sqlite->exec(
				'INSERT INTO last_sync (fname, ftype, sizeb, modts, hashval, init_sync, last_sync) ' .
				"VALUES ('$fname', '{$f['ftype']}', {$f['sizeb']}, {$f['modts']}, X'{$f['hashval']}', $t, $t)"
			);

			if (!$res) {
				throw new SQLiteException('problem with insert');
			}
		}

		$report->out('');
	}

}
