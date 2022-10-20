<?php

declare(strict_types=1);

/**
 * THIS CODE IS WRITTEN IN GOLANG
 *
 * This is alternate code for the php stacktrace translator
 * located in the go-collector-contrib/exporter/awsxrayexporter/internal/translator/cause.go
 *
 * Currently the php repo contains a stacktrace translator that improves
 * upon original php stacktraces by allowing for exception chaining a
 * and giving exact lines of where exceptions occurred.
 *
 * If for any reason this no longer wants to be used, the exception
 * translator in cause.go can be replaced with the following code. It
 * translates original php stacktraces into awsxray format.
 */

// PUT IN CAUSE.GO FILE
// func fillPhpStacktrace(stacktrace string, exceptions []awsxray.Exception) []awsxray.Exception {
// 	r := textproto.NewReader(bufio.NewReader(strings.NewReader(stacktrace)))

// 	exception := &exceptions[0]
// 	var line string
// 	line, err := r.ReadLine()
// 	if err != nil {
// 		return exceptions
// 	}

// 	exception.Stack = make([]awsxray.StackFrame, 0)
// 	for {
// 		if strings.HasPrefix(line, "#") {
// 			parenFirstIdx := strings.IndexByte(line, '(')
// 			parenLastIdx := strings.IndexByte(line, ')')
// 			slashIdx := strings.IndexByte(line, '/')
// 			colonIdx := strings.IndexByte(line, ':')
// 			label := ""
// 			path := ""
// 			lineNumber := 0

// 			if slashIdx >= 0 && colonIdx >= 0 && colonIdx != len(line)-1 {
// 				label = line[colonIdx+2:]
// 				path = line[slashIdx:parenFirstIdx]
// 				lineNumber, _ = strconv.Atoi(line[parenFirstIdx+1 : parenLastIdx])
// 			}

// 			// only append the exception if all the values of the exception are not default
// 			if path != "" || label != "" || lineNumber != 0 {
// 				stack := awsxray.StackFrame{
// 					Path:  aws.String(path),
// 					Label: aws.String(label),
// 					Line:  aws.Int(lineNumber),
// 				}
// 				exception.Stack = append(exception.Stack, stack)
// 			}
// 		}
// 		line, err = r.ReadLine()
// 		if err != nil {
// 			break
// 		}
// 	}
// 	return exceptions
// }

//PUT IN CAUSE_TEST.GO FILE
// func TestParseExceptionPhpStacktrace(t *testing.T) {
// 	exceptionType := "Exception"
// 	message := "Thrown from Class C"

// 	stacktrace := `#0 /Users/olihamuy/Desktop/TestBay/test.php(66): C->exc()
// #1 /Users/olihamuy/Desktop/TestBay/test.php(81): C->doexc()
// #2 /Users/olihamuy/Desktop/TestBay/test.php(85): fail()
// #3 {main}`

// 	exceptions := parseException(exceptionType, message, stacktrace, "php")

// 	assert.Len(t, exceptions, 1)
// 	assert.NotEmpty(t, exceptions[0].ID)
// 	assert.Equal(t, "Exception", *exceptions[0].Type)
// 	assert.Equal(t, "Thrown from Class C", *exceptions[0].Message)
// 	assert.Len(t, exceptions[0].Stack, 3)
// 	assert.Equal(t, "C->exc()", *exceptions[0].Stack[0].Label)
// 	assert.Equal(t, "/Users/olihamuy/Desktop/TestBay/test.php", *exceptions[0].Stack[0].Path)
// 	assert.Equal(t, 66, *exceptions[0].Stack[0].Line)
// 	assert.Equal(t, "C->doexc()", *exceptions[0].Stack[1].Label)
// 	assert.Equal(t, "/Users/olihamuy/Desktop/TestBay/test.php", *exceptions[0].Stack[1].Path)
// 	assert.Equal(t, 81, *exceptions[0].Stack[1].Line)
// 	assert.Equal(t, "fail()", *exceptions[0].Stack[2].Label)
// 	assert.Equal(t, "/Users/olihamuy/Desktop/TestBay/test.php", *exceptions[0].Stack[2].Path)
// 	assert.Equal(t, 85, *exceptions[0].Stack[2].Line)
// }

// func TestParseExceptionPhpStacktraceMalformed(t *testing.T) {
// 	exceptionType := "Exception"
// 	message := "Thrown from Class C"

// 	stacktrace := `#0 /Users/olihamuy/Desktop/TestBay/test.php(66): C->exc()
// #1 /Users/olihamuy/Desktop/TestBay/test.php(81): C->doexc()
// #2 /Users/olihamuy/Desktop/TestBay/test.php(85) fail()
// #3 /Users/olihamuy/Desktop/TestBay/test.php(85):
// #4 /Users/olihamuy/Desktop/TestBay/test.php(): fail()
// #5 {main}`

// 	exceptions := parseException(exceptionType, message, stacktrace, "php")

// 	assert.Len(t, exceptions, 1)
// 	assert.NotEmpty(t, exceptions[0].ID)
// 	assert.Equal(t, "Exception", *exceptions[0].Type)
// 	assert.Equal(t, "Thrown from Class C", *exceptions[0].Message)
// 	assert.Len(t, exceptions[0].Stack, 3)
// 	assert.Equal(t, "C->exc()", *exceptions[0].Stack[0].Label)
// 	assert.Equal(t, "/Users/olihamuy/Desktop/TestBay/test.php", *exceptions[0].Stack[0].Path)
// 	assert.Equal(t, 66, *exceptions[0].Stack[0].Line)
// 	assert.Equal(t, "fail()", *exceptions[0].Stack[2].Label)
// 	assert.Equal(t, "/Users/olihamuy/Desktop/TestBay/test.php", *exceptions[0].Stack[2].Path)
// 	assert.Equal(t, 0, *exceptions[0].Stack[2].Line)
// }

// func TestParseExceptionPhpEmptyStacktrace(t *testing.T) {
// 	exceptionType := "Exception"
// 	message := "Thrown from Class C"

// 	stacktrace := ""

// 	exceptions := parseException(exceptionType, message, stacktrace, "php")

// 	assert.Len(t, exceptions, 1)
// 	assert.NotEmpty(t, exceptions[0].ID)
// 	assert.Equal(t, "Exception", *exceptions[0].Type)
// 	assert.Equal(t, "Thrown from Class C", *exceptions[0].Message)
// 	assert.Len(t, exceptions[0].Stack, 0)
// }
