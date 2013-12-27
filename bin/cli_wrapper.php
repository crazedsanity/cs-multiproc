#!/usr/bin/php
<?php


$E_BADARGS=85;
$E_ERROR=1;
$E_OK=0;

$shortOpts  = "";
$longOpts   = array();


$shortOpts	.= "h";		//specifying "-h" will print out usage info then exit
$longOpts[]	 = "help";	//"--help" is equivalent to "-h"; print usage info then exit.

$options = getopt($shortOpts, $longOpts);

//TODO: get cs-content/__autoload.php work here...
require_once(dirname(__FILE__) .'/../../cs-content/cs_version.class.php');
require_once(dirname(__FILE__) .'/../cs_SingleProcess.class.php');
$p = new cs_SingleProcess();


$basename = basename(__FILE__);
$project = $p->project;
$version = $p->version;
$USAGE = <<<EOT
usage: $basename [options] -- /path/to/script.pl [cmd arg...]
options: 
      -h, --help              - Print a help message then exit

This script is a wrapper script which spawns another command and monitors 
the output (STDERR and STDOUT, independently) and exit code.  It is meant to be 
spawned by a daemon that logs the information to a web application via an API.

For more information, view the library's project page:
	https://github.com/crazedsanity/$project
		
For usage examples, take a look at the WebCron project:
	wiki: https://github.com/crazedsanity/WebCron/wiki
	main: https://github.com/crazedsanity/WebCron
		
There may be issues regarding quoting of arguments of the command (or quoting 
the command itself); in that case, please contact the owner and/or create an 
issue on the WebCron project.

PROJECT NAME: $project
VERSION: $version

EOT;


if($_SERVER['argc'] >= 2) {
	$bits = $_SERVER['argv'];
	array_shift($bits);
	
	$implodeThis = array();
	foreach($bits as $xBit) {
		if(preg_match('/ /', $xBit)) {
			$xBit = '"'. $xBit .'"';
		}
		$implodeThis[] = $xBit;
	}
	$testScript = implode(' ', $implodeThis);
}
else {
	#echo "Invalid command: no command specified\n";
	#echo $USAGE;
	fwrite(STDERR, "Invalid command: no command specified\n");
	fwrite(STDERR, $USAGE);
	exit($E_BADARGS);
}

//since the only actual options at this time are to get help, not much to check.
if(count($options)) {
	fwrite(STDOUT, $USAGE);
	exit($E_OK);
}


//print_r($_SERVER['argv']);
//echo "ACTUAL SCRIPT: ". $actualScript ."\n\n";
//echo "GOING TO RUN:: ". $testScript ."\n\n";
//
//
//
//exit;
////NOTE::: checking if the file exists doesn't work when it's relative, like 
//if(!file_exists($actualScript)) {
//	fwrite(STDERR, "FATAL: command or script (". $actualScript .") does not exist\n". $USAGE);
//}
//else {
//	echo "file exists!\n\n";
//}

//exit;

$p->run($testScript);
$p->poll();

$sleepTime = (0.01 * 1000000);
$maxLoops = 30000;


$loops = 0;

while ($loops < $maxLoops && $p->isActive()) {
	$p->poll();

	fwrite(STDOUT, $p->getError());
	fwrite(STDERR, $p->listen());

	usleep($sleepTime);

	$loops++;
}
$p->poll();

//echo "FINAL STATUS: ". print_r($p->getStatus(),true) ."\n";
//echo "FINAL OUTPUT: ". $p->output ."\n";
//echo "FINAL ERROR: ". $p->error ."\n";

//print_r($p);

if($loops >= $maxLoops) {
	echo "TERMINATING TEST PROCESS (". $loops ." > ". $maxLoops .")... ";
	$res = $p->terminate();
	echo "Result=(". $res .")\n";
}

echo "FINAL REPORT:::: ";
echo $p->getFinalReport() ."\n\n";