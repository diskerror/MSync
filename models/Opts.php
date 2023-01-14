<?php

namespace Model;

use Model\Exceptions\HelpException;

class Opts
{
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
	protected string $localPath  = '.';
	protected string $user       = '';
	protected string $sshKeyPath = '';
	protected string $password   = '';
	protected bool   $verbose;
	protected ?int   $restIndex;

	public function __construct(string $configPath)
	{
		if (!is_dir(DATA_DIR)) {
			mkdir(DATA_DIR);
		}

		/**
		 * Always ignore these entries:
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
		 * Any additional files will be pushed to remote directory.
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
		 * Never push back files ending with tilde ~.
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

		//	Read settings from config file. They will overwrite corresponding variables.
		if (file_exists($configPath)) {
			foreach (parse_ini_file(CONFIG_FILE, false, INI_SCANNER_TYPED) as $k => $v) {
				//	Can't set localPath from ini file.
				if ($k !== 'localPath' && property_exists($this, $k)) {
					$this->$k = $v;
				}
			}
		}

		$opts = getopt(
			'd:Hh:p::u:v',
			['directory:', 'help', 'host:', 'password::', 'username:', 'verbose'],
			$this->restIndex
		);

		if (array_key_exists('H', $opts) || array_key_exists('help', $opts)) {
			throw new HelpException();
		}

		if (array_key_exists('d', $opts)) {
			$this->localPath = ltrim($opts['d']);
		}
		elseif (array_key_exists('directory', $opts)) {
			$this->localPath = ltrim($opts['directory']);
		}

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

		if ($this->localPath[0] === '~') {
			$this->localPath = $_SERVER['HOME'] . substr($this->localPath, 1);
		}
	}

	public function __get($name)
	{
		return $this->$name;
	}

}