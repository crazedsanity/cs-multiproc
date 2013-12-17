<?php

//Wrapper for Thread class

// Initial code: http://www.php-code.net/2010/05/running-multiple-processes-in-php/
// This code adapted from https://gist.github.com/scribu/4736329

class cs_MultiProcess{

	var $output = array();
	var $error = array();
	var $thread = array();
	var $commands = array();

	function __construct($commands) {
		$this->commands = $commands;

		foreach ($this->commands as $key => $command) {
			$this->thread[$key] = Thread::create($command);
			$this->output[$key] = null;
			$this->error[$key] = null;
		}
	}

	function run() {
		$commands = $this->commands;
		//Cycle through commands
		while (count($commands) > 0) {
			foreach ($commands as $key => $command) {
				//Get the output and the errors
				$this->output[$key].=$this->thread[$key]->listen();
				$this->error[$key].=$this->thread[$key]->getError();
				//Check if command is still active
				if ($this->thread[$key]->isActive()) {
					$this->output[$key].=$this->thread[$key]->listen();
					//Check if command is busy
					if ($this->thread[$key]->isBusy()) {
						$this->thread[$key]->close();
						unset($commands[$key]);
					}
				} else {
					//Close the command and free resources
					$this->thread[$key]->close();
					unset($commands[$key]);
				}
			}
		}
		return $this->output;
	}

}