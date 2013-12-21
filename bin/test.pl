#!/usr/bin/perl

use overload q(<) => sub {};

print STDERR "ARG0: ". $ARGV[0] ."\n";
print STDERR "ARG1: ". $ARGV[1] ."\n";

$prefix = "";
if(length($ARGV[0])) {
	$prefix = "[". $ARGV[0] ."] -- ";
}

$maxLoops=10;
if(length($ARGV[1])) {
	$maxLoops = $ARGV[1];
}

my %h;
print STDERR __FILE__ .": Error #1: (testing)\n";


for (my $i=0; $i<$maxLoops; $i++) {
	$x = `date`;
	chomp($x);
	$printThis = $x ." - #". $i ."\n";
	
	if($i == 3 || $i == 10 || $i == 83) {
		print STDERR $prefix ."(ERROR) ". $printThis;
	}
	print STDOUT $prefix . $printThis;
	sleep(1);
}

$date = `date`;
chomp($date);
print STDOUT $prefix . $date ." -- ". __FILE__ .": OUTPUT: '<--Testing data cleansing...\n";

$date = `date`;
chomp($date);
print STDERR $prefix . $date ." -- ". __FILE__ .": Error #2: Script failed (testing)\n";
exit 5;
