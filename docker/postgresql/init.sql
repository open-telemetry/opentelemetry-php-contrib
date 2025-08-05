
CREATE DATABASE otel_db2;

\connect otel_db2;

CREATE USER otel_user2 WITH PASSWORD 'otel_passwd';

GRANT ALL PRIVILEGES ON DATABASE otel_db2 TO otel_user2;

\connect otel_db;

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (name, email) VALUES
('John Doe', 'john.doe@example.com'),
('Jane Smith', 'jane.smith@example.com'),
('Bob Johnson', 'bob.johnson@example.com');

CREATE TABLE products (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price NUMERIC(10, 2) NOT NULL,
    stock INT NOT NULL DEFAULT 0
);

INSERT INTO products (name, price, stock) VALUES
('Laptop', 999.99, 10),
('Smartphone', 499.99, 25),
('Headphones', 49.99, 50);