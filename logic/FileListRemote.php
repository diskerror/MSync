<?php

namespace Logic;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SSH2;
use UnexpectedValueException;

class FileListRemote extends FileList
{

	public function setStatsArray(): void
	{
		$key = PublicKeyLoader::load(file_get_contents($this->opts->sshKeyPath));
		$ssh = new SSH2($this->opts->host);

		if (!$ssh->login($this->opts->user, $key)) {
			throw new UnableToConnectException('Login failed');
		}

		//	Also removes all comments.
		$cmd = php_strip_whitespace('find.php');
		//	remove everything before the first '$', ie. "<?php\n" & etc.
		$cmd = strstr($cmd, '$ret');
		$cmd = strtr($cmd, "'", '"');    //	change all possible single-quotes to double
		$cmd = 'ini_set("memory_limit", "512M"); ' . $cmd;    //	removes "<?php\n"

		//	Replace variables with literal strings.
		$cmd = str_replace(
			['$path', '$plength', '$regexIgnore', '$regexNoHash', '$hashAlgo'],
			[
				'"' . $this->opts->remotePath . '"',
				strlen($this->opts->remotePath),
				'"' . $this->regexIgnore . '"',
				'"' . $this->regexNoHash . '"',
				'"' . Opts::HASH_ALGO . '"',
			],
			$cmd
		);


		$cmd .= ' echo json_encode($ret);';    //	New line provided by echo '$cmd' below

		$response = $ssh->exec("echo '$cmd' | php -a");

		//	Remove text before first '[' or '{'.
		$response = preg_replace('/^[^[{]*(.+)$/sAD', '$1', $response);

		if ($response == '') {
			throw new UnexpectedValueException('Blank response from remote.');
		}

		$this->fileStatsArray =
			json_decode($response, JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
	}

}
