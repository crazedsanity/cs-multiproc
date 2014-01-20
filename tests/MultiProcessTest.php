<?php

class MultiProcessTest extends PHPUnit_Framework_TestCase {
	
	public function setUp(){}
	public function tearDown(){}
	
	
	public function test_invalidArguments() {
		try {
			new cs_MultiProcess(array());
		}
		catch(Exception $ex) {
			$this->fail("An unexpected exception was thrown::: ". $ex->getMessage());
		}
	}
}

?>
