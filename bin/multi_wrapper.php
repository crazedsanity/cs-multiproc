<?php

//
$E_BADARGS=85;
$USAGE = 'USAGE: '. basename($_SERVER['argv'][0]) .' (number of processes) (/usr/bin/perl) (./test.pl)' ."\n";
if($_SERVER['argc'] >= 2) {
	$numChildren = $_SERVER['argv'][1];
	
	$perl = '/usr/bin/perl';
	if(isset($_SERVER['argv'][2])) {
		$perl = $_SERVER['argv'][2];
	}
	
	$testScript = './test.pl';
	if(isset($_SERVER['argv'][3])) {
		$testScript = $_SERVER['argv'][3];
	}
	
	$script = $perl .' '. $testScript;
	#$bits = array();
	#unset($bits[0], $bits[1]);
	#$script = implode(' ', $bits);
}
else {
	echo $USAGE;
	exit($E_BADARGS);
}
print_r($_SERVER['argv']);
#echo $script ."\n\n";


echo "Spawning ". $numChildren ." processes.  Base script: ". $script ."\n";

require_once(dirname(__FILE__) .'/../cs_SingleProcess.class.php');
require_once(dirname(__FILE__) .'/../cs_MultiProcess.class.php');

$commands = array();
for($x=0; $x < $numChildren; $x++) {
	$commands[$x] = $script . ' "CHILD #'. $x .'" | tee _output-'. $x .'.log';
}

print_r($commands);


$mp = new cs_MultiProcess($commands);
print_r($mp);
echo "--------------------------------------------\n";
$mp->run(true);
echo "--------------------------------------------\n";

print_r($mp);
//$p = new cs_SingleProcess($script);
//
//$maxLoops = 300;
//$loops = 0;
//
//while($loops < $maxLoops && $p->isActive()) {
//	
//	echo " ---------------- \n";
//	
//	$status = $p->getStatus();
//	$error = $p->getError();
//	$output = $p->listen();
//	
//	echo "  [". $loops ."] STATUS::: ". print_r($status, true) ."\n";
//	echo "  [". $loops ."] ERROR:::: ". $error ."\n";
//	echo "  [". $loops ."] OUTPUT::: ". $output ."\n";
//	#echo "SITREP::: status=(". $status ."), error=(". $error ."), OUTPUT::: ". $output ."\n\n";
//	sleep(1);
//	
//	$loops++;
//} 
//
//
//echo "FINAL STATUS: ". print_r($p->getStatus(),true) ."\n";
//echo "FINAL OUTPUT: ". $p->output ."\n";
//echo "FINAL OUTPUT (listen): ". $p->listen() ."\n";
//echo "FINAL ERROR: ". $p->error ."\n";
//echo "FINAL ERROR (getError): ". $p->getError() ."\n";
//
//print_r($p);
//
//if($loops >= $maxLoops) {
//	echo "TERMINATING TEST PROCESS... ";
//	$res = proc_terminate($p->process);
//	echo "Result=(". $res .")\n";
//}