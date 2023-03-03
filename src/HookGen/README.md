# HookGen (WIP)

This is an experimental development tool that dumps stack trace for each function call. It's uses
dynamic analysis (at runtime) and possibly static analysis in future to combine best of both.

## Building

```
phpize
./configure
make
make install
```

## Usage

php -dextension=hookgen.so [php app]

As a result, `functions.log` file will be generated. This file contains set of stack traces that
have to merged by branch_merger post-processing tool which will also generate html page for
automatic hook(s) code generation as on below screenshot.

![image](https://user-images.githubusercontent.com/102958445/222697188-324c5bc5-3b03-4713-82ef-5945b709c63d.png)
