<?php
// create_team.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get JSON data
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || !isset($data['name']) || !isset($data['owner_id'])) {
        throw new Exception('Missing required fields');
    }

    // Handle image upload
    $image_url = null;
    if (isset($data['image']) && !empty($data['image'])) {
        // Create uploads directory if it doesn't exist
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/teams/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Process base64 image
        $image_parts = explode(";base64,", $data['image']);
        $image_type_aux = explode("image/", $image_parts[0]);
        $image_type = $image_type_aux[1];
        $image_base64 = base64_decode($image_parts[1]);

        // Generate unique filename
        $file_name = uniqid() . '.' . $image_type;
        $file_path = $upload_dir . $file_name;

        // Save image
        if (file_put_contents($file_path, $image_base64)) {
            $image_url = '/uploads/teams/' . $file_name;
        }
    }

    // Start transaction
    $pdo->beginTransaction();

    try {
        // Insert team
        $stmt = $pdo->prepare("
            INSERT INTO teams (
                owner_id,
                name,
                description,
                image,
                privacy_level,
                division,
                total_members,
                active_members,
                average_rank,
                created_at,
                updated_at
            ) VALUES (
                :owner_id,
                :name,
                :description,
                :image,
                :privacy_level,
                :division,
                1,
                1,
                :average_rank,
                NOW(),
                NOW()
            )
        ");

        $result = $stmt->execute([
            ':owner_id' => $data['owner_id'],
            ':name' => $data['name'],
            ':description' => $data['description'] ?? '',
            ':image' => $image_url,
            ':privacy_level' => $data['privacy'] ?? 'Public',
            ':division' => $data['requirements']['minRank'] ?? 'any',
            ':average_rank' => $data['requirements']['minRank'] ?? 'any'
        ]);

        $teamId = $pdo->lastInsertId();

        // Add owner as team member
        $memberStmt = $pdo->prepare("
            INSERT INTO team_members (
                team_id,
                name,
                role,
                rank,
                status,
                is_active,
                created_at
            ) VALUES (
                :team_id,
                :name,
                :role,
                :rank,
                'online',
                1,
                NOW()
            )
        ");

        $memberStmt->execute([
            ':team_id' => $teamId,
            ':name' => $data['owner_name'],
            ':role' => $data['requirements']['role'] ?? 'Mid',
            ':rank' => $data['requirements']['minRank'] ?? 'any'
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Team created successfully',
            'team_id' => $teamId,
            'image_url' => $image_url
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}