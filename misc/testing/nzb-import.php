<?php

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bootstrap/autoload.php';

use Blacklight\NZBImport;

$n = PHP_EOL;

// Print usage.
if (count($argv) !== 6) {
    exit(
        'This will import NZB files(.nzb or .nzb.gz), into your newznab site from a folder recursively(it will go down into sub-folders).'.$n.
        'Please use arg5, something sensible like 100k, if you have millions of NZB files the initial scan will be VERY slow otherwise.'.$n.$n.
        'Usage: '.$n.
        $_SERVER['_'].' '.__FILE__.' arg1 arg2 arg3 arg4 arg5'.$n.$n.
        'arg1 : Path to folder where NZB files are stored.                | a folder path'.$n.
        'arg2 : Delete NZB when successfully imported.(recommended)       | true/false'.$n.
        'arg3 : Delete NZB when unsuccessfully imported.(not recommended) | true/false'.$n.
        'arg4 : Use NZB file name as release name.(not recommended)       | true/false'.$n.
        'arg5 : Import this many NZB files. (RECOMMENDED 100,000)         | a number'.$n.$n.
        'ie: '.$_SERVER['_'].' '.__FILE__.' '.base_path('nzbToImport/ true false false 1000').$n
    );
}

// Verify arguments.
if (! is_dir($argv[1])) {
    exit('Error: arg1 must be a path (you might not have read access to this path)'.$n);
}
if (! in_array($argv[2], ['true', 'false'], false)) {
    exit('Error: arg2 must be true or false'.$n);
}
if (! in_array($argv[3], ['true', 'false'], false)) {
    exit('Error: arg3 must be true or false'.$n);
}
if (! in_array($argv[4], ['true', 'false'], false)) {
    exit('Error: arg4 must be true or false'.$n);
}
if (! is_numeric($argv[5])) {
    exit('Error: arg5 must be a number'.$n);
}
if ($argv[5] < 0) {
    exit('Error: arg5 must be 0 or higher'.$n);
}

$path = $argv[1];
// Check if path ends with dir separator.
if (! Str::endsWith($path, '/')) {
    $path .= '/';
}

$files = new \RegexIterator(
    new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($argv[1])
    ),
    '/^.+\.nzb(\.gz)?$/i',
    \RecursiveRegexIterator::GET_MATCH
);

$i = 1;
$nzbFiles = [];
foreach ($files as $file) {
    $nzbFiles[] = $file[0];
    if ($i++ >= $argv[5]) {
        break;
    }
}

if ($i > 1) {
    unset($files);

    // Check these user argument values, convert them to bool.
    $deleteNZB = ($argv[2] === 'true');
    $deleteFailedNZB = ($argv[3] === 'true');
    $useNzbName = ($argv[4] === 'true');

    // Create a new instance of NZBImport and send it the file locations.
    $NZBImport = new NZBImport();

    $NZBImport->beginImport($nzbFiles, $useNzbName, $deleteNZB, $deleteFailedNZB);
} else {
    echo 'Nothing found to import!'.$n;
}
