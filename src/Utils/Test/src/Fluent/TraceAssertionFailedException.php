<?php

declare(strict_types=1);

namespace OpenTelemetry\TestUtils\Fluent;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Exception thrown when a trace assertion fails.
 * @psalm-suppress InternalClass
 */
class TraceAssertionFailedException extends AssertionFailedError
{
    /** @var array */
    private array $expectedStructure;

    /** @var array */
    private array $actualStructure;

    /**
     * @param string $message The error message
     * @param array $expectedStructure The expected structure
     * @param array $actualStructure The actual structure
     * @psalm-suppress InternalMethod
     */
    public function __construct(string $message, array $expectedStructure, array $actualStructure)
    {
        parent::__construct($message . "\n" . $this->formatDiff($expectedStructure, $actualStructure));
        $this->expectedStructure = $expectedStructure;
        $this->actualStructure = $actualStructure;
    }

    /**
     * Get the expected structure.
     *
     * @return array
     */
    public function getExpectedStructure(): array
    {
        return $this->expectedStructure;
    }

    /**
     * Get the actual structure.
     *
     * @return array
     */
    public function getActualStructure(): array
    {
        return $this->actualStructure;
    }

    /**
     * Format the diff between expected and actual structures.
     *
     * @param array $expected The expected structure
     * @param array $actual The actual structure
     * @return string
     */
    private function formatDiff(array $expected, array $actual): string
    {
        // First, convert the fluent assertion structures to a format suitable for diffing
        $expectedStructure = $this->convertFluentStructureToArray($expected);
        $actualStructure = $this->convertFluentStructureToArray($actual);

        // Generate a PHPUnit-style diff
        $output = "\n\n--- Expected Trace Structure\n";
        $output .= "+++ Actual Trace Structure\n";
        $output .= "@@ @@\n";

        // Generate the diff for the root level
        $output .= $this->generateArrayDiff($expectedStructure, $actualStructure);

        return $output;
    }

    /**
     * Converts the fluent assertion structure to a format suitable for diffing.
     *
     * @param array $structure The fluent assertion structure
     * @return array The converted structure
     */
    private function convertFluentStructureToArray(array $structure): array
    {
        $result = [];

        foreach ($structure as $item) {
            if (!isset($item['type'])) {
                continue;
            }

            switch ($item['type']) {
                case 'root_span':
                    $result[] = [
                        'name' => $item['name'],
                        'type' => 'root',
                    ];

                    break;

                case 'child_span':
                    $result[] = [
                        'name' => $item['name'],
                        'type' => 'child',
                    ];

                    break;

                case 'missing_root_span':
                    $result[] = [
                        'name' => $item['expected_name'],
                        'type' => 'root',
                        'missing' => true,
                        'available' => $item['available_root_spans'] ?? [],
                    ];

                    break;

                case 'missing_child_span':
                    $result[] = [
                        'name' => $item['expected_name'],
                        'type' => 'child',
                        'missing' => true,
                        'available' => $item['available_spans'] ?? [],
                    ];

                    break;

                case 'root_span_count':
                    $result[] = [
                        'type' => 'count',
                        'count' => $item['count'],
                        'spans' => $item['spans'] ?? [],
                    ];

                    break;

                    // Add other types as needed
            }
        }

        return $result;
    }

    /**
     * Recursively generates a diff between two arrays.
     *
     * @param array $expected The expected array
     * @param array $actual The actual array
     * @param int $depth The current depth for indentation
     * @return string The formatted diff
     */
    private function generateArrayDiff(array $expected, array $actual, int $depth = 0): string
    {
        $output = '';
        $indent = str_repeat('  ', $depth);

        // If arrays are indexed numerically, compare them as lists
        if ($this->isIndexedArray($expected) && $this->isIndexedArray($actual)) {
            $output .= $indent . "Array (\n";

            // Find the maximum index to iterate through
            $maxIndex = max(count($expected), count($actual)) - 1;

            for ($i = 0; $i <= $maxIndex; $i++) {
                if (isset($expected[$i]) && isset($actual[$i])) {
                    // Both arrays have this index, compare the values
                    if (is_array($expected[$i]) && is_array($actual[$i])) {
                        // Both values are arrays, recursively compare
                        $output .= $indent . "  [$i] => Array (\n";
                        $output .= $this->generateArrayDiff($expected[$i], $actual[$i], $depth + 2);
                        $output .= $indent . "  )\n";
                    } elseif ($expected[$i] === $actual[$i]) {
                        // Values are the same
                        $output .= $indent . "  [$i] => " . $this->formatValue($expected[$i]) . "\n";
                    } else {
                        // Values are different
                        $output .= $indent . "- [$i] => " . $this->formatValue($expected[$i]) . "\n";
                        $output .= $indent . "+ [$i] => " . $this->formatValue($actual[$i]) . "\n";
                    }
                } elseif (isset($expected[$i])) {
                    // Only in expected
                    $output .= $indent . "- [$i] => " . $this->formatValue($expected[$i]) . "\n";
                } else {
                    // Only in actual
                    $output .= $indent . "+ [$i] => " . $this->formatValue($actual[$i]) . "\n";
                }
            }

            $output .= $indent . ")\n";
        } else {
            // Compare as associative arrays
            $output .= $indent . "Array (\n";

            // Get all keys from both arrays
            $allKeys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
            sort($allKeys);

            foreach ($allKeys as $key) {
                if (isset($expected[$key]) && isset($actual[$key])) {
                    // Both arrays have this key, compare the values
                    if (is_array($expected[$key]) && is_array($actual[$key])) {
                        // Both values are arrays, recursively compare
                        $output .= $indent . "  ['$key'] => Array (\n";
                        $output .= $this->generateArrayDiff($expected[$key], $actual[$key], $depth + 2);
                        $output .= $indent . "  )\n";
                    } elseif ($expected[$key] === $actual[$key]) {
                        // Values are the same
                        $output .= $indent . "  ['$key'] => " . $this->formatValue($expected[$key]) . "\n";
                    } else {
                        // Values are different
                        $output .= $indent . "- ['$key'] => " . $this->formatValue($expected[$key]) . "\n";
                        $output .= $indent . "+ ['$key'] => " . $this->formatValue($actual[$key]) . "\n";
                    }
                } elseif (isset($expected[$key])) {
                    // Only in expected
                    $output .= $indent . "- ['$key'] => " . $this->formatValue($expected[$key]) . "\n";
                } else {
                    // Only in actual
                    $output .= $indent . "+ ['$key'] => " . $this->formatValue($actual[$key]) . "\n";
                }
            }

            $output .= $indent . ")\n";
        }

        return $output;
    }

    /**
     * Checks if an array is indexed numerically (not associative).
     *
     * @param array $array The array to check
     * @return bool True if the array is indexed, false if it's associative
     */
    private function isIndexedArray(array $array): bool
    {
        if (empty($array)) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Format a value for display.
     *
     * @param mixed $value The value to format
     * @return string
     */
    private function formatValue($value): string
    {
        if (is_string($value)) {
            return "\"$value\"";
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (null === $value) {
            return 'null';
        } elseif (is_array($value)) {
            // Check if this array looks like a status
            if (isset($value['code']) || isset($value['description'])) {
                return $this->formatStatus($value);
            }

            $json = json_encode($value);

            return $json === false ? '[unable to encode]' : $json;
        } elseif (is_int($value) && $this->isSpanKind($value)) {
            return $this->formatKind($value);
        }

        return (string) $value;
    }

    /**
     * Checks if a value is a span kind.
     *
     * @param int $value The value to check
     * @return bool True if the value is a span kind
     */
    private function isSpanKind(int $value): bool
    {
        return $value >= 0 && $value <= 4;
    }

    /**
     * Format a span kind for display.
     *
     * @param int $kind The span kind
     * @return string
     */
    private function formatKind(int $kind): string
    {
        $kinds = [
            0 => 'KIND_INTERNAL',
            1 => 'KIND_SERVER',
            2 => 'KIND_CLIENT',
            3 => 'KIND_PRODUCER',
            4 => 'KIND_CONSUMER',
        ];

        return $kinds[$kind] ?? "UNKNOWN_KIND($kind)";
    }

    /**
     * Format a span status for display.
     *
     * @param array $status The span status
     * @return string
     */
    private function formatStatus(array $status): string
    {
        $output = 'Status: Code=';

        if (isset($status['code'])) {
            $statusCodes = [
                0 => 'STATUS_UNSET',
                1 => 'STATUS_OK',
                2 => 'STATUS_ERROR',
            ];

            $code = $status['code'];
            $output .= isset($statusCodes[$code]) ? $statusCodes[$code] : "UNKNOWN_STATUS($code)";
        } else {
            $output .= 'UNDEFINED';
        }

        if (isset($status['description']) && $status['description']) {
            $output .= ", Description=\"{$status['description']}\"";
        }

        return $output;
    }
}
