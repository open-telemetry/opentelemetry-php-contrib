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
        $output = "\n\nExpected Trace Structure:\n";
        $output .= $this->formatExpectedStructure($expected);

        $output .= "\n\nActual Trace Structure:\n";
        $output .= $this->formatActualStructure($actual);

        return $output;
    }

    /**
     * Format the expected structure.
     *
     * @param array $expected The expected structure
     * @param int $indent The indentation level
     * @return string
     */
    private function formatExpectedStructure(array $expected, int $indent = 0): string
    {
        $output = '';
        $indentation = str_repeat('  ', $indent);

        foreach ($expected as $item) {
            if (!isset($item['type'])) {
                continue;
            }

            switch ($item['type']) {
                case 'root_span':
                    $output .= $indentation . "Root Span: \"{$item['name']}\"\n";

                    break;
                case 'root_span_count':
                    $output .= $indentation . "Root Span Count: {$item['count']}\n";

                    break;
                case 'span_kind':
                    $output .= $indentation . 'Kind: ' . $this->formatKind($item['kind']) . "\n";

                    break;
                case 'span_attribute':
                    $output .= $indentation . "Attribute \"{$item['key']}\": " . $this->formatValue($item['value']) . "\n";

                    break;
                case 'span_status':
                    $output .= $indentation . 'Status: Code=' . $this->formatValue($item['code']);
                    if (isset($item['description'])) {
                        $output .= ", Description=\"{$item['description']}\"";
                    }
                    $output .= "\n";

                    break;
                case 'span_event':
                    $output .= $indentation . "Event: \"{$item['name']}\"\n";

                    break;
                case 'span_event_attribute':
                    $output .= $indentation . "Event Attribute \"{$item['key']}\": " . $this->formatValue($item['value']) . "\n";

                    break;
                case 'child_span':
                    $output .= $indentation . "Child Span: \"{$item['name']}\"\n";

                    break;
                case 'child_span_count':
                    $output .= $indentation . "Child Span Count: {$item['count']}\n";

                    break;
            }
        }

        return $output;
    }

    /**
     * Format the actual structure.
     *
     * @param array $actual The actual structure
     * @param int $indent The indentation level
     * @return string
     */
    private function formatActualStructure(array $actual, int $indent = 0): string
    {
        $output = '';
        $indentation = str_repeat('  ', $indent);

        foreach ($actual as $item) {
            if (!isset($item['type'])) {
                continue;
            }

            switch ($item['type']) {
                case 'root_span':
                    $output .= $indentation . "Root Span: \"{$item['name']}\"\n";

                    break;
                case 'missing_root_span':
                    $output .= $indentation . "Missing Root Span: \"{$item['expected_name']}\"\n";
                    if (!empty($item['available_root_spans'])) {
                        $output .= $indentation . '  Available Root Spans: ' . implode(', ', array_map(function ($name) {
                            return "\"$name\"";
                        }, $item['available_root_spans'])) . "\n";
                    }

                    break;
                case 'root_span_count':
                    $output .= $indentation . "Root Span Count: {$item['count']}\n";
                    if (!empty($item['spans'])) {
                        $output .= $indentation . '  Root Spans: ' . implode(', ', array_map(function ($name) {
                            return "\"$name\"";
                        }, $item['spans'])) . "\n";
                    }

                    break;
                case 'span_kind':
                    $output .= $indentation . 'Kind: ' . $this->formatKind($item['kind']) . "\n";

                    break;
                case 'span_attribute':
                    $output .= $indentation . "Attribute \"{$item['key']}\": " . $this->formatValue($item['value']) . "\n";

                    break;
                case 'missing_span_attribute':
                    $output .= $indentation . "Missing Attribute: \"{$item['key']}\"\n";

                    break;
                case 'span_status':
                    $output .= $indentation . 'Status: Code=' . $this->formatValue($item['code']);
                    if (isset($item['description'])) {
                        $output .= ", Description=\"{$item['description']}\"";
                    }
                    $output .= "\n";

                    break;
                case 'span_event':
                    $output .= $indentation . "Event: \"{$item['name']}\"\n";

                    break;
                case 'missing_span_event':
                    $output .= $indentation . "Missing Event: \"{$item['expected_name']}\"\n";

                    break;
                case 'span_event_attribute':
                    $output .= $indentation . "Event Attribute \"{$item['key']}\": " . $this->formatValue($item['value']) . "\n";

                    break;
                case 'child_span':
                    $output .= $indentation . "Child Span: \"{$item['name']}\"\n";

                    break;
                case 'missing_child_span':
                    $output .= $indentation . "Missing Child Span: \"{$item['expected_name']}\"\n";

                    break;
                case 'unexpected_child_span':
                    $output .= $indentation . "Unexpected Child Span: \"{$item['name']}\"\n";

                    break;
                case 'child_span_count':
                    $output .= $indentation . "Child Span Count: {$item['count']}\n";

                    break;
            }
        }

        return $output;
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
            $json = json_encode($value);

            return $json === false ? '[unable to encode]' : $json;
        }

        return (string) $value;
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
}
