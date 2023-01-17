<?php

namespace Model;

use Model\Exceptions\SqliteException;
use SQLite3;

class Manifest
{
	protected SQLite3 $sqlite;
	protected Opts    $opts;

	public function __construct(Opts $opts)
	{
		$this->opts   = $opts;
		$this->sqlite = new SQLite3($opts->manifestPath);

		$this->sqlite->enableExceptions(true);
//		$this->sqlite->exec("PRAGMA encoding = 'UTF-8'");

		$result = $this->sqlite->exec(<<<'NDOC'
			PRAGMA encoding = 'UTF-8';
			CREATE TABLE IF NOT EXISTS last_sync (
				fname TEXT UNIQUE ON CONFLICT REPLACE,
				ftype TEXT DEFAULT '',
				sizeb INTEGER DEFAULT 0, 
				modts INTEGER DEFAULT 0,
				hashval BLOB DEFAULT '',
				init_sync INTEGER DEFAULT 0,
				last_sync INTEGER DEFAULT 0
			);
			CREATE INDEX fname_i ON last_sync(fname);
			NDOC
		);

		if ($result === false) {
			throw new SQLiteException('problem creating DB');
		}
	}

	protected static function buildValuesString(array $row): string
	{
		$arr = [];
		foreach ($row as $r) {
			switch (gettype($r)) {
				case 'string':
					$arr[] = "'" . SQLite3::escapeString($r) . "'";
					break;

				case 'integer':
				case 'double':
					$arr[] = (string) $r;
					break;
					
				case 'hex';
					$arr[] = "X'" . $r . "'";
			}
		}
	}


	public function insertManyIntoManifest(array $files): void
	{
		$t      = $_SERVER['REQUEST_TIME'];
		$values = [];

		foreach ($files as $f) {
			$fname    = SQLite3::escapeString($f['fname']);
			$values[] = "('$fname', '{$f['ftype']}', {$f['sizeb']}, {$f['modts']}, X'{$f['hashval']}', $t, $t)";
		}

		if (count($values) > 0) {
			$result = $this->sqlite->exec(
				"INSERT INTO last_sync (fname, ftype, sizeb, modts, hashval, init_sync, last_sync) VALUES " .
				implode(',', $values)
			);

			if ($result === false) {
				throw new SQLiteException('problem with manifest insert');
			}
		}
	}

	public function addTempDb(): void
	{
		if (!$this->sqlite->exec("ATTACH DATABASE ':memory:' IF NOT EXISTS AS mem")) {
			throw new SQLiteException('problem attaching memory DB');
		}

		$result = $this->sqlite->exec(<<<'NDOC'
			PRAGMA encoding = 'UTF-8';
			"CREATE TABLE IF NOT EXISTS mem.temp AS temp (
				fname TEXT UNIQUE ON CONFLICT REPLACE,
				ftype TEXT DEFAULT '',
				sizeb INTEGER DEFAULT 0, 
				modts INTEGER DEFAULT 0,
				hashval BLOB DEFAULT ''
			)
			CREATE INDEX fname_i ON last_sync(fname);
			NDOC
		);

		if ($result === false) {
			throw new SQLiteException('problem creating temporary memory table');
		}
	}

	public function insertManyIntoTemp(array $files): void
	{
		$values = [];

		foreach ($files as $f) {
			$fname    = SQLite3::escapeString($f['fname']);
			$values[] = "('$fname', '{$f['ftype']}', {$f['sizeb']}, {$f['modts']}, X'{$f['hashval']}')";
		}

		if (count($values) > 0) {
			$result = $this->sqlite->exec(
				'INSERT INTO last_sync (fname, ftype, sizeb, modts, hashval) VALUES ' .
				implode(',', $values)
			);

			if ($result === false) {
				throw new SQLiteException('problem with temp table insert');
			}
		}
	}

	public function getChangedFiles()
	{
		$result = $this->sqlite->query(
			"SELECT fname FROM last_sync JOIN temp USING (fname) WHERE last_sync.hashval != temp.hashval"
		);

		if ($result === false) {
			throw new SQLiteException('problem with query');
		}

		$arr = [];
		while ($r = $result->fetchArray(SQLITE3_NUM)) {
			$arr[] = $r[0];
		}

		return $arr;
	}
}
