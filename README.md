# PHP-Based Multi-Process Libraries

NOTE::: this project was previously called "cs-multithread", but was renamed  due to the fact that it's actually for creating multiple processes (and PHP can't actually handle threading).

For more (or maybe less) up-to-date information, please [view the wiki](https://github.com/crazedsanity/cs-multiproc/wiki).  This document serves as an excruciatingly brief functionality overview.  The basics:

## "Single Process"

This library spawns a single process and monitors it's output, errors, and exit codes.  There are actually (at least) two processes spawn: the PHP process that runs the script, and a secondary process which is the command to be monitored.

## "Multi Process"

This library will spawn multiple versions of the "Single Process".

# Licensing

This library is dual-licensed under the GPLv2 and MIT licenses.
