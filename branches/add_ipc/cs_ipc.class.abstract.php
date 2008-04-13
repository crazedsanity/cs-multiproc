<?php

/**
 * SVN Signature::::::: $Id$
 * Last Committed Date: $Date$
 * Last Committed Path: $HeadURL$
 * Current Revision:::: $Revision$
 * 
 * 
 * Because the IPC messaging built into PHP doesn't seem to work the way I want it to, 
 * I've created my own messaging system to handle inter-process communication.
 * 
 * The idea: each process uses a single file for it's queue; all messages are appended 
 * to the file.  When one process passes a message to another, the originator appends 
 * a line to the target process' message file.  Each process needs to remember the last 
 * line number it read, so all lines past are new messages, and are therefore read-in 
 * as such.
 * 
 * POTENTIAL PROBLEMS:
 * 		1.) Message files could become massive over time, which would require creating a 
 * new file and notifying the reading process and all writing processes of the new 
 * filename.  Any problems in this portion could result in lost messages.
 * 
 * FORMAT OF MESSAGES:
 * 		Messages are written in a multi-field format, with proprietary column meanings.  
 * The delimiter for each field is a double-pipe (||).  The field meanings:
 * 		1.) timestamp (unix timestamp, with microseconds)
 * 		2.) process_id of sender
 * 		3.) message type (numeric, similar to that in the msg_send() method from PHP IPC)
 * 		4.) message content (base64-encoded to avoid special character problems)
 */

class cs_ipc {
	
	/** Queue resource var. */
	protected $resource=NULL;
	
	/** Holds the value of what the last message type was. */
	protected $lastMsgType=NULL;
	
	/** Process ID, for attaching a queue to a given process. */
	protected $processId;
	
	//-------------------------------------------------------------------------
	public function __construct($processId, $rwDir='/tmp') {
		
		//EXAMPLE: ~project/rw/11354.phpMessageQueue.stat
		$file = $rwDir .'/'. $processId .'.phpMessageQueue.stat';
		
		$this->resource = new cs_fileSystemClass($rwDir);
		$this->resource->create_file($file, TRUE);
		
		$this->gfObj = new cs_globalFunctions;
	}//end __construct()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	/**
	 * Add a message to the queue.
	 */
	public function send_message($senderPid, $message, $msgType=1) {
		if(strlen($message)) {
			if(!isset($msgType) || !is_numeric($msgType)) {
				$msgType = 1;
			}
			
			$data = array(
				0	=> microtime(TRUE),
				1	=> $senderPid,
				2	=> $msgType,
				3	=> base64_encode($message)
			);
			$line = $this->gfObj->string_from_array($data, NULL, '||');
			$retval = $this->resource->append_to_file($line);
		}
		else {
			throw new exception(__METHOD__ .": no data or zero-length message");
		}
		return($retval);
	}//end send_message()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	/**
	 * Get a message out of the queue.
	 */
	public function receive_messages() {
		
		//since we've already got the file open, check if there are lines left.
		$newLines = $this->resource->count_remaining_lines();
		
		$retval = NULL;
		if($newLines > 0) {
			$retval = array();
			
			for($i=0; $i < $newLines; $i++) {
				$retval[] = $this->resource->get_next_line();
			}
		}
		
		return($retval);
	}//end receive_message()
	//-------------------------------------------------------------------------
	
	
}
?>
