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

require_once(dirname(__FILE__) .'/../cs-content/cs_fileSystemClass.php');
require_once(dirname(__FILE__) .'/../cs-content/cs_globalFunctions.php');
require_once(dirname(__FILE__) .'/ipcClass.abstract.php');

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
	protected $gfObj;
	
	/** The maximum number of children that can be spawned. */
	private $maxChildren=NULL;
	
	/** Array of slots, numerically index up to ($maxChildren - 1), holding PID of it's previous child, or NULL if unused. */
	private $availableSlots=array();
	
	/** The default queue name (the first queue specified to "set_max_children()") */
	private $defaultQueue=NULL;
	
	/** Links PID's to queue names. */
	private $pid2queue=array();
	
	/** Function (method) for ticks. */
	private $tickFunc;
	
	/** Directory to store files, such as filelocks and message queues. */
	private $rootPath;
	
	/** Message queue for this process. */
	protected $msgQueue;
	
	/** Message queue for all children. */
	protected $childMsgQueue = array();
	
	/** Message queue for the parent (for the child proc, $this->msgQueue would actually still be the parent's queue). */
	protected $parentMsgQueue;
	
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
	protected function __construct($rootPath=NULL, $lockfileName=NULL) {

		$this->tickFunc = array($this, 'sanity_check');
		
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
		$this->rootPath = $rootPath;
		$this->fsObj = new cs_fileSystemClass($this->rootPath);
		
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
		
		#parent::__construct();
		$this->msgQueue = new ipc($this->myPid, $this->rootPath);
		declare(ticks=1);
		register_tick_function($this->tickFunc);
	}//end __construct()
	//=========================================================================
	
	
	
	//=========================================================================
	public function sanity_check() {
		if($this->isInitialized) {
			//check that the lockfile exists.
			if(!$this->check_lockfile()) {
				//no lockfile?!?! DIE!!!
				$this->message_handler(__METHOD__, "No lockfile: KILLING CHILDREN", 'DEBUG');
				$this->kill_children();
				
				$this->message_handler(__METHOD__, "ABNORMAL DEATH (lockfile disappeared)", 'FATAL');
			}
			elseif(!is_numeric($this->parentPid)) {
				$this->message_handler(__METHOD__, "parentPid is invalid, KILLING CHILDREN", 'DEBUG');
				$this->kill_children();
				$this->message_handler(__METHOD__, "ABNORMAL DEATH (parentPid was invalid)", 'FATAL');
			}
			elseif(!is_numeric($this->myPid)) {
				$this->message_handler(__METHOD__, "myPid is invalid, KILLING CHILDREN", 'DEBUG');
				$this->kill_children();
				$this->message_handler(__METHOD__, "ABNORMAL DEATH (myPid was invalid)", 'FATAL');
			}
		}
		else {
			$this->message_handler(__METHOD__, "Uninitialized", 'ERROR');
		}
	}//end sanity_check()
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
	 * Exact opposite of is_parent, with an additional check to determine if 
	 * it has been initialized properly.
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
		$this->message_handler(__METHOD__, "caught a signal");
		if($this->is_parent()) {
			if(is_array($this->childArr) && count($this->childArr)) {
				$killingSpree = 0;
				foreach($this->childArr as $queue => $subData) {
						foreach($subData as $num=>$childPid) {
						$this->message_handler(__METHOD__, "parent process: killing child in queue=". $queue .", #$num ($childPid)", 'DEBUG');
						posix_kill($childPid, SIGKILL);
						$this->child_is_dead($childPid, TRUE);
						$killingSpree++;
					}
				}
				$this->message_handler(__METHOD__, "parent process: done killing children, killed (". $killingSpree .") of them", 'DEBUG');
			}
		}
		exit(0);
		
		
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
		
		
		if(!is_null($exitValue)) {
			if(!is_object($this->gfObj)) {
				$this->gfObj = new cs_globalFunctions;
				$this->gfObj->debugRemoveHr=1;
				$this->gfObj->debugPrintOpt=1;
			}
			//create the final message without a time, for displaying in the exception
			$message = "FATAL: ". $message;
			$finalMessage = $this->create_message($method, $type, $message, FALSE);
			
			//show a message WITH a time, so we know when it happened in relation to other things
			$this->gfObj->debug_print($this->create_message($method, $type, $message, TRUE));
			
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
	final private function create_message($method, $type, $message, $addTimestamp=TRUE) {
		
		$retval = "";
		if($addTimestamp) {
			$x = explode('.', sprintf('%.4f', microtime(TRUE)));
			$retval .= date('Y-m-d H:i:s') .".". $x[1] ." ";
		}
		
		if($this->is_child()) {
			$retval .= "{". $type ."}\t-- #". $this->childNum ." : [". $method ."] pid=(". $this->myPid .") ";
		}
		else {
			$retval .= "{". $type ."}\tPARENT  [". $method ."] pid=(". posix_getpid() .") ";
		}
		
		$retval .= $message;
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
	/**
	 * Forks the current process (current process copied into a new one; see 
	 * http://php.net/pcntl_fork for more info).
	 * 
	 * TODO: give a $childNum=NULL argument; if it's numeric, then spawn a new one if that child died, or do nothing (?).
	 */
	private function spawn_child($childNum, $queue) {
		
		if($this->is_child()) {
			$this->message_handler(__METHOD__, "Child attempted to spawn more children!!!", 'FATAL');
		}
		elseif(isset($this->childArr[$queue][$childNum])) {
			$this->message_handler(__METHOD__, "Attempted to create child in a used slot (". $childNum ."), queue=(". $queue ."): used by (". $this->childArr[$queue][$childNum] .")", 'FATAL');
		}
		elseif(is_null($childNum) || !is_numeric($childNum)) {
			$this->message_handler(__METHOD__, "Cannot create child without a valid childNum (". $childNum .")", 'FATAL');
		}
		elseif(is_null($queue) || !strlen($queue) || !isset($this->availableSlots[$queue])) {
			$this->message_handler(__METHOD__, "Invalid queue name given (". $queue .")", 'FATAL');
		}
		
		//NOTE: *EACH* process should have this set.
		$pid = pcntl_fork();
		
		if($pid == -1) {
			$this->message_handler(__METHOD__, "Unable to fork", 'FATAL');
		}
		else {
			$this->myPid = posix_getpid();
			$childMsgQ = new ipc($pid, $this->rootPath);
			if($pid) {
				//PARENT PROCESS!!!
				$this->message_handler(__METHOD__, "Parent pid=(". $this->myPid .") spawned child with PID=". $pid ." in QUEUE=(". $queue .")");
				$this->childArr[$queue][$childNum] = $pid;
				$this->pid2queue[$pid] = $queue;
				
				//now let's add an ipc{} object into an array, so we can talk to our kids.
				$this->childMsgQueue[$queue][$childNum] = $childMsgQ;
			}
			else {
				//CHILD PROCESS!!!
				$this->childArr = NULL;
				$this->childNum = $childNum;
				$this->message_handler(__METHOD__, "Created child process #". $childNum ." in QUEUE=(". $queue .")");
				
				//create an ipc{} object so we can talk to our parent.
				$this->msgQueue = $childMsgQ;
				$this->parentMsgQueue = new ipc($this->parentPid, $this->rootPath);
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
		
		$qName = $this->pid2queue[$pid];
		if($checkIt == $pid) {
			$retval = $actualStatus;
			$this->message_handler(__METHOD__, "Child appears dead (". $checkIt .") in queue (". $qName ."), status=(". $actualStatus .")", 'DEBUG');
		}
		elseif($checkIt != $pid && $checkIt > 0) {
			$this->message_handler(__METHOD__, "returned value doesn't equal pid (". $checkIt ." != ". $pid ."), status=(". $actualStatus .")", 'ERROR');
		}
		
		return($retval);
	}//end child_is_dead()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Remove any dead children.
	 */
	public function clean_children() {
		$retval = array();
		$numDead = 0;
		if(is_array($this->childArr) && $this->is_parent()) {
			foreach($this->childArr as $qName=>$subData) {
				foreach($subData as $childNum=>$pid) {
					//check if the kid is still breathing.
					$childExitStatus = $this->child_is_dead($pid);
					if($childExitStatus !== FALSE) {
						$this->message_handler(__METHOD__, "Found child #". $childNum ." of queue (". $qName .") with pid (". $pid .") dead", 'DEBUG');
						
						unset($this->childArr[$qName][$childNum]);
						$this->availableSlots[$qName][$childNum] = $pid;
						
						//tell the parent to handle the dead child.
						$this->dead_child_handler($childNum, $qName, $childExitStatus);
						$numDead++;
					}
					else {
						$retval[$qName]++;
					}
				}
			}
		}
		elseif(!is_array($this->childArr) && $this->is_parent()) {
			$this->message_handler(__METHOD__, "Parent trying to clean invalid child array... ", 'ERROR');
		}
		else {
			$this->message_handler(__METHOD__, "children can't clean children", 'ERROR');
		}
		
		if($numDead > 0) {
			$this->message_handler(__METHOD__, "after cleaning ". $numDead ." dead children, status of queue::: ". $this->gfObj->debug_print($this->childArr,0), 'DEBUG');
		}
		
		return($retval);
	}//end clean_children()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Handler for child death.  NOTE: need to be able to report to the extending 
	 * class which child actually died.
	 */
	public function child_death() {
		if($this->is_parent()) {
			$this->message_handler(__METHOD__, "Saw child die... calling the cleaning process...");
			$this->clean_children();
		}
		else {
			$this->message_handler(__METHOD__, "Child lived to see itself die... ????", 'ERROR');
		}
	}//end child_death()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * A way to set the private "maxChildren" property.  NOTE: when creating 
	 * multiple queues, this method must be called multiple times, one for 
	 * each queue.  
	 */
	protected function set_max_children($maxChildren, $queue=0) {
		if(isset($this->availableSlots[$queue])) {
			$this->message_handler(__METHOD__, "Queue already exists (". $queue .")", 'ERROR');
		}
		elseif($this->is_parent()) {
			if(!is_array($this->maxChildren)) {
				$this->maxChildren = array();
				$this->defaultQueue = $queue;
			}
			$this->maxChildren[$queue]=$maxChildren;
			
			//dirty way of remembering which slots are free... but it's fast.
			$this->availableSlots[$queue] = array();
			for($x=0; $x<$maxChildren; $x++) {
				$this->availableSlots[$queue][$x] = 'uninitialized';
			}
			$this->message_handler(__METHOD__, "availableSlots: ". $this->gfObj->debug_print($this->availableSlots,0));
		}
		else {
			$this->message_handler(__METHOD__, "FATAL: child trying to change maxChildren to (". $maxChildren .") for queue (". $queue .")", 'FATAL');
		}
	}//end set_max_children()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Spawn another child, up to $this->maxChildren[$queue]; if there's already that 
	 * many children, it will continuously scan them until one slot is freed 
	 * before doing so.
	 */
	protected function spawn($queue=NULL) {
		if(is_null($queue)) {
			$queue = $this->defaultQueue;
		}
		
		$livingChildren = $this->clean_children();
		$livingChildren = $livingChildren[$queue];
		if($livingChildren > $this->maxChildren[$queue]) {
			$this->message_handler(__METHOD__, "Too many children spawned (". $livingChildren ."/". $this->maxChildren[$queue] .")", 'FATAL');
		}
		elseif(!is_numeric($this->maxChildren[$queue]) || $this->maxChildren[$queue] < 1) {
			$this->gfObj->debug_print(__METHOD__, $this->gfObj->debug_print($this->maxChildren,0));
			$this->message_handler(__METHOD__, "maxChildren not set for queue=(". $queue ."), can't spawn", 'ERROR');
		}
		elseif(!is_array($this->availableSlots[$queue]) || count($this->availableSlots[$queue]) > $this->maxChildren[$queue]) {
			$this->message_handler(__METHOD__, "Invalid availableSlots... something terrible happened", 'ERROR');
		}
		
		$numLoops = 0;
		$totalLoops = 0;
		if($livingChildren >= $this->maxChildren[$queue]) {
			$this->message_handler(__METHOD__, "Too many children in queue=(". $queue ."): (". $livingChildren ."/". $this->maxChildren[$queue] .")");
			$this->message_handler(__METHOD__, "PID's of children: ". $this->gfObj->debug_print($this->childArr[$queue],0));
			while($livingChildren >= $this->maxChildren[$queue]) {
				//wait for a full minute before saying we're still waiting.
				//TODO: add something like a per-child timeout, just in case.
				if($numLoops >= 60) {
					$this->message_handler(__METHOD__, "Waiting to spawn new child for queue=(". $queue ."), time slept=(". $totalLoops .")");
					$numLoops = 0;
				}
				sleep(1);
				$numLoops++;
				$totalLoops++;
				$livingChildren = $this->clean_children();
				$livingChildren = $livingChildren[$queue];
			}
		}
		
		//made it this far... spawn a new child!
		$slotNum = array_shift(array_keys($this->availableSlots[$queue]));
		$oldProc = $this->availableSlots[$queue][$slotNum];
		unset($this->availableSlots[$queue][$slotNum]);
		$this->message_handler(__METHOD__, "Pulled slot #". $slotNum .", in queue=(". $queue .") previously used by (". $oldProc .")");
		$this->spawn_child($slotNum, $queue);
		
		return($slotNum);
		
	}//end spawn()
	//=========================================================================
	
	
	
	//=========================================================================
	protected function get_property($name) {
		return($this->$name);
	}//end get_property()
	//=========================================================================
	
	
	
	//=========================================================================
	protected function wait_for_children($queue=NULL) {
		if($this->is_parent()) {
			if(is_null($queue)) {
				$queue = $this->defaultQueue;
			}
			while(count($this->childArr[$queue]) != 0) {
				$this->clean_children();
				usleep(500);
			}
		}
		else {
			$this->message_handler(__METHOD__, "Called by a child... your script has gone wonky", 'ERROR');
		}
	}//end wait_for_children()
	//=========================================================================
	
	
	
	//=========================================================================
	private function remove_lockfile() {
		if($this->is_parent()) {
			$this->fsObj->cd("/");
			$lsData = $this->fsObj->ls();
			if(is_array($lsData[$this->lockfile])) {
				$removeResult = $this->fsObj->rm($this->lockfile);
				$this->message_handler(__METHOD__, "Successfully removed lockfile");
			}
			else {
				$this->message_handler(__METHOD__, "Could not find lockfile?", 'FATAL');
			}
		}
		else {
			$this->message_handler(__METHOD__, "Child attempted to remove the lockfile", 'ERROR');
		}
	}//end remove_lockfile()
	//=========================================================================
	
	
	
	//=========================================================================
	protected function finished() {
		if($this->is_parent()) {
			//wait for the children to finish-up.
			$this->wait_for_children();
			
			//stop doing stuff as ticks happen, so we don't "die abnormally" due
			//	to a missing lockfile.
			unregister_tick_function($this->tickFunc);
			
			//drop the lockfile, tell 'em what happened, and die.
			$this->remove_lockfile();
		}
		
		//NOTE: the "exit(99)" is there to indicate something truly horrible happened, as message_handler() didn't exit after the DONE signal.
		$this->message_handler(__METHOD__, "All done!", 'DONE');
		exit(99);
	}//end finished()
	//=========================================================================
	
	
	
	//=========================================================================
	protected function send_message_to_child($childNum, $message, $qName=NULL, $msgType=NULL) {
		if($this->is_parent()) {
			if(!isset($qName) || !strlen($qName)) {
				$qName = $this->defaultQueue;
			}
			if(!isset($this->childMsgQueue[$qName][$childNum])) {
				$this->message_handler(__METHOD__, "Invalid queue (". $qName .") or child (". $childNum .")", 'FATAL');
			}
			$this->childMsgQueue[$qName][$childNum]->send_message($message, $childNum, $msgType);
		}
		else {
			$this->message_handler(__METHOD__, "Child tried to talk to another child", 'FATAL');
		}
	}//end send_message_to_child()
	//=========================================================================
	
	
	
	//=========================================================================
	/**
	 * Send message from child to parent process.
	 */
	protected function send_message_to_parent($message, $msgType=NULL) {
		if($this->is_child()) {
			$this->parentMsgQueue->send_message($message, $msgType);
		}
		else {
			$this->message_handler(__METHOD__, "", 'FATAL');
		}
	}//end send_message_to_parent()
	//=========================================================================
	
}

?>
