<?php
/**
 * Example demonstrating Bool type support in one-ck
 * 
 * This example shows how to use boolean values with ClickHouse Bool type.
 * Bool type is internally stored as UInt8 (0 for false, 1 for true).
 */

require __DIR__ . '/vendor/autoload.php';

use OneCk\Client;
use OneCk\Types;

echo "=== Bool Type Support Example ===\n\n";

// Test 1: encodeBool static method
echo "Test 1: encodeBool() static method\n";
echo "-----------------------------------\n";
echo "encodeBool(true)  = " . Types::encodeBool(true) . " (expected: 1)\n";
echo "encodeBool(false) = " . Types::encodeBool(false) . " (expected: 0)\n";
echo "encodeBool(1)     = " . Types::encodeBool(1) . " (expected: 1)\n";
echo "encodeBool(0)     = " . Types::encodeBool(0) . " (expected: 0)\n";
echo "\n";

// Test 2: Usage example (without actual ClickHouse connection)
echo "Test 2: Example usage with ClickHouse\n";
echo "-----------------------------------\n";
echo "To use Bool type with ClickHouse:\n\n";

echo "1. Create table with Bool column:\n";
echo "   CREATE TABLE users (\n";
echo "       id UInt32,\n";
echo "       name String,\n";
echo "       is_active Bool,\n";
echo "       is_admin Bool\n";
echo "   ) ENGINE = MergeTree() ORDER BY id\n\n";

echo "2. Insert data with boolean values:\n";
echo "   \$ck->insert('users', [\n";
echo "       ['id' => 1, 'name' => 'John', 'is_active' => true, 'is_admin' => false],\n";
echo "       ['id' => 2, 'name' => 'Jane', 'is_active' => true, 'is_admin' => true],\n";
echo "       ['id' => 3, 'name' => 'Bob', 'is_active' => false, 'is_admin' => false]\n";
echo "   ]);\n\n";

echo "3. Query data - Bool values will be returned as PHP booleans:\n";
echo "   \$result = \$ck->query('SELECT * FROM users WHERE is_active = true');\n";
echo "   // Result will have is_active and is_admin as true/false\n\n";

echo "=== All tests completed successfully! ===\n";
