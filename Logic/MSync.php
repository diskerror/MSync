<?php

namespace Logic;

use FilesystemIterator as FI;
use RecursiveDirectoryIterator as RDirIterator;
use RecursiveIteratorIterator as RIterIterator;
use RuntimeException;
use UnexpectedValueException;

class MSync
{
	public const NOT_INITIALIZED = <<<'NDOC'
		This is not an msync managed directory.
		Use ‘msync init’ to create local workspace.
		
		NDOC;

	protected Options $opts;
	protected Report  $report;

	public function __construct(array $argv)
	{
		$this->opts   = new Options($argv);
		$this->report = new Report($this->opts->verbose);
	}

	public function run()
	{
		switch ($this->opts->verb) {
			case 'help':
				throw new HelpException();

			case 'init':
				$this->doInit();
			break;

			case 'pull':
				$this->doPull();
			break;

			case 'push':
				$this->doPush();
			break;

			case 'resolve':
				$this->doResolve();
			break;

			case 'conflicted':
				$this->showConflicted();
			break;

			default:
				throw new UnexpectedValueException('Unknown verb.');
		}
	}

	protected function _assertFileExists(string $file, string $message = '')
	{
		if (!file_exists($file)) {
			throw new RuntimeException($message == '' ? '"' . $file . '" does not exist.' : $message);
		}
	}

	protected function isConflicted(): bool
	{
		if (file_exists($this->opts->conflictPath)) {
			foreach (new RIterIterator(new RDirIterator($this->opts->conflictPath, FI::SKIP_DOTS)) as $f) {
				//	We found one and we only need one.
				return true;
			}
		}
		return false;
	}


	protected function doInit(): void
	{
		$continueYN = 'no';   //  default reply
		if (file_exists($this->opts->manifestFile)) {
			$this->report->shout('This is an MSync managed directory!');
			$this->report->shout('Do you wish to update or replace all working files? [y|N]: ', false);
		}
		else {
			$this->report->shout('Do you wish to update or add to the contents of this directory? [y|N]: ', false);
		}

		if ($rline = readline('')) {
			$continueYN = $rline;
		}

		if (substr(strtolower($continueYN), 0, 1) !== 'y') {
			$this->report->out('Canceled.' . PHP_EOL);
			return;
		}

//		if (file_exists($this->opts->conflictPath)) {
//			foreach (new RIterIterator(new RDirIterator($this->opts->conflictPath, FI::SKIP_DOTS)) as $f) {
//				unlink($f->getPathname());
//			}
//		}

		exec("rm -rf '{$this->opts->conflictPath}/*'");

//		$this->report->out('Getting remote file list.');
//		$this->opts->addFileToNoHash();
//		$fileListRemote = new FileListRemote($this->opts);

//		$this->report->out('Getting local file list.');
//		$fileListLocal = new FileListLocal($this->opts);

//		$this->report->out('Opening SFTP connection to remote directory.');
//		$transfer = new Xfer($this->opts);

//		$this->report->out('Pulling new or different files.');
//		$this->report->statusReset(count($fileListRemote));
//		foreach ($fileListRemote as $fname => $finfo) {
//			if ($fileListLocal->isDifferent($fname, $finfo)) {
//				$transfer->pullFile($fname, $finfo);
//			}
//			$this->report->status();
//		}
//		$this->report->statusLast();
//		unset($transfer);

//		$this->report->out('Writing file info to manifest.');
//		$manifest = new Manifest($this->opts);
//		//	Clear old data if any.
//		$manifest->unsetAll();
//		$manifest->update($fileListRemote);

		$this->report->out('Pulling new or different files.');
		$cmd = "rsync " . ($this->opts->verbose ? "--info=progress2 " : "") .
			"--filter=._- -rltDme ssh {$this->opts->user}@{$this->opts->host}:'{$this->opts->remotePath}/' '{$this->opts->localPath}/'";
		passthru("echo '{$this->opts->initIgnore}' | {$cmd}");

		$this->report->out('Building file list.');
		$fileListLocal = new FileListLocal($this->opts);

		$this->report->out('Writing file info to manifest.');
		$manifest = new Manifest($this->opts);
		//	Clear old data if any.
		$manifest->unsetAll();
		$manifest->update($fileListLocal);

		$this->report->out('Initialization complete.');
	}


	protected function doPull(): void
	{
		$this->_assertFileExists($this->opts->manifestFile, self::NOT_INITIALIZED);

		$this->report->out('Getting remote file list.');
		$this->opts->addFileToNoHash('neverPush.txt');
		$fileListRemote = new FileListRemote($this->opts,);


		$this->report->out('Finding remote files that have changed.');
		$manifest      = new Manifest($this->opts);
		$remoteChanged = new FileList($this->opts);
		foreach ($fileListRemote as $fname => $info) {
			if ($info['ftype'] === 'f' && $manifest->isDifferent($fname, $info)) {
				$remoteChanged[$fname] = $info;
			}
		}

		$ctRemotChanged = count($remoteChanged);
		if ($ctRemotChanged > 0) {
			//  Build list of local files that have changed.
			$this->report->out('Retrieving local files that might have changed.');
			$fileListLocal = new FileListLocal($this->opts);
			$localChanged  = new FileList($this->opts);
			foreach ($fileListLocal as $fname => $info) {
				if ($info['ftype'] === 'f' && $manifest->isDifferent($fname, $info)) {
					$localChanged[$fname] = $info;
				}
			}

			//	Make sure directory is ready for possible conflicts.
			if (!file_exists($this->opts->conflictPath)) {
				mkdir($this->opts->conflictPath, 0755, true);
			}

			$this->report->out('Opening SFTP connection to remote directory.');
			$transfer = new Xfer($this->opts);

			//  Copy files from remote directory...
			//      If the local file has changed then
			//          put it in the conflict directory.
			//      Else,
			//          copy it in place.
			$this->report->out('Pulling changes from remote directory.');
			$this->report->statusReset(count($remoteChanged));
			foreach ($remoteChanged as $rChangedFname => $rChangedInfo) {
				$transfer->pullFile($rChangedFname, $rChangedInfo, isset($localChanged[$rChangedFname]));
				$this->report->status();
			}
			$this->report->statusLast();
			unset($transfer);

			//  Write changed and new file stats into the manifest.
			$this->report->out('Writing changes to manifest.');
			$manifest->update($remoteChanged);
		}

		//  If there are files in conflict then
		//      launch user's preferred "diff" engine to compare files.
		if ($this->isConflicted()) {
			exec($this->opts->diffTool . ' "' . $this->opts->localPath . '" "' . $this->opts->conflictPath . '"');
		}
		elseif ($ctRemotChanged === 0) {
			$this->report->out('Nothing to do.');
		}
		else {
			$this->report->out('Finished with no conflicts.');
		}
	}


	protected function doPush(): void
	{
		$this->_assertFileExists($this->opts->manifestFile, self::NOT_INITIALIZED);

		//  Check conflict directory for files.
		//  If any, abort push and advise user.
		if ($this->isConflicted()) {
			$this->report->shout('Files are still in conflict. Aborting.');
			return;
		}

		//  Build list of local files that have changed.
		$this->report->out('Gathering local files that have changed.');
		$manifest      = new Manifest($this->opts);
		$fileListLocal = new FileListLocal($this->opts);
		$localChanged  = new FileList($this->opts);
		foreach ($fileListLocal as $fname => $info) {
			if ($info['ftype'] !== 'd' && $manifest->isDifferent($fname, $info)) {
				$localChanged[$fname] = $info;
			}
		}

		if (count($localChanged) === 0) {
			$this->report->out('Nothing to do.');
			return;
		}

		//  Build list of remote files that have changed or are new.
		//  If any, abort push and advise user.
		$this->report->out('Getting remote file list.');
		$this->opts->addFileToIgnore('neverPush.txt');
		$fileListRemote = new FileListRemote($this->opts);

		$this->report->out('Finding remote files that have changed.');
		foreach ($fileListRemote as $fname => $info) {
			if ($info['ftype'] === 'f' && $manifest->isDifferent($fname, $info)) {
				//	All we need is one.
				$this->report->shout('There are changed files in the remote directory.');
				$this->report->shout('Perform a “pull” and check changes. Aborting.');
				return;
			}
		}

		$this->report->out('Opening SFTP connection to remote directory.');
		$transfer = new Xfer($this->opts);

		$this->report->out('Pushing changes to remote directory.');
		$this->report->statusReset(count($localChanged));
		foreach ($localChanged as $fname => $info) {
			$transfer->pushFile($fname, $info);
			$this->report->status();
		}
		$this->report->statusLast();
		unset($transfer);

		$this->report->out('Writing updates to manifest.');
		$manifest->update($localChanged);
	}


	protected function doResolve(): void
	{
		$this->_assertFileExists($this->opts->manifestFile, self::NOT_INITIALIZED);

		$fullPath = $this->opts->conflictPath . $this->opts->fileToResolve;

		//  Written as assertions.
		switch (false) {
			case (strpos($this->opts->fileToResolve, '..') === false):
				throw new UnexpectedValueException('Cannot point to file outside project.');

			case ($this->opts->fileToResolve[0] !== '/'):
				throw new UnexpectedValueException('Parameter must be a relative path to file.');

			case file_exists($fullPath):
				throw new UnexpectedValueException('File has been resolved or does not exist.');

			case is_file($fullPath):
				throw new UnexpectedValueException('Parameter must be a relative path to a regular file.');
		}

		unlink($fullPath);
		$this->report->out('"' . $this->opts->fileToResolve . '" has been marked as resolved.');
	}


	protected function showConflicted(): void
	{
		$this->_assertFileExists($this->opts->manifestFile, self::NOT_INITIALIZED);

		if (file_exists($this->opts->conflictPath)) {
			$cpLength = strlen($this->opts->conflictPath);
			foreach (new RIterIterator(new RDirIterator($this->opts->conflictPath, FI::SKIP_DOTS)) as $f) {
				$this->report->shout(substr($f->getPathname(), $cpLength));
			}
		}
	}

}
