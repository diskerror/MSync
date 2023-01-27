<?php

namespace Logic;

use RuntimeException;
use UnderflowException;
use UnexpectedValueException;

/**
 * @property  $IGNORE_REGEX          string
 * @property  $NEVER_HASH            string
 * @property  $NO_PUSH_REGEX         string
 *
 * @property  $host                  string
 * @property  $remotePath            string
 * @property  $localPath             string
 * @property  $user                  string
 * @property  $group                 string
 * @property  $sshKeyPath            string
 * @property  $password              string
 * @property  $verbose               bool
 *
 * @property  $restIndex             ?int
 * @property  $appDataPath           string
 * @property  $iniAllowed            array
 *
 * @property  $verb                  string
 * @property  $fileToResolve         string
 *
 * Generated in "__get()".
 * @property  $manifestFile          string
 * @property  $conflictPath          string
 * @property  $pullRegexIgnore       string
 * @property  $pushRegexIgnore       string
 * @property  $pullRegexNoHash       string
 * @property  $pushRegexNoHash       string
 */
class Options
{
	public const    APP_DATA_DIR       = '/.msync/';
	public const    MANIFEST_FILE      = 'manifest.json';
	public const    CONFIG_FILE        = 'config.ini';
	public const    CONFLICT_DIR       = 'conflict/';
	public const    TEMP_SUFFIX        = '.temp';
	public const    SAMPLE_CONFIGS_DIR = __DIR__ . '/../SampleConfigs/';

	/**
	 * This algorithm seems to be the best trade-off between size (uniqueness),
	 * speed, and cross-platform availability. tiger128,3? sha1? md4? fnv164?
	 */
	public const    HASH_ALGO = 'fnv164';

	/**
	 * Files that contain the regex file transfer rules.
	 */
	protected const ALWAYS_IGNORE_FILE = 'alwaysIgnore.txt';
	protected const NEVER_HASH_FILE    = 'neverHash.txt';
	protected const NEVER_PUSH_FILE    = 'neverPush.txt';

	/**
	 * Settings from command line and config file.
	 */
	protected string $host       = '192.168.1.77';  //	IP address or FQDN
	protected string $remotePath = '/var/www/html'; //	full path
	protected string $localPath;                    //	not in config file, relative path, becomes full path
	protected string $user       = '';              //	user name in remote directory
	protected string $group      = 'www-data';      //	required group for files in remote directory
	protected string $sshKeyPath = '';              //	relative path, becomes full path
	protected string $password   = '';
	protected bool   $verbose    = true;

	protected string $verb;
	protected string $fileToResolve;

	protected ?int   $restIndex;
	protected string $appDataPath;
	protected array  $iniAllowed;
	protected string $regexIgnore;
	protected string $regexNoHash;

	public function __construct(array &$argv)
	{
		/**
		 * Create list of allowed INI keys.
		 * Unset, null, or const properties are not picked up.
		 */
		$this->iniAllowed = array_keys(get_object_vars($this));

		$opts = getopt(
			'd:Hh:p::u:v',
			['directory:', 'help', 'host:', 'password::', 'username:', 'verbose'],
			$this->restIndex
		);

		//	Stop early for “help” option.
		if (array_key_exists('H', $opts) || array_key_exists('help', $opts)) {
			throw new HelpException();
		}

		if (!isset($argv[$this->restIndex])) {
			throw new UnexpectedValueException('Missing verb or other parameter.');
		}
		$this->verb = $argv[$this->restIndex];

		if ($this->verb === 'resolve') {
			if (!isset($argv[$this->restIndex + 1])) {
				throw new UnderflowException('Missing path to file.');
			}
			$this->fileToResolve = ltrim($argv[$this->restIndex + 1]);
		}

		if (array_key_exists('d', $opts)) {
			$this->localPath = ltrim($opts['d']);
		}
		elseif (array_key_exists('directory', $opts)) {
			$this->localPath = ltrim($opts['directory']);
		}

		switch (true) {
			case isset($this->localPath) === false:
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

		$this->appDataPath = $this->localPath . self::APP_DATA_DIR;
		if (!is_dir($this->appDataPath)) {
			mkdir($this->appDataPath);
		}


		//	Read settings from config file. They will overwrite corresponding variables.
		$configFile = $this->appDataPath . self::CONFIG_FILE;
		if (file_exists($configFile)) {
			foreach (parse_ini_file($configFile, false, INI_SCANNER_TYPED) as $k => $v) {
				if (in_array($k, $this->iniAllowed, true)) {
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

		if (!($this->sshKeyPath = realpath($this->sshKeyPath))) {
			throw new UnexpectedValueException('Bad SSH key path.');
		}

		$this->verbose = array_key_exists('v', $opts) || array_key_exists('verbose', $opts);

		//	Set file exclude strings.
		$this->regexIgnore = self::APP_DATA_DIR . '?.*|';    //	Always ignore our local application data.

		if (file_exists($this->appDataPath . self::ALWAYS_IGNORE_FILE)) {
			$this->regexIgnore .=
				self::listToRegex(file_get_contents($this->appDataPath . self::ALWAYS_IGNORE_FILE));
		}
		else {
			$this->regexIgnore .=
				self::listToRegex(file_get_contents(self::SAMPLE_CONFIGS_DIR . self::ALWAYS_IGNORE_FILE));
		}

		if (file_exists($this->appDataPath . self::NEVER_HASH_FILE)) {
			$this->regexNoHash =
				self::listToRegex(file_get_contents($this->appDataPath . self::NEVER_HASH_FILE));
		}
		else {
			$this->regexNoHash =
				self::listToRegex(file_get_contents(self::SAMPLE_CONFIGS_DIR . self::NEVER_HASH_FILE));
		}

		//	TODO: Needs option to save configs to local app config.
	}

	public function addFileToIgnore(string $fname = self::NEVER_PUSH_FILE)
	{
		if (file_exists($this->appDataPath . $fname)) {
			$this->regexIgnore .= '|' .
				self::listToRegex(file_get_contents($this->appDataPath . $fname));
		}
		else {
			$this->regexIgnore .= '|' .
				self::listToRegex(file_get_contents(self::SAMPLE_CONFIGS_DIR . $fname));
		}
	}

	public function addFileToNoHash(string $fname = self::NEVER_PUSH_FILE)
	{
		if (file_exists($this->appDataPath . $fname)) {
			$this->regexNoHash .= '|' .
				self::listToRegex(file_get_contents($this->appDataPath . $fname));
		}
		else {
			$this->regexNoHash .= '|' .
				self::listToRegex(file_get_contents(self::SAMPLE_CONFIGS_DIR . $fname));
		}
	}

	public function __get($name)
	{
		switch ($name) {
			case 'manifestFile':
				return $this->appDataPath . self::MANIFEST_FILE;

			case 'conflictPath':
				return $this->appDataPath . self::CONFLICT_DIR;

			case 'regexIgnore':
				return '@^(?:' . $this->regexIgnore . ')$@';

			case 'regexNoHash':
				return '@^(?:' . $this->regexNoHash . ')$@';
		}

		return $this->$name;
	}

	protected static function listToRegex(...$strs): string
	{
		foreach ($strs as &$s) {
			$s = trim($s);
		}

		return strtr(implode('|', $strs), "\n", '|');
//		return '@^(?:' . strtr(implode('|', $strs), "\n", '|') . ')$@';
	}

}
