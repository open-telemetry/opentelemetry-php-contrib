<?php

declare(strict_types=1);

return [
    'message' => 'hello world',
    'message_with_interpolation' => 'hello world trace_id={trace_id} span_id={span_id}',
    'context' => [
        'foo' => 'bar',
        'exception' => new \Exception('kaboom', 500, new \RuntimeException('kablam')),
    ],
];
