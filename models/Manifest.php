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
			"CREATE TABLE IF NOT EXISTS last_sync (
				fname TEXT UNIQUE ON CONFLICT REPLACE,
				ftype TEXT DEFAULT '',
				sizeb INTEGER DEFAULT 0, 
				modts INTEGER DEFAULT 0,
				hashval BLOB DEFAULT '',
				init_sync INTEGER DEFAULT 0,
				last_sync INTEGER DEFAULT 0
			)"
		);
	}


	public function firstWrite(array $files)
	{
		$ct         = count($files);
		$of_ct      = ' of ' . $ct;
		$t          = $_SERVER['REQUEST_TIME'];

		$values    = [];
		for ($i = 0; $i < $ct; ++$i) {
			$f        = $files[$i];
			$fname    = SQLite3::escapeString($f['fname']);
			$values[] = "('$fname', '{$f['ftype']}', {$f['sizeb']}, {$f['modts']}, X'{$f['hashval']}', $t, $t)";
		}

		if (count($values) > 0) {
			$hugeQuery =
				"INSERT INTO last_sync (fname, ftype, sizeb, modts, hashval, init_sync, last_sync) VALUES " .
				implode(',', $values);

			if (!$this->sqlite->exec($hugeQuery)) {
				throw new SQLiteException('problem with insert');
			}
		}
	}

}
