<?php

namespace Logic;

use Ds\Queue;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SFTP;

/**
 * Xfer class.
 */
class Xfer
{
	protected Options $opts;
	protected SFTP    $sftp;

	protected Queue $remoteDirsToUpdate;
	protected Queue $localDirsToUpdate;


	public function __construct(Options $opts)
	{
		$this->opts = $opts;

		$this->remoteDirsToUpdate = new Queue();
		$this->localDirsToUpdate  = new Queue();

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

	public function __destruct()
	{
		$this->updateDirectories();
	}


	public function __get($name)
	{
		return $this->$name;
	}

	public function pullFile(string $remoteFname, array $remoteInfo, bool $toConflict = false): void
	{
		$localfname = ($toConflict ? $this->opts->conflictPath : '') . $remoteFname;
		$tempName   = $localfname . Options::TEMP_SUFFIX;

		$dname = preg_replace('@^(.*)/[^/]+$@', '$1', $tempName);
		if (!file_exists($dname)) {
			mkdir($dname, 0775, true);
		}

		switch ($remoteInfo['ftype']) {
			case 'f':
				$this->sftp->get($remoteFname, $tempName);
				rename($tempName, $localfname);
			break;

			case 'l':
				$target = $this->sftp->readlink($remoteFname);
				symlink($target, $tempName);
				rename($tempName, $localfname);
			break;

			case 'd':
				$remoteInfo['fname'] = $remoteFname;
				$this->localDirsToUpdate->push($remoteInfo);
			break;
		}
	}

	public function pushFile(string $localFname, array $localInfo): void
	{
		$tempName = $localFname . Options::TEMP_SUFFIX;

		$dname = preg_replace('@^(.*)/[^/]+$@', '$1', $localFname);
		if (!isset($remoteList[$dname])) {
			$this->sftp->mkdir($dname, 0775, true);
		}

		switch ($localInfo['ftype']) {
			case 'f':
				$this->sftp->put($tempName, $localFname, SFTP::SOURCE_LOCAL_FILE);
				$this->sftp->chmod($tempName, 0664);
				$this->sftp->exec("chgrp -f {$this->opts->group} '{$this->opts->remotePath}/{$tempName}'\n");
				$this->sftp->delete($localFname);
				$this->sftp->rename($tempName, $localFname);

//					$this->sftp->exec(<<<HDOC
//					chmod -f 664 "{$this->opts->remotePath}/{$tempName}"
//					chgrp -f {$this->opts->group} "{$this->opts->remotePath}/{$tempName}"
//					mv -f "{$this->opts->remotePath}/{$tempName}" "{$this->opts->remotePath}/{$localfname}"
//
//					HDOC
//					);
			break;

			case 'l':
				$target = readlink($localFname);
				$this->sftp->symlink($target, $tempName);
				$this->sftp->touch($tempName, $localInfo['mtime'], $localInfo['mtime']);
				$this->sftp->chmod($tempName, 0664);
				$this->sftp->exec("chgrp -f {$this->opts->group} '{$this->opts->remotePath}/{$tempName}'\n");
				$this->sftp->delete($localFname);
				$this->sftp->rename($tempName, $localFname);

//					$this->sftp->exec('ln -fs "' . $target . '" "' . $tempName . '"');
//					$this->sftp->exec(<<<HDOC
//					touch -m -d @{$localInfo['mtime']} "{$this->opts->remotePath}/{$tempName}"
//					chmod -f 664 "{$this->opts->remotePath}/{$tempName}"
//					chgrp -f {$this->opts->group} "{$this->opts->remotePath}/{$tempName}"
//					mv -f "{$this->opts->remotePath}/{$tempName}" "{$this->opts->remotePath}/{$localfname}"
//
//					HDOC
//					);
			break;

			case 'd':
				$localInfo['fname'] = $localFname;
				$this->remoteDirsToUpdate->push($localInfo);
			break;
		}
	}

	/**
	 * Directories must be done after placing files so that mod times are transferred.
	 * Empty directories will not be copied.
	 */
	protected function updateDirectories(): void
	{
		while (!$this->localDirsToUpdate->isEmpty()) {
			$d = $this->localDirsToUpdate->pop();
			if (file_exists($d['fname'])) {
				touch($d['fname'], $d['mtime']);
			}
		}

		while (!$this->remoteDirsToUpdate->isEmpty()) {
			$d = $this->remoteDirsToUpdate->pop();
			if ($this->sftp->file_exists($d['fname'])) {
				$this->sftp->touch($d['fname'], $d['mtime'], $d['mtime']);
			}
		}
	}

}
