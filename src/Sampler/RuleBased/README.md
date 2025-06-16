# RuleBasedSampler

Allows sampling based on a list of rule sets.

## Installation

```shell
composer require open-telemetry/sampler-rule-based
```

## Usage

Provide a list of `RuleSet` instances and a fallback sampler to the `RuleBasedSampler` constructor. Each rule set
contains a list of rules and a delegate sampler to execute if the rule matches. The rules are evaluated in the order
they are defined. The first matching rule set will decide the sampling result.

If no rules match, then the fallback sampler is used.

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

## Configuration

The RuleBased sampler can be configured through [Declarative Configuration](https://opentelemetry.io/docs/specs/otel/configuration/#declarative-configuration), using the
key `php_rule_based` under `tracer_provider.sampler.sampler`:

```yaml
file_format: "0.4"

tracer_provider:
  sampler:
    php_rule_based:
      rule_sets:
        # ...
      fallback:
        # ...
```

### Examples

Drop spans for the /health endpoint:

```yaml
php_rule_based:
    rule_sets:
    -   rules:
        -   span_kind: { kind: SERVER }
        -   attribute: { key: url.path, pattern: ~^/health$~ }
        delegate:
            always_off: {}
    fallback: # ...
```

Sample spans with at least one sampled link:

```yaml
php_rule_based:
    rule_sets:
    -   rules: [ link: { sampled: true } ]
        delegate:
            always_on: {}
    fallback: # ...
```

Modeling parent-based sampler as rule-based sampler:

```yaml
php_rule_based:
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