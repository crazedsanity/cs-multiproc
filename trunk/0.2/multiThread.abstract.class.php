<?php
/*
 * Created on Aug 9, 2007
 * 
 * SVN Signature::::::: $Id$
 * Last Committed Date: $Date$
 * Last Committed Path: $HeadURL$
 * Current Revision:::: $Revision$
 * 
 * TODO: add proper headers for all methods
 * TODO: child lockfiles
 * TODO: check that the lockfile has the proper PID in it, die if not (another test for "sanity_check()").
 * TODO: allow for non-parent classes to extend this one (if the parent creates a new class, it should also have access to these features).
 */


abstract class multiThreadAbstract extends cs_versionAbstract {
	
	/** PID of the parent process. */
	private $parentPid;
	
	/** PID of the *current* process (this might be the parent or a child) */
	private $myPid;
	
	/** Numerically-indexed array with index being child # and the value (array) containing various info about it. */
	private $children = array();
	
	/** Whether it's a daemon or not (a daemon will fork itself from the originating process). */
	private $isDaemon=NULL;
	
	/** Determines if this object has been properly initialized or not. */
	private $isInitialized=FALSE;
	
	/** Instance of cs_fileSystemClass{}, for lockfiles. */
	private $fsObj;
	
	/** Instance of cs_globalFunctions{}, for various functions/methods within. */
	protected $gfObj;
	
	/** The maximum number of children that can be spawned. */
	private $maxChildren=NULL;
	
	/** Holds value to use for registering/unregistering the tick function. */
	private $tickFunction;
	
	/** Absolute path to a *.lock file so the script doesn't trip over itself */
	private $lockFile;
	
	/** Name used to generate name of lockfile & to prepend to child process output files. */
	private $processName;
	
	/** Number of seconds to wait between calls to checkin(). */
	private $checkinDelay=1;
	
	/** Timestamp indicating when the last time babysitter() called checkin() */
	private $lastCheckin;
	
	/* ************************************************************************
	 * 
	 * ABSTRACT METHODS: these methods MUST be defined in extending classes.
	 * 
	 ********************************************************************** */
	//Here's the list of methods that need to be declared in the classes that extend this one.
	
	//=========================================================================
	/**
	 * Should handle the given child number within the given queue's death: any 
	 * cleanup that needs to be done, or processing of output files, etc. should 
	 * be done within this method.
	 */
	abstract protected function dead_child_handler($childNum, $exitStatus, array $output);
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Called from the registered tick function every second (calling the method 
	 * set_checkin_delay() can increase this delay). 
	 */
	abstract protected function checkin();
	//=========================================================================
	
	
	
	
	//=========================================================================
	/**
	 * The constructor.  NOTE: this *MUST* be extended, as it is the ONLY 
	 * way to set $this->isInitialized
	 */
	public function __construct($rootPath=NULL, $processName=NULL, $waitForExisting=NULL) {
		//check that some required functions are available.
		$requiredFuncs = array('posix_getpid', 'posix_kill', 'pcntl_fork', 'pcntl_wait', 'pcntl_waitpid', 'pcntl_signal');
		foreach($requiredFuncs as $funcName) {
			if(!function_exists($funcName)) {
				throw new exception(__METHOD__ .": required function, ". $funcName .", does not exist");
			}
		}
		
		//set a PID.
		$this->myPid = posix_getpid();
		
		//create the required objects.
		if(is_null($rootPath) || !strlen($rootPath)) {
			$rootPath = dirname(__FILE__) .'/../..';
		}
		$this->fsObj = new cs_fileSystem($rootPath);
		
		$this->gfObj = new cs_globalFunctions;
		$this->gfObj->debugPrintOpt=1;
		$this->gfObj->debugRemoveHr=1;
		
		//create a lockfile to ensure we don't trip over ourselves.
		if(is_null($processName)) {
			$processName = __CLASS__;
		}
		$this->processName = $processName;
		$this->lockfile = $processName .'.lock';
		$this->create_lockfile($this->lockfile, $waitForExisting);
		
		
		pcntl_signal(SIGTERM, array($this, "signal_handler"));
		pcntl_signal(SIGUSR1, array($this, "signal_handler"));
		pcntl_signal(SIGHUP, array($this, "signal_handler"));
		pcntl_signal(SIGINT, array($this, "signal_handler"));
		
		//everything that's required to be setup is: say it's initialized.
		$this->isInitialized=TRUE;
		
		/* "ticks" are the number of low-level operations performed between calls
		 * to the function defined by "register_tick_function()"; this means that 
		 * the defined function MUST BE FAST (hundreds or even thousands of ticks 
		 * can occur between each function call) if it's 1, otherwise set this to 
		 * something like 100 or even 1000.
		 * 
		 * For the purposes of this class, each tick will essentially check that 
		 * lockfiles still exist, etc.
		 * 
		 * IMPORTANT NOTE:
		 * Changing the registered tick function--or unregistering it--will cause 
		 * your program to lose all the automatic safeties that this class is 
		 * built to provide, including automatic death if the lockfile is 
		 * removed.
		 */
		
		declare(ticks=1);
		$this->tickFunction = array($this, 'babysitter');
		register_tick_function($this->tickFunction);
	}//end __construct()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Method used to check the status of children (called every X ticks, as defined
	 * in the constructor via a "define()" call).
	 */
	public function babysitter() {
		if($this->isInitialized) {
			//check that the lockfile exists.
			if(!$this->check_lockfile()) {
				//no lockfile?!?! DIE!!!
				$this->message_handler(__METHOD__, "No lockfile: KILLING CHILDREN", 'DEBUG');
				$this->kill_children();
				$this->message_handler(__METHOD__, "ABNORMAL DEATH (lockfile disappeared)", 'FATAL');
			}
			elseif(!is_numeric($this->myPid)) {
				$this->message_handler(__METHOD__, "myPid is invalid, KILLING CHILDREN", 'DEBUG');
				$this->kill_children();
				$this->message_handler(__METHOD__, "ABNORMAL DEATH (myPid was invalid)", 'FATAL');
			}
			else {
				//handle calling the checkin() method in regular intervals
				$now = time();
				$sinceLast = $now - $this->lastCheckin;
				if($sinceLast >= $this->checkinDelay || !is_numeric($this->lastCheckin)) {
					$showThis = $sinceLast;
					if(!is_numeric($this->lastCheckin)) {
						$showThis = '--';
					}
					$this->lastCheckin = $now;
					$this->message_handler(__METHOD__, "calling checkin after delay of (". $showThis .") seconds");
					$this->checkin();
				}
			}
		}
		else {
			$this->message_handler(__METHOD__, "Uninitialized", 'ERROR');
		}
	}//end babysitter()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Turn the process into a daemon.
	 * 
	 * TODO: use pcntl_fork() here: the parent should die, while the child carries on.  :)
	 */
	protected function daemonize() {
		$this->message_handler(__METHOD__, "Cannot daemonize, not implemented yet", 'ERROR');
	}//end daemonize()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Checks if the lockfile exists: false if it is missing, or the first line 
	 * (which should be the PID) if it does.
	 */
	protected function check_lockfile() {
		$this->fsObj->cd("/");
		$output = $this->fsObj->ls($this->lockfile);
		
		$retval = false;
		if(is_array($output[$this->lockfile])) {
			$contents = $this->fsObj->read($this->lockfile, true);
			$retval = array(
				'pid'		=> trim($contents[0]),
				'modified'	=> $output[$this->lockfile]['modified']
			);
		}
		return($retval);
	}//end check_lockfile()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Kills all child processes (such as if the parent dies).
	 */
	public function kill_children() {
		$this->message_handler(__METHOD__, "called... ", 'DEBUG');
		
		foreach($this->children as $childNum=>$info) {
			$res = proc_terminate($info['resource']);
			
			
			$childInfo = $this->get_child_status($childNum);
			$this->message_handler(__METHOD__, "Signalled child #". $childNum ." to terminate (". $res ."), INFO::: ". 
					$this->gfObj->string_from_array($childInfo, 'text_list'));
			
			if($res === true) {
				#unset($this->children[$childNum]);
				$this->child_death($childNum);
				$this->message_handler(__METHOD__, " -------- removed child #". $childNum);
			}
		}
		
	}//end kill_children()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Handles formatting messages... presently, they're just printed to STDOUT.
	 */
	final protected function message_handler($method, $message, $type='NOTICE') {
		if(is_bool($type) && $type === TRUE) {
			$type = 'FATAL';
		}
		$type = strtoupper($type);
		switch($type) {
			case 'NOTICE':
			case NULL: {
				$type='NOTICE';
				$exitValue=NULL;
			}
			break;
			
			case 'ERROR': {
				$exitValue = 1;
			}
			break;
			
			case 'FATAL': {
				$exitValue = 2;
			}
			break;
			
			case 'DONE': {
				$exitValue = 0;
			}
			break;
			
			case 'DEBUG':
			default:
				$type = 'DEBUG';
				$exitValue = NULL;
		}
		
		//spit out some FATAL info if there's a bad/non-existent exitValue, or just spit out the info.
		if(!is_null($exitValue) && $exitValue != 0) {
			//create the final message for displaying in the exception
			$message = "FATAL: ". $message;
			$finalMessage = $this->create_message($method, $type, $message);
			
			//show a message with a time, so we know when it happened in relation to other things
			//TODO: put this into a special log for the main script instead of STDOUT.
			$this->gfObj->debug_print($this->create_message($method, $type, $message));
			
			//finish up!
			$this->kill_children();
			$this->gfObj->debug_print(__METHOD__ ." EXITING FROM MESSAGE::: \n\t". $finalMessage);
			exit($exitValue);
		}
		else {
			$this->gfObj->debug_print($this->create_message($method, $type, $message));
		}
	}//end message_handler()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Create the message string for message_handler(), thus avoiding any 
	 * recursive calling of said method.
	 */
	final private function create_message($method, $type, $message) {
		$x = explode('.', sprintf('%.4f', microtime(TRUE)));
		$messagePrefix = $this->gfObj->truncate_string(str_pad(date('Y-m-d H:i:s') .".". $x[1] ." {". $type ."} ", 40), 35, "", true);
		$retval = $messagePrefix . ": [". $method ."] pid=(". posix_getpid() ."):: ". $message;
		
		return($retval);
	}//end create_message()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Creates a lockfile to avoid other processes from tripping over this one.
	 */
	private function create_lockfile($name, $minutesToWait=NULL) {
		$this->message_handler(__METHOD__, "starting, lockfile=(". $name .")", 'DEBUG');
		$lockfileExists = $this->check_lockfile();
		$goodToGo = FALSE;
		if($lockfileExists) {
			//another process is running: wait for it.
			$secondsBetweenChecks = 5;
			$secondsToWait = (($minutesToWait * 60) - $secondsBetweenChecks);
			$maxLoops = 500;
			
			$currentLoop = 0;
			$startTime = time();
			$secondsWaited = 0;
			$this->message_handler(__METHOD__, "Lockfile (". $this->lockfile .") exists");
			if($secondsToWait > 0) {
				$this->message_handler(__METHOD__, "starting nap at ". $startTime);
				while($currentLoop < $maxLoops && $secondsWaited < $secondsToWait) {
					$secondsWaited = (time() - $startTime);
					$info = $this->check_lockfile();
					$lockfileTime = $info['modified'];
					$pid = $info['pid'];
					if(is_numeric($lockfileTime)) {
						$this->message_handler(__METHOD__, " lockfile still present (created at ". $lockfileTime .", pid=". $pid ."), slept for (". $secondsWaited  .")", 'DEBUG');
						sleep($secondsBetweenChecks);
					}
					else {
						$this->message_handler(__METHOD__, "file disappeared at (". time() .")... ". $this->gfObj->debug_print($info,0), 'NOTICE');
						$goodToGo = TRUE;
						break;
					}
				}
				
				if($this->check_lockfile()) {
					$this->message_handler(__METHOD__, "lockfile still present, and we have already waited ". $minutesToWait ." minute(s)", 'FATAL');
				}
				elseif(!$goodToGo && $this->check_lockfile() === FALSE) {
					//last moment check...
					$this->message_handler(__METHOD__, ": lockfile disappeared at the last moment... continuing on", 'DEBUG');
					$goodToGo = TRUE;
				}
				
			}
			else {
				$this->message_handler(__METHOD__, "lockfile exists and no waiting period was defined", 'FATAL');
			}
		}
		else {
			$goodToGo = TRUE;
		}
		
		
		$retval = FALSE;
		if($goodToGo) {
			//create the file & drop our PID in there.
			$this->message_handler(__METHOD__, "Creating lockfile (". $this->lockfile .").... ", 'DEBUG');
			if(!strlen($this->lockfile)) {
				$this->message_handler(__METHOD__, "Cannot create lockfile, invalid length (". $this->lockfile .")", 'FATAL');
			}
			$this->fsObj->create_file($this->lockfile, TRUE);
			$this->fsObj->openFile($this->lockfile);
			$this->fsObj->append_to_file($this->myPid);
			
			$lockfileTime = $this->check_lockfile();
			$this->timeStarted = $lockfileTime;
			$retval = TRUE;
		}
		
		return($retval);
	}//end create_lockfile()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Retrieve current status of the child.  If the child has died, the exitcode 
	 * is cached for later.
	 */
	private function get_child_status($childNum) {
		if(is_numeric($childNum) && $childNum >= 0) {
			$status = array();
			if(isset($this->children[$childNum]) && is_resource($this->children[$childNum]['resource'])) {
				$status = proc_get_status($this->children[$childNum]['resource']);
				
				//MUST cache the exitcode IMMEDIATELY if the "running" flag is false
				if(!$status['running'] && !isset($this->children[$childNum]['exitStatus'])) {
					$this->children[$childNum]['exitStatus'] = $status['exitcode'];
					$this->message_handler(__METHOD__, "capturing exitcode (". $status['exitcode'] .")", 'DEBUG');
				}
			}
		}
		else {
			throw new exception(__METHOD__ .": invalid childNum (". $childNum .")");
		}
		return($status);
	}//end get_child_status()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Start a script as another process.
	 */
	final public function run_script($command, $cwd=null) {
		$childNum = count($this->children);
		
		$this->message_handler(__METHOD__, "starting...");
		$myTimestamp = time();
		$logFilePrefix = $this->fsObj->root .'/'. $this->processName .'-child'. 
				$childNum .'-pid'. $this->myPid .'-'. $myTimestamp;
		
		$stdoutFile = $logFilePrefix .'-stdout.log';
		$stderrFile = $logFilePrefix .'-stderr.log';
		
		$descriptorSpec = array(
			0	=> array('pipe',	"r"),									//stdin (child reads data from parent through this)
			1	=> array('file',	$stdoutFile, "w"),	//stdout (child writes to this one)
			2	=> array('file',	$stderrFile, "w")		//STDERR (errors written here)
		);
		$this->childPipes[$childNum] = array();
		if(is_null($cwd)) {
			$cwd = $this->fsObj->root;
		}
		
		//spawn the child process... 
		$this->children[$childNum]['pipes'] = array();
		$this->children[$childNum]['resource'] = proc_open($command, $descriptorSpec, $this->children[$childNum]['pipes'], $cwd);
		
		$childStatus = proc_get_status($this->children[$childNum]['resource']);
		$this->children[$childNum]['pid'] = $childStatus['pid'];
		$this->children[$childNum]['command'] = $childStatus['command'];
		$this->children[$childNum]['files'] = array(
			'stdout'	=> $stdoutFile,
			'stderr'	=> $stderrFile
		);
		
		$this->message_handler(__METHOD__, "DONE, childNum=(". $childNum .")");
		
		return($childNum);
	}//end run_script()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Remove any dead children.
	 */
	public function clean_children() {
		$livingChildren = 0;
		if(is_array($this->children) && count($this->children)) {
			foreach($this->children as $childNum=>$data) {
				$childInfo = $this->get_child_status($childNum);
				if($childInfo['running'] == true) {
					$livingChildren++;
				}
				else {
					$this->message_handler(__METHOD__, "Child #". $childNum ." (pid=". $childInfo['pid'] .") not running");
					#unset($this->children[$childNum]);
					$this->child_death($childNum);
				}
			}
		}
		return($livingChildren);
	}//end clean_children()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Handler for child death.  NOTE: need to be able to report to the extending 
	 * class which child actually died.
	 */
	private function child_death($childNum) {
		$this->message_handler(__METHOD__, "running...");
		
		//pull information about it.
		$pidInfo = $this->get_child_status($childNum);
		
		//get output from files then delete the files.
		//TODO: should this use cs_fileSystem{}?
		$output = array();
		foreach($this->children[$childNum]['files'] as $index=>$logfile) {
			$output[$index] = file_get_contents($logfile);
			unlink($logfile);
		}
		
		//close the process.
		proc_close($this->children[$childNum]['resource']);
		
		//get the cached exitStatus...
		$cachedExitStatus = $this->children[$childNum]['exitStatus'];
		
		//bury the body.
		unset($this->children[$childNum]);
		
		//determine the exitCode...
		if($pidInfo['signaled'] == true) {
			$exitCode = $pidInfo['termsig'];
		}
		elseif($pidInfo['stopped'] == true) {
			$exitCode = $pidInfo['stopsig'];
		}
		else {
			//NOTE FROM PHP.NET::: Only first call of this function return real value, next calls return -1.
			$exitCode = $cachedExitStatus;
			$this->message_handler(__METHOD__, "Using exitcode (". $exitCode .")");
		}
		
		$this->dead_child_handler($childNum, $exitCode, $output);
	}//end child_death()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * A way to set the private "maxChildren" property. 
	 */
	protected function set_max_children($maxChildren) {
		//TODO: FIX ME
		throw new exception(__METHOD__ .": fix me");
		
		if(is_numeric($maxChildren) && $maxChildren > 0) {
			$this->maxChildren = $maxChildren;
		}
		else {
			throw new exception(__METHOD__ .": invalid setting (". $maxChildren ."), must be greater than 0");
		}
	}//end set_max_children()
	//=========================================================================
	
	
	
	//=========================================================================
	protected function get_property($name) {
		return($this->$name);
	}//end get_property()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Method that will wait for all children to finish before stopping (don't 
	 * call it unless you are SURE the script should just wait).
	 */
	protected function wait_for_children() {
		$this->message_handler(__METHOD__, "starting...");
		while(count($this->children) != 0) {
			$livingChildren = $this->clean_children();
			if($livingChildren > 0) {
				$this->message_handler(__METHOD__, "waiting for children (". $livingChildren .")");
				sleep(1);
			}
			else {
				$this->message_handler(__METHOD__, "all children died (". $livingChildren .")");
			}
		}
		$this->message_handler(__METHOD__, "done");
	}//end wait_for_children()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Destroy the lock file so it can be run again later.
	 */
	private function remove_lockfile() {
		$this->fsObj->cd("/");
		$lsData = $this->fsObj->ls();
		if(is_array($lsData[$this->lockfile])) {
			$removeResult = $this->fsObj->rm($this->lockfile);
			$this->message_handler(__METHOD__, "Successfully removed lockfile");
		}
		else {
			$this->message_handler(__METHOD__, "Could not find lockfile?", 'FATAL');
		}
	}//end remove_lockfile()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Indicates the script has completed EVERYTHING it needs to do: this will 
	 * wait for children to die before removing the lock file & exiting.
	 */
	protected function finished() {
		//wait for the children to finish-up.
		$this->wait_for_children();
		
		//stop doing stuff as ticks happen, so we don't "die abnormally" due
		//	to a missing lockfile.
		unregister_tick_function($this->tickFunction);
		
		//drop the lockfile, tell 'em what happened, and die.
		$this->remove_lockfile();
		
		//NOTE: the "exit(99)" is there to indicate something truly horrible happened, as message_handler() didn't exit after the DONE signal.
		$this->message_handler(__METHOD__, "All done!", 'DONE');
		exit(99);
	}//end finished()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Retrieve pid of THIS (parent) script.
	 */
	final public function get_myPid() {
		return($this->myPid);
	}//end get_myPid()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Retrieve number of active children.
	 */
	final protected function get_num_children($queue=null) {
		$livingChildren = 0;
		if(is_array($this->children)) {
			$livingChildren = count($this->children);
		}
		return($livingChildren);
	}//end get_num_children()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Method called in the event a signal is caught.
	 * 
	 * TODO: handle SIGHUP properly -- pass signal to children?  
	 */
	final public function signal_handler() {
		$this->message_handler(__METHOD__, "Caught signal...");
		$this->kill_children();
	}//end signal_handler()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Set the number of seconds to wait between calls to checkin()--this is done
	 * by the babysitter() method.
	 */
	final protected function set_checkin_delay($secondsToWait) {
		$myDelay = $secondsToWait;
		if(!is_numeric($secondsToWait) || $secondsToWait < 1) {
			$myDelay = 1;
		}
		$this->checkinDelay = $myDelay;
	}//end set_checkin_delay()
	//=========================================================================
}

?>
