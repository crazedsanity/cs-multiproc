<?php

/**
 * 
 * SVN Signature::::::: $Id$
 * Last Committed Date: $Date$
 * Last Committed Path: $HeadURL$
 * Current Revision:::: $Revision$
 */

class cs_ipc {
	
	/** Queue resource var. */
	protected $resource=NULL;
	
	/** maximum size of message that we will accept... */
	private $maxSize=1024;
	
	/** Holds the value of what the last message type was. */
	protected $lastMsgType=NULL;
	
	//-------------------------------------------------------------------------
	public function __construct($qName, $rwDir='/tmp', $maxMessageSize=NULL) {
		if(isset($maxMsgSize) && is_numeric($maxMsgSize) && ($maxMsgSize > 0)) {
			$this->maxSize = $maxMsgSize;
		}
		$file = $rwDir .'/'. $qName .'.phpMessageQueue.stat';
		$this->resource = msg_get_queue(ftok($file, 'R'), 0666);
	}//end __construct()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	/**
	 * Add a message to the queue.
	 */
	public function send_message($message, $msgType=1) {
		if(strlen($message)) {
			if(!isset($msgType) || !is_numeric($msgType)) {
				$msgType = 1;
			}
			$retval = msg_send($this->resource, $msgType, $message);
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
	public function receive_message($msgType=1) {
		if(!isset($msgType) || !is_numeric($msgType)) {
			$msgType = 1;
		}
		$retval = NULL;
		msg_receive($this->resource, $msgType, $this->lastMsgType, $this->maxSize, $retval);
		return($retval);
	}//end receive_message()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	/**
	 * Returns the number of messages in this queue.
	 */
	public function get_num_messages() {
		$data = msg_stat_queue($this->resource);
		return($data['msg_qnum']);
	}//end get_num_messages()
	//-------------------------------------------------------------------------
	
}
?>
