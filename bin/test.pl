#!/usr/bin/perl

use overload q(<) => sub {};
my %h;
print STDERR __FILE__ .": Error #1: (testing)\n";
#for (my $i=0; $i<50000; $i++) {
#	$h{$i} = bless [ ] => 'main';
#	print STDOUT '.' if $i % 1000 == 0;
#}
#sleep(2);

$maxLoops=10;

for (my $i=0; $i<$maxLoops; $i++) {
	$x = `date`;
	chomp($x);
	$printThis = $x ." - #". $i ."\n";
	
	if($i == 3 || $i == 10 || $i == 83) {
		print STDERR "(ERROR) ". $printThis;
	}
	else {
		print STDOUT $printThis;
	}
	sleep(1);
}

$date = `date`;
chomp($date);
print STDOUT $date ." -- ". __FILE__ .": OUTPUT: '<--Testing data cleansing...\n";

$date = `date`;
chomp($date);
print STDERR $date ." -- ". __FILE__ .": Error #2: Script failed (testing)\n";
exit 5;
