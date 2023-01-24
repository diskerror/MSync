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

	protected Opts   $opts;
	protected Report $report;

	public function __construct(array $argv)
	{
		$this->opts   = new Opts($argv);
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
		foreach (new RIterIterator(new RDirIterator($this->opts->conflictPath, FI::SKIP_DOTS)) as $f) {
			//	We found one and we only need one.
			//	It doesn't matter if it was found this time or a previous time.
			return true;
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

		if (file_exists($this->opts->conflictPath)) {
			foreach (new RIterIterator(new RDirIterator($this->opts->conflictPath, FI::SKIP_DOTS)) as $f) {
				unlink($f->getPathname());
			}
		}

		$this->report->out('Getting remote file list.');
		$fileListRemote = new FileListRemote($this->opts, $this->opts->pullRegexIgnore, $this->opts->pullRegexNoHash);

		$this->report->out('Getting local file list.');
		$fileListLocal = new FileListLocal($this->opts, $this->opts->pullRegexIgnore, $this->opts->pullRegexNoHash);

		$this->report->out('Bulding list of files to transfer.');
		//	Local list is actually built here on first call.
		$localCompare = new FileCompareAlgorithm($fileListLocal());

		$this->report->out('Opening SFTP connection to remote directory.');
		$sync = new Sync($this->opts);

		$this->report->out('Pulling non-existent or files that differ.');
		$this->report->statusReset(count($fileListRemote()));
		foreach ($fileListRemote() as $fname => $finfo) {
			if ($localCompare->isDifferent($fname, $finfo)) {
				$sync->pullFileNew($fname, $finfo);
			}
			$this->report->status();
		}
		$this->report->statusLast();
		$sync->updateLocalDirectories($fileListRemote());

		$this->report->out('Writing file info to manifest.');
		(new Manifest($this->opts->manifestFile))->write($fileListRemote());

		$this->report->out('Initialization complete.');
	}


	protected function doPull(): void
	{
		$this->_assertFileExists($this->opts->manifestFile, self::NOT_INITIALIZED);

		$this->report->out('Getting remote file list.');
		$fileListRemote = new FileListRemote($this->opts, $this->opts->pullRegexIgnore, $this->opts->pullRegexNoHash);


		$this->report->out('Finding remote files that have changed.');
		$manifest        = new Manifest($this->opts->manifestFile);
		$manifestCompare = new FileCompareAlgorithm($manifest());
		$remoteChanged   = [];
		foreach ($fileListRemote() as $fname => $info) {
			if ($info['ftype'] === 'f' && $manifestCompare->isDifferent($fname, $info)) {
				$remoteChanged[$fname] = $info;
			}
		}

		$ctRemotChanged = count($remoteChanged);
		if ($ctRemotChanged > 0) {
			//  Build list of local files that have changed.
			$this->report->out('Retrieving local files that might have changed.');
			$fileListLocal = new FileListLocal($this->opts, $this->opts->pullRegexIgnore, $this->opts->pullRegexNoHash);
			$localChanged  = [];
			foreach ($fileListLocal() as $fname => $info) {
				if ($info['ftype'] === 'f' && $manifestCompare->isDifferent($fname, $info)) {
					$localChanged[$fname] = $info;
				}
			}

			$this->report->out('Opening SFTP connection to remote directory.');
			$sync = new Sync($this->opts);

			//  Copy files from remote directory...
			//      If the local file has changed then
			//          put it in the conflict directory.
			//      Else,
			//          copy it in place.
			$this->report->out('Pulling changes from remote directory.');
			$directories = [];
			$this->report->statusReset(count($remoteChanged));
			foreach ($remoteChanged as $rChangedFname => $rChangedInfo) {
				if ($rChangedInfo['ftype'] !== 'd') {
					$sync->pullFileNew($rChangedFname, $rChangedInfo, isset($localChanged[$rChangedFname]));
				}
				else {
					$directories[$rChangedFname] = $rChangedInfo;
				}
				$this->report->status();
			}
			$this->report->statusLast();
			$sync->updateLocalDirectories($directories);

			//  Write changed and new file stats into the manifest.
			$this->report->out('Writing updates to manifest.');
			$manifest->update($remoteChanged);
		}

		//  If there are files in conflict then
		//      launch user's preferred "diff" engine to compare files.
		//      Or print paths to directories to compare.
		if ($this->isConflicted()) {
			exec('phpstorm diff "' . $this->opts->localPath . '" "' . $this->opts->conflictPath . '"');
		}
		elseif ($ctRemotChanged === 0) {
			$this->report->out('Nothing to do.');
		}
		else {
			$this->report->out('Finnished with no conflicts.');
		}
	}


	protected function doPush(): void
	{
		$this->_assertFileExists($this->opts->manifestFile, self::NOT_INITIALIZED);

		if (!file_exists($this->opts->conflictPath) || !is_dir($this->opts->conflictPath)) {
			mkdir($this->opts->conflictPath, 0755, true);
		}

		//  Check conflict directory for files.
		//  If any, abort push and advise user.
		if ($this->isConflicted()) {
			$this->report->shout('Files are still in conflict. Aborting.');
			return;
		}

		//  Build list of remote files that have changed or are new.
		//  If any, abort push and advise user.
		$this->report->out('Getting remote file list.');
		$fileListRemote = new FileListRemote($this->opts, $this->opts->pullRegexIgnore, $this->opts->pullRegexNoHash);


		$this->report->out('Finding remote files that have changed.');
		$manifest        = new Manifest($this->opts->manifestFile);
		$manifestCompare = new FileCompareAlgorithm($manifest());
		$remoteChanged   = [];
		foreach ($fileListRemote() as $fname => $info) {
			if ($info['ftype'] === 'f' && $manifestCompare->isDifferent($fname, $info)) {
				$remoteChanged[$fname] = $info;
			}
		}


		if (count($remoteChanged) > 0) {
			$this->report->shout('There are changed files in the remote directory.');
			$this->report->shout('Perform a "pull" and check changes. Aborting.');
			return;
		}

		//  Build list of local files that have changed.
		$this->report->out('Retrieving local files that might have changed.');
		$fileListLocal = new FileListLocal($this->opts, $this->opts->pullRegexIgnore, $this->opts->pullRegexNoHash);
		$localChanged  = [];
		foreach ($fileListLocal() as $fname => $info) {
			if ($info['ftype'] === 'f' && $manifestCompare->isDifferent($fname, $info)) {
				$localChanged[$fname] = $info;
			}
		}

		if (count($localChanged) === 0) {
			$this->report->out('Nothing to do.');
			return;
		}

		$this->report->out('Opening SFTP connection to remote directory.');
		$sync = new Sync($this->opts);

		$this->report->out('Pushing changes to remote directory.');
		$directories = [];
		$this->report->statusReset(count($localChanged));
		foreach ($localChanged as $fname => $info) {
			if ($info['ftype'] !== 'd') {
				if (isset($localChanged[$fname])) {
					//  If a local file is changing from a link to a regular file then
					//      first delete the link or data will write to the wrong place.
					if (is_link($fname)) {
						unlink($fname);

						if (file_exists($this->opts->conflictPath . $fname)) {
							unlink($this->opts->conflictPath . $fname);
						}
					}

					$sync->pullFileNew($fname, $info, true);
				}
				else {
					$sync->pullFileNew($fname, $info);
				}
			}
			else {
				$directories[$fname] = $info;
			}
			$this->report->status();
		}
		$this->report->statusLast();
		$sync->updateRemoteDirectories($directories);

		$this->report->out('Writing updates to manifest.');
		$manifest->update($localChanged);
	}


	protected function doResolve(): void
	{
		$this->_assertFileExists($this->opts->manifestFile, self::NOT_INITIALIZED);

		$fullPath = realpath($this->opts->conflictPath . $this->opts->fileToResolve);

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
		$this->report->out('"' . $this->opts->fileToResolve . '" has been removed.');
	}

}
