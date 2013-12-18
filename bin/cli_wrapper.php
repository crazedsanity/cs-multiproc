<?php

//
$E_BADARGS=85;
$USAGE = 'USAGE: '. basename($_SERVER['argv'][0]) .' "" /usr/bin/perl ./test.pl arg1 arg2' ."\n";
if($_SERVER['argc'] >= 3) {
	$bits = $_SERVER['argv'];
	unset($bits[0], $bits[1]);
	$testScript = implode(' ', $bits);
}
else {
	echo $USAGE;
	exit($E_BADARGS);
}
print_r($_SERVER['argv']);
echo $testScript ."\n\n";

require_once(dirname(__FILE__) .'/../cs_SingleProcess.class.php');
$p = new cs_SingleProcess($testScript);

$maxLoops = 300;
$loops = 0;

while($loops < $maxLoops && $p->isActive()) {
	
	echo " ---------------- \n";
	
	$status = $p->getStatus();
	$error = $p->getError();
	$output = $p->listen();
	
	echo "  [". $loops ."] STATUS::: ". print_r($status, true) ."\n";
	echo "  [". $loops ."] ERROR:::: ". $error ."\n";
	echo "  [". $loops ."] OUTPUT::: ". $output ."\n";
	#echo "SITREP::: status=(". $status ."), error=(". $error ."), OUTPUT::: ". $output ."\n\n";
	sleep(1);
	
	$loops++;
} 


echo "FINAL STATUS: ". print_r($p->getStatus(),true) ."\n";
echo "FINAL OUTPUT: ". $p->output ."\n";
echo "FINAL OUTPUT (listen): ". $p->listen() ."\n";
echo "FINAL ERROR: ". $p->error ."\n";
echo "FINAL ERROR (getError): ". $p->getError() ."\n";

print_r($p);

if($loops >= $maxLoops) {
	echo "TERMINATING TEST PROCESS... ";
	$res = proc_terminate($p->process);
	echo "Result=(". $res .")\n";
}