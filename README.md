PHP-Based Multi-Thread Libraries
=================


This library requires two classes from the cs-content system 
(http://sf.net/projects/cs-content):
 ** cs_fileSystemClass.php
 ** cs_globalFunctions.php

  
  
When building libraries using cs-multiThread, it is important to understand the 
ideology of it: a main script (parent) is written that implements cs-multiThread 
and is used to run external scripts (children).  The theory is as follows:

PARENT
 * spawns external scripts
 * handles communication (i.e. logging output, errors, & exit status to db)
 * handles creating new child when old child dies (as necessary)

CHILD
 * handles processing
 * communicates with parent (optional)
	

The original idea was to have one script: a portion of the script is written to 
run if that process is determined to be the parent; another portion of the 
script is written to run if the process is the child.  The parent portion will 
simply monitor the child processes, spawning a limited number of them and 
spawning more as needed: the child process are set to run a limited set of 
data and then die.  

OLD METHOD
----------

One script handles everything, executing one part of the script only if it is 
the parent, running the other part if it is a child.  All logic is contained 
within one script (and thus MUST be PHP).  The original concept for this lib 
was to be a multi-threaded inventory processing system to more efficiently 
handle massive inventory updates spread across hundreds of files (each file 
containing from just a few to several million lines).

The main process creates a child for each file, running up to 8 concurrently.
As each one dies (indicating the file is completed), the parent handles cleanup 
and then spawns another child for the next file (if there isn't anymore, it scans 
for new ones until all children are dead & no more files are left to process).

NEW METHOD
----------

The main difference between the old method and the new one is where the code is
stored.  In the old method, all code had to be stored in the main script, and 
it had to differentiate parent from child; the new method requires that a single 
script be written to be the parent and the "child processes" have to be stored 
in separate files.  This separates logic, potentially speeding things up in 
the process.

The other advantage of the "new" method is that there is finer-grained control
available to the parent.  It can differentiate normal output from errors.  It 
can also read from and write to the child processes, giving new instructions 
or allowing for on-the-fly updates (i.e. status updates for each child can be 
written to a log or database as they happen for real-time information on 
multiple processes).