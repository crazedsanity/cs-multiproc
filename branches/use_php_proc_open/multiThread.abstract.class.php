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

require_once(dirname(__FILE__) .'/../cs-content/cs_fileSystem.class.php');
require_once(dirname(__FILE__) .'/../cs-content/cs_globalFunctions.class.php');
require_once(dirname(__FILE__) .'/../cs-versionparse/cs_version.abstract.class.php');

abstract class multiThreadAbstract extends cs_versionAbstract {
	
	/** PID of the parent process. */
	private $parentPid;
	
	/** PID of the *current* process (this might be the parent or a child) */
	private $myPid;
	
	/** Numerically-indexed array of {procNum}=>{childPID}, starting at 0. */
	protected $childArr = array();
	
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
	
	/** Array of slots, numerically index up to ($maxChildren - 1), holding PID of it's previous child, or NULL if unused. */
	private $availableSlots=array();
	
	/** The default queue name (the first queue specified to "set_max_children()") */
	private $defaultQueue=NULL;
	
	/** Links PID's to queue names. */
	private $pid2queue=array();
	
	/** Holds value to use for registering/unregistering the tick function. */
	private $tickFunction;
	
	/** Absolute path to a *.lock file so the script doesn't trip over itself */
	private $lockFile;
	
	/* ************************************************************************
	 * 
	 * ABSTRACT METHODS: these methods MUST be defined in extending classes.
	 * 
	 ********************************************************************** */
	//Here's the list of methods that need to be declared in the classes that extend this one.
	
	/**
	 * Should handle the given child number within the given queue's death: any 
	 * cleanup that needs to be done, or processing of output files, etc. should 
	 * be done within this method.
	 */
	abstract protected function dead_child_handler($childNum, $qName, $exitStatus);
	
	
	
	
	
	/* ************************************************************************
	 * 
	 * Methods that may be called from the extending classes.
	 * 
	 *********************************************************************** */
	
	//=========================================================================
	/**
	 * The constructor.  NOTE: this *MUST* be extended, as it is the ONLY 
	 * way to set $this->isInitialized
	 */
	public function __construct($rootPath=NULL, $lockfileName=NULL) {
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
		if(is_null($lockfileName)) {
			$lockfileName = __CLASS__;
		}
		$this->lockfile = $lockfileName .'.lock';
		$this->create_lockfile($this->lockfile);
		
		
		pcntl_signal(SIGTERM, array($this, "kill_children"));
		pcntl_signal(SIGUSR1, array($this, "kill_children"));
		pcntl_signal(SIGHUP, array($this, "kill_children"));
		pcntl_signal(SIGINT, array($this, "kill_children"));
		
		
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
		
		declare(ticks=10);
		$this->tickFunction = array($this, 'babysitter');
		register_tick_function($this->tickFunction);
	}//end __construct()
	//=========================================================================
	
	
	
	//=========================================================================
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
				//get output of all children...
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
	 */
	protected function daemonize() {
		$this->message_handler(__METHOD__, "Cannot daemonize, not implemented yet", 'ERROR');
	}//end daemonize()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Checks if the lockfile still exists: if that disappears, the parent 
	 * process will kill all of it's children and exit.
	 */
	protected function check_lockfile() {
		$this->fsObj->cd("/");
		$output = $this->fsObj->ls($this->lockfile);
		
		$retval = FALSE;
		if(is_array($output[$this->lockfile])) {
			$retval = $output[$this->lockfile]['modified'];
		}
		return($retval);
	}//end check_lockfile()
	//=========================================================================
	
	
	
	//=========================================================================
	public function kill_children() {
		throw new exception(__METHOD__ .": FIX ME");
		
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
		$retval = $messagePrefix . ": [". $method ."] pid=(". posix_getpid() .")";
		
		return($retval);
	}//end create_message()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Creates a lockfile to avoid other processes from tripping over this one.
	 */
	private function create_lockfile($name, $minutesToWait=NULL) {
		$lockfileExists = $this->check_lockfile();
		$goodToGo = FALSE;
		if($lockfileExists) {
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
					$lockfileTime = $this->check_lockfile();
					if(is_numeric($lockfileTime)) {
						$this->message_handler(__METHOD__, " lockfile still present (created at ". $lockfileTime ."), slept for (". $secondsWaited  .")", 'DEBUG');
						sleep($secondsBetweenChecks);
					}
					else {
						$this->message_handler(__METHOD__, "file disappeared at (". time() .")", 'NOTICE');
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
	private function get_child_status($childNum) {
		if(is_numeric($childNum) && $childNum >= 0) {
			$status = array();
			if(isset($this->children[$childNum])) {
				$status = proc_get_status($this->children[$childNum]);
			}
		}
		else {
			throw new exception(__METHOD__ .": invalid childNum (". $childNum .")");
		}
	}//end get_child_status()
	//=========================================================================
	
	
	
	//=========================================================================
	public function run_script() {
		throw new exception(__METHOD__ .": fix me");
	}//end run_script()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Remove any dead children.
	 */
	public function clean_children() {
		throw new exception(__METHOD__ .": fix me");
	}//end clean_children()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Handler for child death.  NOTE: need to be able to report to the extending 
	 * class which child actually died.
	 */
	public function child_death() {
		throw new exception(__METHOD__ .": fix me");
	}//end child_death()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * A way to set the private "maxChildren" property.  NOTE: when creating 
	 * multiple queues, this method must be called multiple times, one for 
	 * each queue.  
	 */
	protected function set_max_children($maxChildren, $queue=0) {
		throw new exception(__METHOD__ .": fix me");
	}//end set_max_children()
	//=========================================================================
	
	
	
	//=========================================================================
	protected function get_property($name) {
		return($this->$name);
	}//end get_property()
	//=========================================================================
	
	
	
	//=========================================================================
	protected function wait_for_children($queue=NULL) {
		if(is_null($queue)) {
			$queue = $this->defaultQueue;
		}
		while(count($this->childArr[$queue]) != 0) {
			$this->clean_children();
			usleep(500);
		}
	}//end wait_for_children()
	//=========================================================================
	
	
	
	//=========================================================================
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
	final public function get_myPid() {
		return($this->myPid);
	}//end get_myPid()
	//=========================================================================
	
	
	
	//=========================================================================
	final protected function get_num_children($queue=null) {
		throw new exception(__METHOD__ .": fix me");
	}//end get_num_children()
	//=========================================================================
	
}

?>
