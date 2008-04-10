<?php



abstract class ipc {
	
	/** Required for using the ftok() function... */
	private $file = '/tmp/php_msgqueue.stat';
	
	/** Queue resource var. */
	protected $q=NULL;
	
	/** maximum size of message that we will accept... */
	private $maxSize=1024;
	
	/** Holds the value of what the last message type was. */
	protected $lastMsgType=NULL;
	
	//-------------------------------------------------------------------------
	protected function __construct($maxMessageSize=NULL) {
		if(isset($maxMsgSize) && is_numeric($maxMsgSize) && ($maxMsgSize > 0)) {
			$this->maxSize = $maxMsgSize;
		}
		$this->q = msg_get_queue(ftok($this->file, 'R'), 0666);
	}//end __construct()
	//-------------------------------------------------------------------------
	
	
	
	//-------------------------------------------------------------------------
	/**
	 * Add a message to the queue.
	 */
	protected function send_message($message, $msgType=1) {
		if(strlen($message)) {
			$retval = msg_send($this->q, $msgType, $message);
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
	protected function receive_message($msgType=1) {
		$myMsgType = NULL;
		msg_receive($this->q, $msgType, $this->lastMsgType, $this->maxSize, $retval);
		return($retval);
	}//end receive_message()
	//-------------------------------------------------------------------------
	
}
?>
