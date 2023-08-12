<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\MongoDB;

interface MongoDBTraceAttributes
{
    public const DB_MONGODB_MASTER = 'db.mongodb.master';
    public const DB_MONGODB_READ_ONLY = 'db.mongodb.read_only';
    public const DB_MONGODB_CONNECTION_ID = 'db.mongodb.connection_id';
    public const DB_MONGODB_REQUEST_ID = 'db.mongodb.request_id';
    public const DB_MONGODB_OPERATION_ID = 'db.mongodb.operation_id';
    public const DB_MONGODB_MAX_WIRE_VERSION = 'db.mongodb.max_wire_version';
    public const DB_MONGODB_MIN_WIRE_VERSION = 'db.mongodb.min_wire_version';
    public const DB_MONGODB_MAX_WRITE_BATCH_SIZE = 'db.mongodb.max_write_batch_size';
    public const DB_MONGODB_MAX_BSON_OBJECT_SIZE_BYTES = 'db.mongodb.max_bson_object_size_bytes';
    public const DB_MONGODB_MAX_MESSAGE_SIZE_BYTES = 'db.mongodb.max_message_size_bytes';
}
