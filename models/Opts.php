<?php

namespace Model;

use RuntimeException;
use UnexpectedValueException;

class Opts
{
	const DATA_DIR      = '.msync/';
	const MANIFEST_FILE = 'manifest.db';
	const CONFIG_FILE   = 'config.ini';
	const CONFLICT_DIR  = 'conflict/';

	/**
	 * Regex file transfer rules.
	 */
	protected string $IGNORE_REGEX;
	protected string $NEVER_HASH;
	protected string $NO_PUSH_REGEX;

	/**
	 * Settings from command line and config file.
	 */
	protected string $host       = '192.168.1.77';
	protected string $remotePath = '/var/www/html';
	protected string $localPath  = '.';    //	Becomes full path to local directory.
	protected string $user       = '';
	protected string $sshKeyPath = '';
	protected string $password   = '';
	protected bool   $verbose    = true;

	protected ?int   $restIndex;
	protected string $dataDir = '';
	protected array  $iniAllowed;

	public function __construct(array &$argv)
	{
		/**
		 * Protect these from being overwritten.
		 */
		$this->iniAllowed = get_class_vars(self::class);
		unset($this->iniAllowed['localPath']);
		unset($this->iniAllowed['restIndex']);
		unset($this->iniAllowed['dataDir']);
		unset($this->iniAllowed['iniAllowed']);

		/**
		 * Always ignore these entries (testing with SuiteCRM 7):
		 *    top directory and directories above the current directory (., ..)
		 *    any hidden file (starting with a dot)
		 *    all log files, data files, uploaded business files
		 *    database backup file
		 *    and extra upload* files.
		 */
		$this->IGNORE_REGEX = <<<'NDOC'
			/\.
			.*/\.\.
			.*/\.[^./].*
			.*\.log
			.*\.csv
			.*\.zip
			.*[0-9a-fA-F]{12}
			.*/IMPORT_[^/]+\d
			/sugarcrm_old\.sql
			/upload[^/]+/.*
			NDOC;

		/**
		 * Don't hash these files because we will never edit them.
		 * Any additional files of these types will be pushed to remote directory.
		 */
		$this->NEVER_HASH = <<<'NDOC'
			.*\.exe
			.*\.gif
			.*\.ico
			.*\.jpg
			.*\.png
			.*\.svg
			NDOC;

		/**
		 * These are separate as we need to pull them for testing.
		 * Never push back files ending with tilde ~.
		 * Never push back config*.php.
		 * Never push back these directories.
		 * Also, these never need hashing as we never change them.
		 */
		$this->NO_PUSH_REGEX = <<<HDOC
			.*~
			/cache/.*
			/config.*\.php
			/custom/blowfish/.*
			/custom/history/.*
			/silentUpgrade*.php
			/upload.*
			/vendor/.*
			HDOC;

		$opts = getopt(
			'd:Hh:p::u:v',
			['directory:', 'help', 'host:', 'password::', 'username:', 'verbose'],
			$this->restIndex
		);

		//	Stop early for “help” option.
		if (array_key_exists('H', $opts) || array_key_exists('help', $opts)) {
			$argv[$this->restIndex] = 'help';
			return;
		}

		if (!isset($argv[$this->restIndex])) {
			throw new UnexpectedValueException('Missing parameter.');
		}

		if (array_key_exists('d', $opts)) {
			$this->localPath = ltrim($opts['d']);
		}
		elseif (array_key_exists('directory', $opts)) {
			$this->localPath = ltrim($opts['directory']);
		}

		switch (true) {
			case $this->localPath === '':
			case $this->localPath === '.':
				$this->localPath = getcwd();
				break;

			case substr($this->localPath, 0, 2) === '~/':
				$this->localPath = $_SERVER['HOME'] . substr($this->localPath, 1);
				break;

			case $this->localPath[0] !== '/':
				$this->localPath = getcwd() . '/' . $this->localPath;
				break;
		}

		if (!file_exists($this->localPath)) {
			throw new RuntimeException('Directory "' . $this->localPath . '" does not exist.');
		}


		//	Read settings from config file. They will overwrite corresponding variables.
		if (file_exists(self::CONFIG_FILE)) {
			foreach (parse_ini_file(self::CONFIG_FILE, false, INI_SCANNER_TYPED) as $k => $v) {
				if (array_key_exists($k, $this->iniAllowed)) {
					$this->$k = $v;
				}
			}
		}

		//	CLI option always override INI file.
		if (array_key_exists('h', $opts)) {
			$this->host = trim($opts['h']);
		}
		elseif (array_key_exists('host', $opts)) {
			$this->host = trim($opts['host']);
		}

		//  TODO: make password behavior like mysql
		if (array_key_exists('p', $opts)) {
			$this->password = $opts['p'];
		}
		elseif (array_key_exists('password', $opts)) {
			$this->password = $opts['password'];
		}

		if (array_key_exists('u', $opts)) {
			$this->user = trim($opts['u']);
		}
		elseif (array_key_exists('user', $opts)) {
			$this->user = trim($opts['user']);
		}

		if ($this->user === '') {
			$this->user = $_SERVER['USER'];
		}

		if ($this->sshKeyPath === '') {
			$this->sshKeyPath = $_SERVER['HOME'] . '/.ssh/id_rsa';
		}
		else {
			$this->sshKeyPath = realpath($this->sshKeyPath);
		}

		$this->verbose = array_key_exists('v', $opts) || array_key_exists('verbose', $opts);

		$this->dataDir = $this->localPath . '/' . self::DATA_DIR;
		if (!is_dir($this->dataDir)) {
			mkdir($this->dataDir);
		}
	}

	public function __get($name)
	{
		switch ($name) {
			case 'manifestPath':
				return $this->dataDir . '/' . self::MANIFEST_FILE;

			case 'conflictPath':
				return $this->dataDir . '/' . self::CONFLICT_DIR;
		}

		return $this->$name;
	}
}
