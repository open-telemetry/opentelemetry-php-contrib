CREATE DATABASE IF NOT EXISTS otel_db2;
CREATE USER 'otel_user2'@'%' IDENTIFIED BY 'otel_passwd';


GRANT ALL PRIVILEGES ON *.* TO 'otel_user'@'%';
GRANT ALL PRIVILEGES ON *.* TO 'otel_user2'@'%';
FLUSH PRIVILEGES;


USE otel_db;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (name, email) VALUES
('John Doe', 'john.doe@example.com'),
('Jane Smith', 'jane.smith@example.com'),
('Bob Johnson', 'bob.johnson@example.com');

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    stock INT NOT NULL DEFAULT 0
);

INSERT INTO products (name, price, stock) VALUES
('Laptop', 999.99, 10),
('Smartphone', 499.99, 25),
('Headphones', 49.99, 50);
