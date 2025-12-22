<?php

require_once __DIR__ . '/../src/Database.php';

use App\Database;

$db = new Database();
$pdo = $db->getConnection();

// Basic Create
try {
    $stmt = $pdo->prepare("INSERT INTO robots (name, type, battery_level) VALUES (?, ?, ?)");
    $stmt->execute(['MediBot-01', 'healthcare', 95]);
    echo "Robot inserted successfully!<br>";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

// Basic Read
$stmt = $pdo->query("SELECT * FROM robots");
$robots = $stmt->fetchAll();

echo "<h2>Robots List:</h2>";
echo "<ul>";
foreach ($robots as $robot) {
    echo "<li>{$robot['name']} ({$robot['type']}) - Battery: {$robot['battery_level']}%</li>";
}
echo "</ul>";
