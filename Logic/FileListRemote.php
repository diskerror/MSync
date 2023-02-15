<?php

namespace Logic;

use Exception;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\UnableToConnectException;
use phpseclib3\Net\SSH2;
use UnexpectedValueException;

class FileListRemote extends FileList
{

	public function init(): void
	{
		$sshKey = PublicKeyLoader::load(file_get_contents($this->opts->sshKeyPath));
		$ssh    = new SSH2($this->opts->host);

		if (!$ssh->login($this->opts->user, $sshKey)) {
			throw new UnableToConnectException('Login failed');
		}

		//	Also removes all comments.
		$cmd = php_strip_whitespace('find.php');
		//	remove everything before the first '$', ie. "<?php\n" & etc.
		$cmd = strstr($cmd, '$ret');
		$cmd = strtr($cmd, "'", '"');    //	change all possible single-quotes to double
		$cmd = 'try{ini_set("memory_limit","-1"); ' . $cmd;

		//	Replace variables with literal strings.
		$cmd = str_replace(
			['$path', '$plength', '$regexIgnore', '$regexNoHash', '$hashAlgo'],
			[
				'"' . $this->opts->remotePath . '"',
				strlen($this->opts->remotePath),
				'"' . $this->opts->regexIgnore . '"',
				'"' . $this->opts->regexNoHash . '"',
				'"' . Options::HASH_ALGO . '"',
			],
			$cmd
		);

		if(false) {
			//	Required new-line provided by echo '$cmd' below.
			$cmd .= '}catch(Throwable $t){$ret["__exception"]=$t->__toString();}echo json_encode($ret);';

			$response = $ssh->exec("echo '$cmd' | php -a");
		}
		else {
			//	Required new-line provided by echo '$cmd' below.
			$cmd .= '}catch(Throwable $t){$ret["__exception"]=$t->__toString();}file_put_contents("msync.json",json_encode($ret));';

			//	It's written to a file (file_put_contents) first because some systems can't do this in memory.
			//	The file is written in the users home directory as the SSH2 object doesn't retain working directory.
			$ssh->exec("echo '$cmd' | php -a");
			$response = $ssh->exec("cat msync.json ; rm msync.json");
		}

		//	Remove text before first '[' or '{'.
		$response = preg_replace('/^[^[{]*(.+)$/sAD', '$1', $response);

		if ($response == '') {
			throw new UnexpectedValueException('Blank response from remote.');
		}

		$this->fileList =
			json_decode($response, JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);

		if (key_exists('__exception', $this->fileList)) {
			throw new Exception($this->fileList['__exception']);
		}
	}

}
