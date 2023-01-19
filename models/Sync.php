<?php

namespace Model;

use Laminas\Json\Json;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SFTP;
use UnexpectedValueException;

/**
 * Msync class.
 */
class Sync
{
	/**
	 * Settings from command line and config file.
	 */
	protected Opts $opts;

	/**
	 * Functional classes.
	 */
	protected SFTP $sftp;

	public function __construct(Opts $opts)
	{
		$this->opts = $opts;

		if ($this->opts->localPath !== getcwd()) {
			chdir($this->opts->localPath);
		}

		$key = PublicKeyLoader::load(file_get_contents($this->opts->sshKeyPath));

		$this->sftp = new SFTP($this->opts->host);
		if (!$this->sftp->login($this->opts->user, $key)) {
			throw new UnableToConnectException('Login failed');
		}

		$this->sftp->enableDatePreservation();
		$this->sftp->chdir($this->opts->remotePath);
	}

	public function getRemoteList(): array
	{
		$cmd = php_strip_whitespace('find.php');
		$cmd = substr($cmd, 6);    //	removes "<?php\n"

		//	Replace variables with literal strings.
		$cmd = str_replace(
			['$path', '$plength', '$regexIgnore', '$regexNoHash', '$hashAlgo'],
			[
				'"' . $this->opts->remotePath . '"',
				strlen($this->opts->remotePath),
				'"' . Opts::heredocToRegex($this->opts->IGNORE_REGEX) . '"',
				'"' . Opts::heredocToRegex($this->opts->NO_PUSH_REGEX) . '"',
				'"' . Opts::HASH_ALGO . '"',
			],
			$cmd
		);


		$cmd .= ' echo json_encode($rtval);';	//	New line provided by echo '$cmd' below

		$response = $this->sftp->exec("echo '$cmd' | php -a");

		//	Remove text before first '[' or '{'.
		$response = preg_replace('/^[^[{]*(.+)$/sAD', '$1', $response);
		
		if($response==''){
			throw new UnexpectedValueException('Blank response from remote.');
		}

		return Json::decode($response, JSON_OBJECT_AS_ARRAY);
	}

	public function getLocalList(): array
	{
		$path        = $this->opts->localPath;
		$plength     = strlen($this->opts->localPath);
		$regexIgnore = Opts::heredocToRegex($this->opts->IGNORE_REGEX);
		$regexNoHash = Opts::heredocToRegex($this->opts->NO_PUSH_REGEX);
		$hashAlgo    = Opts::HASH_ALGO;

		require 'find.php';

		return $rtval;
	}

	public function pullFiles(array $files): void
	{
		$report = new Report($this->opts->verbose);
		$i      = 0;
		$of_ct  = ' of ' . count($files);

		$directories = [];
		foreach ($files as $fname => $info) {
			++$i;
			$dname = dirname($fname);

			if (!file_exists($dname)) {
				mkdir($dname, 0755, true);
			}

			if ($info['ftype'] === 'f') {
				/**
				 * Copy the file to here when:
				 *        it doesn't exist locally
				 *        it was not hashed but sizes differ or mod times differ
				 *        it was hashed and the hashes differ
				 */
				if (!file_exists($fname) ||
					($info['hashval'] === '' ?
						($info['sizeb'] != filesize($fname) || $info['modts'] != filemtime($fname)) :
						$info['hashval'] !== hash_file(Opts::HASH_ALGO, $fname)
					)
				) {
					$this->sftp->get($fname, $fname);
				}
			}
			elseif ($info['ftype'] === 'l') {
				/**
				 * Copy the file to here when:
				 *        it doesn't exist locally
				 *        it is not a symbolic link
				 */
				if (!file_exists($fname) || !is_link($fname)) {
					if (file_exists($fname)) {
						unlink($fname);
					}

					$target = $this->sftp->exec("php -r 'echo readlink(\"{$this->opts->remotePath}/$fname\");'");
					symlink($target, $fname);
				}
			}
			elseif ($info['ftype'] === 'd') {
				$directories[$fname] = $info;
			}
			else {
				$report->out(PHP_EOL . 'WARNING: Inknown file type: ' . $info['ftype']);
			}

			if ($i % 23 == 0) {
				$report->status($i . $of_ct);
			}
		}

		/**
		 * Directories must be done after placing files so that mod times aren't changed.
		 */
		foreach ($directories as $dname => $dinfo) {
			if (!file_exists($dname)) {
				mkdir($dname, 0755, true);
			}

			touch($dname, $dinfo['modts']);
		}

		$report->status($i . $of_ct);
		$report->out('');
	}

	public function pullFile(string $fname, array $info): bool
	{
		$dname = dirname($fname);

		if (!file_exists($dname)) {
			mkdir($dname, 0755, true);
		}

		switch ($info['ftype']) {
			case 'f':
				$this->pullRegularFile($fname, $info['fname'], $fname);
				return true;

			case 'l':
				if (file_exists($fname)) {
					unlink($fname);
				}

				$target = $this->sftp->exec("php -r 'echo readlink(\"{$this->opts->remotePath}/$fname\");'");
				symlink($target, $fname);

				return true;
		}

		return false;
	}

	public function pullToConflict(string $fname, array $info)
	{
		$this->pullRegularFile($fname, $info, $this->opts->conflictPath . '/' . $fname);
	}

	public function pullRegularFile(string $remoteFname, array $remoteInfo, string $localFname): void
	{
		if ($remoteInfo['ftype'] !== 'f') {
			throw new UnexpectedValueException('Only regular file can be copied to the conflict directory.');
		}

		$localDname = dirname($localFname);
		if (!file_exists($localDname)) {
			mkdir($localDname, 0755, true);
		}

		//	It might already be in the conflict folder.
		/**
		 * Copy the file to here when:
		 *        it doesn't exist locally
		 *        it was not hashed but sizes differ or mod times differ
		 *        it was hashed and the hashes differ
		 */
		if (!file_exists($localFname) ||
			($remoteInfo['hashval'] === '' ?
				($remoteInfo['sizeb'] != filesize($localFname) || $remoteInfo['modts'] != filemtime($localFname)) :
				$remoteInfo['hashval'] !== hash_file(Opts::HASH_ALGO, $localFname)
			)
		) {
			$this->sftp->get($remoteFname, $localFname);
		}
	}
	
	public function pushFiles(array $filesToPush, array $remoteList):void{
		$report = new Report($this->opts->verbose);
		$i      = 0;
		$of_ct  = ' of ' . count($filesToPush);

		$directories = [];
		foreach ($filesToPush as $pushFname => $pushInfo) {
			++$i;
			$dname = dirname($pushFname);

			if (!isset($remoteList[$dname])) {
				$this->sftp->mkdir($dname, 0775, true);
			}

			if ($pushInfo['ftype'] === 'f') {
				/**
				 * Copy the file to here when:
				 *        it doesn't exist remotely
				 *        it was not hashed but sizes differ or mod times differ
				 *        it was hashed and the hashes differ
				 */
				if (!isset($remoteList[$pushFname]) ||
					($pushInfo['hashval'] === '' ?
						($pushInfo['sizeb'] != $remoteList[$pushFname]['sizeb'] || $pushInfo['modts'] != $remoteList[$pushFname]['modts']) :
						$pushInfo['hashval'] !== $remoteList[$pushFname]['hashval']
					)
				) {
					$this->sftp->put($pushFname, $pushFname, SFTP::SOURCE_LOCAL_FILE);
				}
			}///////////////////////////////////////////////////////////////////////////////////////////
			elseif ($pushInfo['ftype'] === 'l') {
				/**
				 * Copy the file to remote directory when:
				 *        it doesn't exist remotely
				 *        it is not a symbolic link
				 */
				if (!file_exists($pushFname) || !is_link($pushFname)) {
					if (file_exists($pushFname)) {
						unlink($pushFname);
					}

					$target = $this->sftp->exec("php -r 'echo readlink(\"{$this->opts->remotePath}/$pushFname\");'");
					symlink($target, $pushFname);
				}
			}
			elseif ($pushInfo['ftype'] === 'd') {
				$directories[$pushFname] = $pushInfo;
			}
			else {
				$report->out(PHP_EOL . 'WARNING: Inknown file type: ' . $pushInfo['ftype']);
			}

			if ($i % 23 == 0) {
				$report->status($i . $of_ct);
			}
		}

		/**
		 * Directories must be done after placing files so that mod times aren't changed.
		 */
		foreach ($directories as $dname => $dinfo) {
			if (!file_exists($dname)) {
				mkdir($dname, 0755, true);
			}

			touch($dname, $dinfo['modts']);
		}

		$report->status($i . $of_ct);
		$report->out('');
	}
}
