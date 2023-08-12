<?php

declare(strict_types=1);

return [
    'message' => 'hello world',
    'message_with_interpolation' => 'hello world traceId={traceId} spanId={spanId}',
    'context' => [
        'foo' => 'bar',
        'exception' => new \Exception('kaboom', 500, new \RuntimeException('kablam')),
    ],
];
