#!/usr/bin/env php
<?php

use Logic\Options;

require 'Options.php';
$opts = new Options($argv);

$path        = $opts->localPath;
$plength     = strlen($path);
$regexIgnore = '@^(.*/\..*|/vendor/.*)$@';
$regexNoHash = '@^(.*\.jpg|.*\.exe)$@';
$hashAlgo    = Options::HASH_ALGO;

require 'find.php';

echo json_encode($ret, JSON_PRETTY_PRINT), "\n";
