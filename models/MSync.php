<?php /** @noinspection ALL */

namespace Model;

use Exception;
use Laminas\Json\Json;
use Model\Exceptions\HelpException;
use Model\Exceptions\NotInitializedException;
use Model\Exceptions\SQLiteException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use SQLite3;

/**
 * Msync class.
 */
class MSync
{
	public const DATA_DIR      = '.msync/';
	public const CONFIG_FILE   = self::DATA_DIR . 'config.ini';
	public const MANIFEST_FILE = self::DATA_DIR . 'manifest.db';
	public const TEMP_DIR      = self::DATA_DIR . 'temp/';
	public const REMOTE_FLIST  = '.msyncFileListing.json';    //	Should begin with a dot.

	public const TABLE_NAME            = 'last_sync';
	public const TABLE_FIELD_DEFS      = <<<'NDOC'
		(
			ftype TEXT DEFAULT "",
			fname TEXT DEFAULT "",
			sizeb INTEGER DEFAULT 0, 
			modts INTEGER DEFAULT 0,
			hashval BLOB DEFAULT ""
			init_sync INTEGER DEFAULT 0,
			last_sync INTEGER DEFAULT 0,
			conflict INTEGER DEFAULT 0
		)
		NDOC;
	public const INITIAL_INSERT_FIELDS = '(ftype, fname, sizeb, modts, hashval, init_sync, last_sync)';

	/**
	 * This algorithm seems to be the best trade-off between size (uniqueness),
	 * speed, and cross-platform availability.
	 */
	public const HASH_ALGO = 'tiger192,3';

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

	/**
	 * Alwas ingnore these files during pushing and pulling files to and from server.
	 * These can be overridden from the config file. See the man page for GNU ‘find’
	 * for the format of these rules.
	 */
	protected string $IGNORE_REGEX;
	protected string $NEVER_HASH;
	protected string $NO_PUSH_REGEX;

	/**
	 * Functional classes.
	 */
	protected SFTP    $sftp;
	protected Sqlite3 $sqlite;
	protected Report  $report;

	protected int $restIndex;

	public function __construct()
	{
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

		$this->NEVER_HASH = <<<'NDOC'
			.*\.exe
			.*\.gif
			.*\.ico
			.*\.jpg
			.*\.png
			.*\.svg
			NDOC;

		$this->NO_PUSH_REGEX = <<<HDOC
			.*~
			/cache/.*
			/config.*\.php
			/custom/application/Ext/.*
			/custom/blowfish/.*
			/custom/history/.*
			/custom/modulebuilder/.*
			/custom/modules/[^/]+/Ext/.*
			/custom/working/.*
			/silentUpgrade*.php
			/upload.*
			/vendor/.*
			HDOC;

		//	Read settings from config file. They will overwrite corresponding variables.
		if (file_exists(self::CONFIG_FILE)) {
			foreach (parse_ini_file(self::CONFIG_FILE, false, INI_SCANNER_TYPED) as $k => $v) {
				//	Can't set localPath from ini file.
				if ($k !== 'localPath' && property_exists($this, $k)) {
					$this->$k = $v;
				}
			}
		}

		$this->restIndex = 0;
		$opts            = getopt(
			'd:Hh:p::u:v',
			['directory:', 'help', 'host:', 'password::', 'username:', 'verbose'],
			$this->restIndex
		);

		if (array_key_exists('H', $opts) || array_key_exists('help', $opts)) {
			throw new HelpException();
		}

		if (array_key_exists('d', $opts)) {
			$this->localPath = $opts['d'];
		}
		elseif (array_key_exists('directory', $opts)) {
			$this->localPath = $opts['directory'];
		}

		if (array_key_exists('h', $opts)) {
			$this->host = $opts['h'];
		}
		elseif (array_key_exists('host', $opts)) {
			$this->host = $opts['host'];
		}

		//  TODO: make password behavior like mysql
		if (array_key_exists('p', $opts)) {
			$this->password = $opts['p'];
		}
		elseif (array_key_exists('password', $opts)) {
			$this->password = $opts['password'];
		}

		if (array_key_exists('u', $opts)) {
			$this->user = $opts['u'];
		}
		elseif (array_key_exists('user', $opts)) {
			$this->user = $opts['user'];
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
		$this->report = new Report($this->verbose);

		$this->localPath = trim($this->localPath);
		if ($this->localPath[0] === '~') {
			$this->localPath = $_SERVER['HOME'] . substr($this->localPath, 1);
		}
		if ($this->localPath !== getcwd()) {
			chdir($this->localPath);
		}
	}

	public function assertInitialized(): bool
	{
		return file_exists(self::MANIFEST_FILE);
	}

	public function __get($name)
	{
		return $this->$name;
	}

	public function openManifest(): void
	{
		if (!isset($this->sqlite)) {
			$this->report->out('Opening manifest DB.');

			if (!is_dir(self::DATA_DIR)) {
				mkdir(self::DATA_DIR);
			}
			$this->sqlite = new SQLite3(self::MANIFEST_FILE);

			$this->sqlite->enableExceptions(true);
			$this->sqlite->exec('PRAGMA encoding = \'UTF-8\'');

			$this->sqlite->exec('CREATE TABLE IF NOT EXISTS' . self::TABLE_NAME . MSync::TABLE_FIELD_DEFS);
		}
	}

	public function openRemoteConnection(): void
	{
		if (!isset($this->sftp)) {
			$this->report->out('Logging into remote system.');
			$key = PublicKeyLoader::load(file_get_contents($this->sshKeyPath));

			$this->sftp = new SFTP($this->host);
			if (!$this->sftp->login($this->user, $key)) {
				throw new \Exception('Login failed');
			}

			$this->sftp->enableDatePreservation();
			$this->sftp->chdir($this->remotePath);
		}
	}

	public static function hdToRegex(...$strs): string
	{
		foreach ($strs as &$s) {
			$s = trim($s);
		}

		return '@^(?:' . strtr(implode('|', $strs), "\n", '|') . ')$@';
	}

	public function getRemoteList(): array
	{
		$this->openRemoteConnection();

		$this->report->out('Building remote file list.');

		$cmd = php_strip_whitespace(__DIR__ . '/../find.php');
		$cmd = substr($cmd, 6);    //	removes "<?php\n"
		$cmd = str_replace(
			['$path', '$plength', '$regexIgnore', '$regexNoHash', '$hashName'],
			[
				'"' . $this->remotePath . '"',
				strlen($this->remotePath),
				'"' . self::hdToRegex($this->IGNORE_REGEX) . '"',
				'"' . self::hdToRegex($this->NO_PUSH_REGEX) . '"',
				'"' . self::HASH_ALGO . '"',
			],
			$cmd
		);

		$cmd .= ' echo json_encode($rtval), "\\n";';

		$response = $this->sftp->exec("echo '{$cmd}' | php -a");
		$response = substr($response, strpos($response, '['));

		return Json::decode($response, JSON_OBJECT_AS_ARRAY);
	}

	public function getDevList(): array
	{
		$this->report->out('Gathering development directory information.');

		$path        = $this->localPath;
		$plength     = strlen($this->localPath);
		$regexIgnore = self::hdToRegex($this->IGNORE_REGEX);
		$regexNoHash = self::hdToRegex($this->NO_PUSH_REGEX);
		$hashName    = self::HASH_ALGO;

		require __DIR__ . '/../find.php';

		return $rtval;
	}

	public function pullFiles(array $files): void
	{
		$this->report->out('Copying remote files to development directory.');
		foreach ($files as $file) {
			if ($file['ftype'] === 'f') {
				$fname = $file['fname'];
				$dname = dirname($fname);

				if (!is_dir($dname)) {
					mkdir($dname, 0755, true);
				}

				if (!file_exists($fname) || $file['hashval'] !== hash_file(self::HASH_ALGO, $fname)) {
					$this->sftp->get($fname, $fname);
				}
			}
		}

		foreach ($files as $file) {
			if ($file['ftype'] === 'd') {
				$dname = $file['fname'];

				if (!is_dir($dname)) {
					mkdir($dname, 0755, true);
				}

				touch($dname, $dir['modts']);
			}
		}
	}

	public function initializeManifest(array $files)
	{
		$this->openManifest();
		$t = $_SERVER['REQUEST_TIME'];

		foreach ($files as $f) {
			$fname = SQLite3::escapeString($f['fname']);
			$res   = $this->sqlite->exec(
				'INSERT INTO ' . self::TABLE_NAME . '(ftype, fname, sizeb, modts, hashval, init_sync, last_sync) ' .
				"VALUES ('{$f['ftype']}', '{$fname}', {$f['sizeb']}, {$f['modts']}, X'{$f['hashval']}', $t, $t)"
			);

			if (!$res) {
				throw new SQLiteException('problem with insert');
			}
		}
	}
}
