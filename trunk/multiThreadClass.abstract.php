<?php
/*
 * Created on Aug 9, 2007
 * 
 * SVN Signature::::::: $Id$
 * Last Committed Date: $Date$
 * Last Committed Path: $HeadURL$
 * Current Revision:::: $Revision$
 * 
 */

require_once(dirname(__FILE__) .'/../cs-content/cs_fileSystemClass.php');
require_once(dirname(__FILE__) .'/../cs-content/cs_globalFunctions.php');

abstract class multiThread {
	
	/** PID of the parent process. */
	private $parentPid;
	
	/** PID of the *current* process (this might be the parent or a child) */
	private $myPid;
	
	/** Numerically-indexed array of {procNum}=>{childPID}, starting at 0. */
	protected $childArr = array();
	
	/** Name of the parent's lockfile (like "{myProgName}.lock") */
	private $parentLockfile=NULL;
	
	#NOTE: child locks haven't been implemented yet.
	#/** Numerically-indexed array of {procNum}=>{lockfileName}. */
	#private $childLocks=array();
	
	/** CHILDREN: shows which process # this one is (first spawned child=0).  For the parent, this MUST be null. */
	private $childNum=NULL;
	
	/** Whether it's a daemon or not (a daemon will fork itself from the originating process). */
	private $isDaemon=NULL;
	
	/** Determines if this object has been properly initialized or not. */
	private $isInitialized=FALSE;
	
	/** Instance of cs_fileSystemClass{}, for lockfiles. */
	private $fsObj;
	
	/** Instance of cs_globalFunctions{}, for various functions/methods within. */
	private $gfObj;
	
	/** The maximum number of children that can be spawned. */
	private $maxChildren=NULL;
	
	/** Array of slots, numerically index up to ($maxChildren - 1), holding PID of it's previous child, or NULL if unused. */
	private $availableSlots=array();
	
	
	
	/* ************************************************************************
	 * 
	 * ABSTRACT METHODS: these methods MUST be defined in extending classes.
	 * 
	 ********************************************************************** */
	//Here's the list of methods that need to be declared in the classes that extend this one.
	#abstract protected function get_child_pids();
	
	
	
	
	
	
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
	protected function __construct($rootPath=NULL, $lockfileName=NULL) {
		
		//check that some required functions are available.
		$requiredFuncs = array('posix_getpid', 'posix_kill', 'pcntl_fork', 'pcntl_wait', 'pcntl_waitpid', 'pcntl_signal');
		foreach($requiredFuncs as $funcName) {
			if(!function_exists($funcName)) {
				throw new exception(__METHOD__ .": required function, ". $funcName .", does not exist");
			}
		}
		
		//set a PID.
		$this->myPid = posix_getpid();
		$this->parentPid = $this->myPid;
		
		//create the required objects.
		if(is_null($rootPath) || !strlen($rootPath)) {
			$rootPath = dirname(__FILE__) .'/../..';
		}
		$this->fsObj = new cs_fileSystemClass($rootPath);
		
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
		pcntl_signal(SIGCHLD, array($this, "child_death"));
		
		
		//everything that's required to be setup is: say it's initialized.
		$this->isInitialized=TRUE;
		
		/* "ticks" are the number of low-level operations performed between calls
		 * to the function defined by "register_tick_function()"; this means that 
		 * the defined function MUST BE FAST (hundreds or even thousands of ticks 
		 * can occur between each function call) if it's 1, otherwise set this to 
		 * something like 00 or even 1000.
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
		register_tick_function(array($this, 'sanity_check'), $this->myPid);
	}//end __construct()
	//=========================================================================
	
	
	
	//=========================================================================
	public function sanity_check() {
		if($this->isInitialized) {
			//check that the lockfile exists.
			if(!$this->check_lockfile()) {
				//no lockfile?!?! DIE!!!
				$this->message_handler(__METHOD__, "No lockfile: KILLING CHILDREN");
				$this->kill_children();
				
				$this->message_handler(__METHOD__, "ABNORMAL DEATH (lockfile disappeared)");
				exit(1);
			}
		}
		else {
			$this->message_handler(__METHOD__, "Uninitialized", TRUE);
		}
	}//end sanity_check()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Turn the process into a daemon.
	 */
	protected function daemonize() {
		$this->message_handler(__METHOD__, "Cannot daemonize, not implemented yet", TRUE);
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
	/**
	 * TRUE if this is the parent process, false otherwise.
	 */
	protected function is_parent() {
		$retval = FALSE;
		if($this->myPid == $this->parentPid) {
			$retval = TRUE;
		}
		return($retval);
	}//end is_parent()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Uses is_parent() to determine if this is a child or not.
	 */
	protected function is_child() {
		
		$retval = FALSE;
		if($this->myPid != $this->parentPid) {
			$retval = TRUE;
		}
		return($retval);
	}//end is_child()
	//=========================================================================
	
	
	
	//=========================================================================
	public function kill_children() {
		if($this->is_parent()) {
			if(is_array($this->childArr) && count($this->childArr)) {
				$killingSpree = 0;
				foreach($this->childArr as $num => $childPid) {
					$this->message_handler(__METHOD__, "parent process: killing child #$num ($childPid)");
					posix_kill($childPid, SIGTERM);
					$this->child_is_dead($childPid, TRUE);
					$killingSpree++;
				}
				$this->message_handler(__METHOD__, "parent process: done killing children, killed (". $killingSpree .") of them");
			}
		}
		else {
			#$this->message_handler(__METHOD__, "Child encountered fatal message, dying");
			exit(0);
		}
		
		
	}//end kill_children()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Handles formatting messages... presently, they're just printed to STDOUT.
	 */
	protected function message_handler($method, $message, $isException=FALSE) {
		if($isException) {
			//create the final message without a time, for displaying in the exception
			$finalMessage = $this->create_message($method, $message, FALSE);
			
			//show a message WITH a time, so we know when it happened in relation to other things
			$this->gfObj->debug_print($this->create_message($method, $message, TRUE));
			
			//finish up!
			$this->kill_children();
			throw new exception(__METHOD__ .": EXCEPTION FROM MESSAGE::: \n\t". $finalMessage);
		}
		else {
			$this->gfObj->debug_print($this->create_message($method, $message));
		}
	}//end message_handler()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Create the message string for message_handler(), thus avoiding any 
	 * recursive calling of said method.
	 */
	private function create_message($method, $message, $addTimestamp=TRUE) {
		
		$retval = "";
		if($addTimestamp) {
			$retval .= sprintf('%.4f', microtime(TRUE)) ." ";
		}
		
		if($this->is_child()) {
			$retval .= "---- #". $this->childNum ." [". $method ."] pid=(". $this->myPid .") ";
		}
		else {
			$retval .= "PARENT  [". $method ."] pid=(". posix_getpid() .") ";
		}
		
		$retval .= $message;
		return($retval);
	}//end create_message()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Creates a lockfile to avoid other processes from tripping over this one.
	 */
	protected function create_lockfile($name, $minutesToWait=NULL) {
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
						$this->message_handler(__METHOD__, " lockfile still present (created at ". $lockfileTime ."), slept for (". $secondsWaited  .")");
						sleep($secondsBetweenChecks);
					}
					else {
						$this->message_handler(__METHOD__, "file disappeared at (". time() .")");
						$goodToGo = TRUE;
						break;
					}
				}
				
				if($this->check_lockfile()) {
					$this->message_handler(__METHOD__, "FATAL: lockfile still present, and we have already waited ". $minutesToWait ." minute(s)", TRUE);
				}
				elseif(!$goodToGo && $this->check_lockfile() === FALSE) {
					//last moment check...
					$this->message_handler(__METHOD__, ": lockfile disappeared at the last moment... continuing on");
					$goodToGo = TRUE;
				}
				
			}
			else {
				$this->message_handler(__METHOD__, "FATAL: lockfile exists and no waiting period was defined", TRUE);
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
	/**
	 * Forks the current process (current process copied into a new one; see 
	 * http://php.net/pcntl_fork for more info).
	 * 
	 * TODO: give a $childNum=NULL argument; if it's numeric, then spawn a new one if that child died, or do nothing (?).
	 */
	private function spawn_child($childNum) {
		
		if($this->is_child()) {
			$this->message_handler(__METHOD__, "Child attempted to spawn more children!!!");
		}
		elseif(is_null($childNum) || !is_numeric($childNum)) {
			$this->message_handler(__METHOD__, "Cannot create child without a valid childNum (". $childNum .")");
		}
		
		//NOTE: *EACH* process should have this set.
		$pid = pcntl_fork();
		
		if($pid == -1) {
			$this->message_handler(__METHOD__, "Unable to fork", TRUE);
		}
		else {
			if($pid) {
				//PARENT PROCESS!!!
				$this->message_handler(__METHOD__, "Parent pid=(". $this->myPid .") spawned child with PID=". $pid);
				$this->childArr[$childNum] = $pid;
			}
			else {
				//CHILD PROCESS!!!
				$this->childArr = NULL;
				$this->myPid = $pid;
				$this->childNum = $childNum;
				$this->message_handler(__METHOD__, "should be child #". $childNum);
			}
		}
		
	}//end spawn_child()
	//=========================================================================
	
	
	
	//=========================================================================
	private function child_is_dead($pid, $wait=FALSE) {
		$retval = FALSE;
		
		//check if it's exitted or not ("WNOHANG" means this won't stop our currently running script)
		$actualStatus = NULL;
		if($wait) {
			$checkIt = pcntl_waitpid($pid, $actualStatus);
		}
		else {
			$checkIt = pcntl_waitpid($pid, $actualStatus, WNOHANG OR WUNTRACED);
		}
		
		#$this->message_handler(__METHOD__, "RESULT: (". $checkIt .")");
		if($checkIt == $pid) {
			$retval = TRUE;
			$this->message_handler(__METHOD__, "Child appears dead (". $checkIt ."), status=(". $actualStatus .")...?");
		}
		elseif($checkIt != $pid && $checkIt > 0) {
			$this->message_handler(__METHOD__, "returned value doesn't equal pid (". $checkIt ." != ". $pid ."), status=(". $actualStatus .")", TRUE);
		}
		
		return($retval);
	}//end child_is_dead()
	//=========================================================================
	
	
	
	//=========================================================================
	public function clean_children() {
		$retval = 0;
		if(is_array($this->childArr) && $this->is_parent()) {
			foreach($this->childArr as $childNum=>$pid) {
				//check if the kid is still breathing.
				if($this->child_is_dead($pid)) {
					$this->message_handler(__METHOD__, "Found child #". $childNum ." with pid (". $pid .") dead... CHECK IT!!!!");
					
					unset($this->childArr[$childNum]);
					$this->availableSlots[$childNum] = $pid;
					
					//TODO: to keep spawning with only a certain range of numbers, i.e. 0-4, keep an array of freed slots here.
				}
				else {
					$retval++;
				}
			}
		}
		else {
			$this->message_handler(__METHOD__, "children can't clean children", TRUE);
		}
		return($retval);
	}//end clean_children()
	//=========================================================================
	
	
	
	//=========================================================================
	public function child_death() {
		if($this->is_parent()) {
			$this->message_handler(__METHOD__, "Saw child die... calling the cleaning process...");
			$this->clean_children();
		}
		else {
			$this->message_handler(__METHOD__, "Child lived to see itself die... ????");
			exit(1);
		}
	}//end child_death()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * A way to set the private "maxChildren" property.
	 */
	protected function set_max_children($number) {
		if($this->is_parent()) {
			$this->maxChildren=$number;
			
			//dirty way of remembering which slots are free... but it's fast.
			for($i=0; $i<$number; $i++) {
				$this->availableSlots[$i] = "uninitialized";
			}
		}
		else {
			$this->message_handler(__METHOD__, "FATAL: child trying to change maxChildren to (". $number .")", TRUE);
		}
	}//end set_max_children()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Spawn another child, up to $this->maxChildren; if there's already that 
	 * many children, it will continuously scan them until one slot is freed 
	 * before doing so.
	 */
	protected function spawn() {
		$livingChildren = $this->clean_children();
		if($livingChildren > $this->maxChildren) {
			$this->message_handler(__METHOD__, "Too many children spawned (". $livingChildren ."/". $this->maxChildren .")", TRUE);
		}
		elseif(!is_numeric($this->maxChildren) || $this->maxChildren < 1) {
			$this->message_handler(__METHOD__, "maxChildren not set, can't spawn", TRUE);
		}
		elseif(!is_array($this->availableSlots) || count($this->availableSlots) > $this->maxChildren) {
			$this->message_handler(__METHOD__, "Invalid availableSlots... something terrible happened", TRUE);
		}
		
		$numLoops = 0;
		$totalLoops = 0;
		if($livingChildren >= $this->maxChildren) {
			$this->message_handler(__METHOD__, "Too many children");
			while($livingChildren >= $this->maxChildren) {
				//wait for a full minute before saying we're still waiting.
				//TODO: add something like a per-child timeout, just in case.
				if($numLoops >= 60) {
					$this->message_handler(__METHOD__, "Waiting to spawn new child, time slept=(". $totalLoops .")");
					$numLoops = 0;
				}
				sleep(1);
				$numLoops++;
				$totalLoops++;
				$livingChildren = $this->clean_children();
			}
		}
		
		//made it this far... spawn a new child!
		$slotNum = array_shift(array_keys($this->availableSlots));
		$oldProc = $this->availableSlots[$slotNum];
		unset($this->availableSlots[$slotNum]);
		$this->message_handler(__METHOD__, "Pulled slot #". $slotNum .", previously used by (". $oldProc .")");
		$this->spawn_child($slotNum);
		
	}//end spawn()
	//=========================================================================
	
	
	
}

?>