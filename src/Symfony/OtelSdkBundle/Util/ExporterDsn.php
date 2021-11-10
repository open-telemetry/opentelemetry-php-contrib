<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\Util;

use InvalidArgumentException;

class ExporterDsn
{
    private string $type;
    private string $scheme;
    private string $host;
    private ?string $path = null;
    private ?int $port = null;
    private array $options = [];
    private ?string $user = null;
    private ?string $password = null;

    public function __construct(
        string $type,
        string $scheme,
        string $host,
        ?string $path = null,
        ?int $port = null,
        array $options = [],
        ?string $user = null,
        ?string $password = null
    ) {
        $this->type = $type;
        $this->scheme = $scheme;
        $this->host = $host;
        $this->path = $path;
        $this->port = $port;
        $this->options = $options;
        $this->user = $user;
        $this->password = $password;
    }

    public static function fromArray(array $dsn): self
    {
        foreach (['type', 'scheme', 'host'] as $key) {
            if (!isset($dsn[$key])) {
                throw new InvalidArgumentException(
                    'Exporter DSN array must have entry: ' . $key
                );
            }
        }

        return new self(
            $dsn['type'],
            $dsn['scheme'],
            $dsn['host'],
            $dsn['path'] ?? null,
            $dsn['port'] ?? null,
            $dsn['options'] ?? [],
            $dsn['user'] ?? null,
            $dsn['password'] ?? null,
        );
    }

    public function __toString(): string
    {
        $dsn = sprintf(
            '%s+%s',
            $this->getType(),
            $this->getEndpoint()
        );
        $dsn .= !empty($this->getOptions()) ? '?' . http_build_query($this->getOptions()) : '';

        return $dsn;
    }

    /**
     * Returns the endpoint (DSN without type and options)
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        $dsn = sprintf(
            '%s://',
            $this->getScheme()
        );
        $dsn .= $this->getUser() !== null && $this->getPassword() !== null ? sprintf(
            '%s:%s@',
            (string) $this->getUser(),
            (string) $this->getPassword()
        ) : '';
        $dsn .= $this->getHost();
        $dsn .= $this->getPort() !== null ? sprintf(
            ':%s',
            (string) $this->getPort(),
        ) : '';
        $dsn .= $this->getPath() ?? '';

        return $dsn;
    }

    public function asConfigArray(): array
    {
        return [
            'type' => $this->getType(),
            'url' => $this->getEndpoint(),
            'options' => $this->getOptions(),
        ];
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return string|null
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * @return int|null
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return string|null
     */
    public function getUser(): ?string
    {
        return $this->user;
    }

    /**
     * @return string|null
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }
}
