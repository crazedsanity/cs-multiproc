<?php

// Initial code: http://www.php-code.net/2010/05/running-multiple-processes-in-php/
// This code adapted from https://gist.github.com/scribu/4736329

class cs_SingleProcess {

	public $process; // process reference
	public $pipes; // stdio
	public $buffer; // output buffer
	public $output;
	public $error;
	public $timeout;
	public $start_time;
	public $command;

	public function __construct($command) {
		$this->process = 0;
		$this->buffer = "";
		$this->pipes = (array) NULL;
		$this->output = "";
		$this->error = "";

		$this->start_time = time();
		$this->timeout = 0;
		
		$descriptor = array(
			0 => array("pipe", "r"),
			1 => array("pipe", "w"),
			2 => array("pipe", "w"),
		);
		$this->command = $command;
		$this->process = proc_open($command, $descriptor, $this->pipes);
		
		//set stuff so it's non-blocking.
		stream_set_blocking($this->pipes[1], 0);
		stream_set_blocking($this->pipes[2], 0);
		
//		while($this->isActive()) {
//			$this->listen();
//			$this->getError();
//		}
	}//end __construct()
	
	
	
	//See if the command is still active
	function isActive() {
		$info = $this->getStatus();
		
		return $info['running'];
//		$this->buffer .= $this->listen();
//		$f = stream_get_meta_data($this->pipes[1]);
//		return !$f["eof"];
	}//end isActive()
	
	
	
	//Close the process
	function close() {
		$r = proc_close($this->process);
		$this->process = NULL;
		return $r;
	}//end close()
	
	
	
	//Send a message to the command running
	function tell($thought) {
		fwrite($this->pipes[0], $thought);
	}//end tell()
	
	
	
	//Get the command output produced so far
	function listen() {
		$buffer = $this->buffer;
		$this->buffer = "";
		while ($r = fgets($this->pipes[1], 1024)) {
			$buffer .= $r;
			$this->output.=$r;
		}
		echo $r;
		return $buffer;
	}//end listen()
	
	
	
	//Get the status of the current runing process
	function getStatus() {
		return proc_get_status($this->process);
	}//end getStatus()
	
	
	
	//See if the command is taking too long to run (more than $this->timeout seconds)
	function isBusy() {
		$retval = ( $this->start_time > 0 ) && ( $this->start_time + $this->timeout < time() );
		return $retval;
	}//end isBusy()
	
	
	
	//What command wrote to STDERR
	function getError() {
		$buffer = "";
		while ($r = fgets($this->pipes[2], 1024)) {
			$buffer .= $r;
			$this->error .= $r;
		}
		return $buffer;
	}//end getError()
	
}
