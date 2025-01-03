<?php
// get_teams.php

// CORS headers
header('Access-Control-Allow-Origin: *'); // Your Next.js app URL
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

    // Get teams with owner info
    $teamsQuery = "
        SELECT 
            t.*,
            u.username as owner_username,
            u.avatar as owner_avatar,
            COUNT(DISTINCT tm.id) as total_members
        FROM teams t
        LEFT JOIN users u ON t.owner_id = u.id
        LEFT JOIN team_members tm ON t.id = tm.team_id
        GROUP BY t.id
        ORDER BY t.created_at DESC
    ";
    
    $stmt = $pdo->prepare($teamsQuery);
    $stmt->execute();
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get members for each team
    foreach ($teams as &$team) {
        // Get team members
        $membersQuery = "
            SELECT 
                tm.*,
                u.username,
                u.avatar,
                u.id as user_id
            FROM team_members tm
            LEFT JOIN users u ON tm.name = u.username
            WHERE tm.team_id = ?
        ";
        $memberStmt = $pdo->prepare($membersQuery);
        $memberStmt->execute([$team['id']]);
        $team['members'] = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get pending join requests
        $requestsQuery = "
            SELECT 
                tjr.*,
                u.username,
                u.avatar
            FROM team_join_requests tjr
            LEFT JOIN users u ON tjr.name = u.username
            WHERE tjr.team_id = ? AND tjr.status = 'pending'
        ";
        $requestStmt = $pdo->prepare($requestsQuery);
        $requestStmt->execute([$team['id']]);
        $team['join_requests'] = $requestStmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate active members
        $team['active_members'] = count(array_filter($team['members'], function($member) {
            return $member['is_active'] == 1;
        }));
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'data' => $teams
    ]);

} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('General error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}