<?php
// edit_tournament.php

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// Include database configuration
$db_config = require 'db_config.php';

try {
    // Connect to the database
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get JSON data from the request body
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['id'])) {
        throw new Exception('Missing tournament ID');
    }

    $tournament_id = $data['id'];

    // Prepare the update query
    $updateFields = [];
    $params = [':id' => $tournament_id];

    // List of allowed fields to update
    $allowedFields = [
        'nom_des_qualifications', 'slug', 'start_date', 'end_date', 'status',
        'description_des_qualifications', 'nombre_maximum',
        'prize_pool', 'format_des_qualifications', 'type_de_match',
        'type_de_jeu', 'image','rules'
    ];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateFields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }

    // If no fields to update, throw an exception
    if (empty($updateFields)) {
        throw new Exception('No fields to update');
    }

    // Construct the SQL query
    $sql = "UPDATE tournaments SET " . implode(', ', $updateFields) . " WHERE id = :id";

    // Prepare and execute the query
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Tournament updated successfully'
        ]);
    } else {
        throw new Exception('Failed to update tournament');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>