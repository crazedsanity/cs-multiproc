<?php

//Wrapper for Thread class

// Initial code: http://www.php-code.net/2010/05/running-multiple-processes-in-php/
// This code adapted from https://gist.github.com/scribu/4736329

class cs_MultiProcess {

	protected $output = array();
	protected $error = array();
	protected $thread = array();
	protected $commands = array();
	protected $newCommands = array();

	function __construct(array $commands=null) {
		$this->addCommands($commands);
	}
	
	
	public function addCommands(array $commands) {
		
		if(is_array($commands) && count($commands)) {
			$this->commands = $commands;

			foreach ($this->commands as $key => $command) {
				$this->thread[$key] = new cs_SingleProcess($command); //Thread::create($command);
				$this->output[$key] = null;
				$this->error[$key] = null;
			}
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
				
				$myErr = $this->thread[$key]->getError();
				$this->error[$key].=$myErr;
				
				//Check if command is still active
				if ($this->thread[$key]->isActive()) {
					$myOut = $this->thread[$key]->listen();
					$this->output[$key].=$myOut;
				} else {
					//Close the command and free resources
					$this->thread[$key]->close();
					unset($commands[$key]);
					
					if($printOutput) {
						echo "thread #". $key ." is done...\n";
					}
				}
			}
			
			//add any new commands before running through another loop.
			if(is_array($this->newCommands) && count($this->newCommands)) {
				$this->commands = array_merge($this->commands, $this->newCommands);
				$this->newCommands = array();
			}
		}
		
		echo "FINAL OUTPUT::: ". print_r($this->output, true) ."\n\n";
		return $this->output;
	}

}