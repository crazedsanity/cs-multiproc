<?php

class SingleProcessTest extends PHPUnit_Framework_TestCase {
	
	public function setUp(){}
	public function tearDown(){}
	
	
	/**
	 * @expectedException InvalidArgumentException
	 */
	public function test_invalidArguments() {
		new cs_SingleProcess(null);
	}
}

?>
