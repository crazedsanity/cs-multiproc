<?php

//Wrapper for Thread class

// Initial code: http://www.php-code.net/2010/05/running-multiple-processes-in-php/
// This code adapted from https://gist.github.com/scribu/4736329

class cs_MultiProcess {

	var $output = array();
	var $error = array();
	var $thread = array();
	var $commands = array();

	function __construct(array $commands) {
		if(is_array($commands) && count($commands)) {
			$this->commands = $commands;

			foreach ($this->commands as $key => $command) {
				$this->thread[$key] = new cs_SingleProcess($command); //Thread::create($command);
				$this->output[$key] = null;
				$this->error[$key] = null;
			}
		}
		else {
			throw new InvalidArgumentException(__METHOD__ .": no arguments");
		}
	}

	function run($printOutput=true) {
		$commands = $this->commands;
		//Cycle through commands
		while (count($commands) > 0) {
			foreach ($commands as $key => $command) {
				//Get the output and the errors
				$myOut = $this->thread[$key]->listen();
				$this->output[$key].=$myOut;
//				if($printOutput && strlen($myOut)) {
//					echo "    ". $myOut;
//				}
				
				$myErr = $this->thread[$key]->getError();
				$this->error[$key].=$myErr;
//				if($printOutput && strlen($myErr)) {
//					echo "    ". $myErr ."\n";
//				}
				
				//Check if command is still active
				if ($this->thread[$key]->isActive()) {
					$myOut = $this->thread[$key]->listen();
					$this->output[$key].=$myOut;
//					if($printOutput && strlen($myOut)) {
//						echo "    ". $myOut;
//					}
					
//					//Check if command is busy
//					if ($this->thread[$key]->isBusy()) {
//						$this->thread[$key]->close();
//						
//						if($printOutput) {
//							echo "Closed busy process (". $key ."), command:: ". $commands[$key] ."\n";
//						}
//						unset($commands[$key]);
//					}
				} else {
					//Close the command and free resources
					$this->thread[$key]->close();
					unset($commands[$key]);
					
					if($printOutput) {
						echo "thread #". $key ." is done...\n";
					}
				}
			}
		}
		
		echo "FINAL OUTPUT::: ". print_r($this->output, true) ."\n\n";
		return $this->output;
	}

}