# Contrib Sampler

Provides additional samplers that are not part of the official specification.

## Installation

```shell
composer require open-telemetry/sampler-rule-based
```

## RuleBasedSampler

Allows sampling based on a list of rule sets. The first matching rule set will decide the sampling result.

```php
$sampler = new RuleBasedSampler(
    [
        new RuleSet(
            [
                new SpanKindRule(Kind::Server),
                new AttributeRule('url.path', '~^/health$~'),
            ],
            new AlwaysOffSampler(),
        ),
    ],
    new AlwaysOnSampler(),
);
```

### Configuration

###### Example: drop spans for the /health endpoint

```yaml
contrib_rule_based:
    rule_sets:
    -   rules:
        -   span_kind: { kind: SERVER }
        -   attribute: { key: url.path, pattern: ~^/health$~ }
        delegate:
            always_off: {}
    fallback: # ...
```

###### Example: sample spans with at least one sampled link

```yaml
contrib_rule_based:
    rule_sets:
    -   rules: [ link: { sampled: true } ]
        delegate:
            always_on: {}
    fallback: # ...
```

###### Example: modeling parent based sampler as rule based sampler

```yaml
rule_based:
    rule_sets:
    -   rules: [ parent: { sampled: true, remote: true } ]
        delegate: # remote_parent_sampled
    -   rules: [ parent: { sampled: false, remote: true } ]
        delegate: # remote_parent_not_sampled
    -   rules: [ parent: { sampled: true, remote: false } ]
        delegate: # local_parent_sampled
    -   rules: [ parent: { sampled: false, remote: false } ]
        delegate: # local_parent_not_sampled
    fallback: # root
```

## AlwaysRecordingSampler

Records all spans to allow the usage of span processors that generate metrics from spans.

```php
$sampler = new AlwaysRecordingSampler(
    new ParentBasedSampler(new AlwaysOnSampler()),
);
```

### Configuration

```yaml
always_recording:
    sampler: # ...
```
