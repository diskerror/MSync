<?php

namespace Logic;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SFTP;

/**
 * Msync class.
 *
 * @param array $remoteList
 * @param array $localList
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
	public array   $destinationList;

	public function __construct(Opts $opts)
	{
		$this->opts = $opts;

		chdir($this->opts->localPath);

		$key = PublicKeyLoader::load(file_get_contents($this->opts->sshKeyPath));

		$this->sftp = new SFTP($this->opts->host);
		if (!$this->sftp->login($this->opts->user, $key)) {
			throw new UnableToConnectException('Login failed');
		}

		$this->sftp->enableDatePreservation();
		$this->sftp->chdir($this->opts->remotePath);
	}

	public function __get($name)
	{
		return $this->$name;
	}

	public function pullFileNew(string $remotefname, array $remoteInfo, bool $toConflict = false): void
	{
		$localfname = ($toConflict ? $this->opts->conflictPath : '') . $remotefname;
		$tempName   = $localfname . Opts::TEMP_SUFFIX;

		$dname = $remoteInfo['ftype'] === 'd' ? $tempName : dirname($tempName);
		if (!file_exists($dname)) {
			mkdir($dname, 0755, true);
		}

		switch ($remoteInfo['ftype']) {
			case 'f':
				$this->sftp->get($remotefname, $tempName);
				rename($tempName, $localfname);
			break;

			case 'l':
				$target = $this->sftp->readlink($remotefname);
				symlink($target, $tempName);
				rename($tempName, $localfname);
			break;
		}
	}

	public function pushFileNew(string $localfname, array $localInfo): void
	{
		$dname = $localInfo['ftype'] === 'd' ? $localfname : dirname($localfname);
		if (!isset($remoteList[$dname])) {
			$this->sftp->mkdir($dname, 0775, true);
		}

		switch ($localInfo['ftype']) {
			case 'f':
				$tempName = $localfname . Opts::TEMP_SUFFIX;
				$this->sftp->put($tempName, $localfname, SFTP::SOURCE_LOCAL_FILE);
				$this->sftp->exec(<<<HDOC
					chmod -f 664 "{$this->opts->remotePath}/{$tempName}"
					chgrp -f {$this->opts->group} "{$this->opts->remotePath}/{$tempName}"
					mv -f "{$this->opts->remotePath}/{$tempName}" "{$this->opts->remotePath}/{$localfname}"\n
					HDOC
				);
			break;

			case 'l':
				$tempName = $localfname . Opts::TEMP_SUFFIX;
				$target   = readlink($localfname);
				$this->sftp->exec('ln -fs "' . $target . '" "' . $tempName . '"');
				$this->sftp->exec(<<<HDOC
					chmod -f 664 "{$this->opts->remotePath}/{$tempName}"
					chgrp -f {$this->opts->group} "{$this->opts->remotePath}/{$tempName}"
					touch -m -d @{$localInfo['modts']} "{$this->opts->remotePath}/{$tempName}"
					mv -f "{$this->opts->remotePath}/{$tempName}" "{$this->opts->remotePath}/{$localfname}"\n
					HDOC
				);
		}
	}

	/**
	 * Directories must be done after placing files so that mod times aren't changed.
	 */
	public function updateLocalDirectories(array $fileList)
	{
		foreach ($fileList as $name => $info) {
			if ($info['ftype'] === 'd') {
				if (!file_exists($name)) {
					mkdir($name, 0755, true);
				}

				touch($name, $info['modts']);
			}
		}
	}

	public function updateRemoteDirectories(array $fileList)
	{
//		foreach ($fileList as $dname => $dinfo) {
//			if ($dinfo['ftype'] === 'd') {
//				if (!file_exists($dname)) {
//					mkdir($dname, 0755, true);
//				}
//
//				//	Do touch only if remote directory owner is the same as ssh user. (else it fails)
//				touch($dname, $dinfo['modts']);
//			}
//		}
	}

}
