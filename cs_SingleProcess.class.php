<?php

// Initial code: http://www.php-code.net/2010/05/running-multiple-processes-in-php/
// This code adapted from https://gist.github.com/scribu/4736329

class cs_SingleProcess {

	protected $_process; // process reference
	protected $_pipes; // stdio
	protected $_buffer; // output buffer
	protected $_output;
	protected $_error;
	protected $_timeout;
	protected $_startTime;
	protected $_endTime;
	protected $_command;
	protected $_lastStatus;
	protected $_lastOutput;
	protected $_lastError;
	protected $_lastType;	// last type of output found (STDERR/STDOUT)
	protected $_allOutput;	//all output, xml-style, wrapped in type (etc)
	protected $_exitCode=null;
	protected $_version;
	
	const STDIN=0;
	const STDOUT=1;
	const STDERR=2;
	
	protected $typeMap = array(
		self::STDIN		=> 'stdin',
		self::STDOUT	=> 'stdout',
		self::STDERR	=> 'stderr',
	);
	
	//-------------------------------------------------------------------------
	public function __construct() {
		$this->_process = 0;
		$this->_buffer = "";
		$this->_pipes = (array) NULL;
		$this->_output = "";
		$this->_error = "";

		$this->_timeout = 0;
		
		$this->_version = new cs_version(dirname(__FILE__) .'/VERSION');
		
	}//end __construct()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	public function run($command) {
		$this->_command = $command;
		$descriptor = array(
			self::STDIN => array("pipe", "r"),
			self::STDOUT => array("pipe", "w"),
			self::STDERR => array("pipe", "w"),
		);
		
		
		$this->_startTime = microtime(true);
		$this->_process = proc_open($this->command, $descriptor, $this->_pipes);
		
		//set stuff so it's non-blocking.
		stream_set_blocking($this->_pipes[1], 0);
		stream_set_blocking($this->_pipes[2], 0);
	}//end run()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	public function __get($name) {
		$retval = null;
		switch($name) {
			case 'output':
				$retval = $this->_output;
				break;
			case 'error':
				$retval = $this->_error;
				break;
			case 'startTime':
			case 'start_time':
				$retval = $this->_startTime;
				break;
			case 'command':
				$retval = $this->_command;
				break;
			case 'pid':
				$data = $this->getStatus();
				$retval = $data['pid'];
				break;
			case 'project':
				$retval = $this->_version->get_project();
				break;
			case 'version':
				$retval = $this->_version->get_version();
				break;
			default:
				throw new exception(__METHOD__ .': unknown property "'. $name .'"');
		}
		
		return $retval;
	}//end __get()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	public function __set($name, $value) {
		$retval = null;
		
		switch($name) {
			case 'timeout':
				$this->_timeout = $value;
				break;
			default:
				throw new exception(__METHOD__ .': cannot set property "'. $name .'"');
		}
		
		return $retval;
	}//end __set()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	//See if the command is still active
	public function isActive() {
		$info = $this->getStatus();
		return $info['running'];
	}//end isActive()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	//Close the process
	public function close() {
		$r = proc_close($this->_process);
		$this->_process = NULL;
		return $r;
	}//end close()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	public function terminate($signal=null) {
		$r = proc_terminate($this->_process, $signal);
		return $r;
	}//end terminate()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	//Send a message to the command running
	public function tell($thought) {
		fwrite($this->_pipes[0], $thought);
	}//end tell()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	public function poll() {
		while ($r = fgets($this->_pipes[2], 1024)) {
			$this->_appendAllOutput($r, self::STDERR);
			$this->_error .= $r;
			$this->_lastError .= $r;
		}
		while ($r = fgets($this->_pipes[1], 1024)) {
			$this->_appendAllOutput($r, self::STDOUT);
			$this->_output.=$r;
			$this->_lastOutput .= $r;
		}
	}//end poll()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	protected function _appendAllOutput($output=null, $type=null) {
		$output = rtrim($output);
		if(!is_null($output) && strlen($output)) {
			if(is_null($type)) {
				throw new exception(__METHOD__ .": invalid type, unable to append");
			}
			$tagName = $this->typeMap[$type];
			$this->_allOutput .= "\t<". $tagName .' time="'. microtime(true) .'">'. 
				htmlentities($output) . '</'. $tagName .">\n";
		}
		$this->_lastType = $type;
	}//end _appendAllOutput()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	//Get the command output produced so far
	public function listen() {
		$this->poll();
		$retval = $this->_lastOutput;
		$this->_lastOutput = "";
		return $retval;
	}//end listen()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	//Get the status of the current runing process
	public function getStatus() {
		$this->_lastStatus = proc_get_status($this->_process);	
		if($this->_lastStatus['running'] === FALSE && $this->_exitCode === NULL) {
			$this->_exitCode = $this->_lastStatus['exitcode'];
			$this->_endTime = microtime(true);
		}
		return $this->_lastStatus;
	}//end getStatus()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	//See if the command is taking too long to run (more than $this->timeout seconds)
	public function isBusy() {
		$retval = ( $this->_startTime > 0 ) && ( $this->_startTime + $this->_timeout < time() );
		return $retval;
	}//end isBusy()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	//What command wrote to STDERR
	public function getError() {
		$this->poll();
		$retval = $this->_lastError;
		$this->_lastError = "";
		return $retval;
	}//end getError()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	public function getFinalReport() {
		$this->getStatus();
		$report = '<processData>' ."\n\t";
		
		$myStatus = $this->_lastStatus;
		foreach($myStatus as $index=>$value) {
			if($index == 'exitcode') {
				$value = $this->_exitCode;
			}
			if(strlen($value)) {
				$report .= "\n\t<". $index .'>'. $value .'</'. $index .'>';
			}
			else {
				$report .= "\n\t<". $index ." />";
			}
		}
		$report .= "\n\t<start_time>". $this->_startTime ."</start_time>";
		$report .= "\n\t<end_time>". $this->_endTime ."</end_time>";
		$report .= "\n\t<total_time>". number_format(($this->_endTime - $this->_startTime), 2) ."</total_time>\n";
		
		if(strlen($this->_allOutput)) {
			$report .= "<all_output>\n". $this->_allOutput ."\n</all_output>";
		}
		else {
			$report .= "<all_output />";
		}
		$report .= "\n</processData>";
		
		return $report;
	}//end getFinalReport()
	//-------------------------------------------------------------------------
	
}
