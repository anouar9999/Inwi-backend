<?php
// team_api.php

// Enable error reporting for development
ini_set('display_errors', 1);
error_reporting(E_ALL);

// CORS Headers
// CORS headers - EXACT same as get_teams.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
$db_config = require 'db_config.php';
// Response helper function
function sendResponse($success, $data = null, $message = null, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit();
}

// Main API logic
try {
    // Connect to database
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get endpoint from query parameter
    $endpoint = $_GET['endpoint'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    // Get request body for POST/PUT requests
    $requestBody = null;
    if ($method === 'POST' || $method === 'PUT') {
        $requestBody = json_decode(file_get_contents('php://input'), true);
    }

    // Main routing logic
    switch ($endpoint) {
        case 'team-stats':
            if ($method !== 'GET') {
                sendResponse(false, null, 'Method not allowed', 405);
            }
            handleGetTeamStats($pdo);
            break;

        case 'team-members':
            handleTeamMembers($pdo, $method, $requestBody);
            break;

        case 'team-requests':
            handleTeamRequests($pdo, $method, $requestBody);
            break;

        case 'team-settings':
            handleTeamSettings($pdo, $method, $requestBody);
            break;
        case 'join-request':
            handleJoinRequest($pdo, $method, $requestBody);
            break;

        default:
            sendResponse(false, null, 'Endpoint not found', 404);
    }
} catch (PDOException $e) {
    sendResponse(false, null, 'Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    sendResponse(false, null, $e->getMessage(), 500);
}

// Team Stats Handler
function handleGetTeamStats($pdo) {
    $teamId = $_GET['team_id'] ?? null;
    if (!$teamId) {
        sendResponse(false, null, 'Team ID is required', 400);
    }

    try {
        $query = "
            SELECT 
                t.*,
                COUNT(DISTINCT tm.id) as total_members,
                SUM(CASE WHEN tm.is_active = 1 THEN 1 ELSE 0 END) as active_members,
                COUNT(DISTINCT tjr.id) as pending_requests
            FROM teams t
            LEFT JOIN team_members tm ON t.id = tm.team_id
            LEFT JOIN team_join_requests tjr ON t.id = tjr.team_id AND tjr.status = 'pending'
            WHERE t.id = ?
            GROUP BY t.id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$teamId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        sendResponse(true, $stats);
    } catch (Exception $e) {
        sendResponse(false, null, $e->getMessage(), 500);
    }
}

// Team Members Handler
function handleTeamMembers($pdo, $method, $requestBody = null) {
    switch ($method) {
        case 'GET':
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                sendResponse(false, null, 'Team ID is required', 400);
            }

            $query = "
                SELECT 
                    tm.*,
                    u.username,
                    u.avatar,
                    u.id as user_id
                FROM team_members tm
                LEFT JOIN users u ON tm.name = u.username
                WHERE tm.team_id = ?
                ORDER BY tm.created_at DESC
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([$teamId]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            sendResponse(true, $members);
            break;

        case 'POST':
            if (!isset($requestBody['team_id'], $requestBody['name'], $requestBody['role'], $requestBody['rank'])) {
                sendResponse(false, null, 'Missing required fields', 400);
            }

            $query = "
                INSERT INTO team_members (team_id, name, role, rank, status)
                VALUES (?, ?, ?, ?, 'online')
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $requestBody['team_id'],
                $requestBody['name'],
                $requestBody['role'],
                $requestBody['rank']
            ]);
            
            sendResponse(true, null, 'Member added successfully');
            break;

        case 'DELETE':
            $memberId = $_GET['member_id'] ?? null;
            if (!$memberId) {
                sendResponse(false, null, 'Member ID is required', 400);
            }

            $query = "DELETE FROM team_members WHERE id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$memberId]);
            
            sendResponse(true, null, 'Member removed successfully');
            break;

        default:
            sendResponse(false, null, 'Method not allowed', 405);
    }
}

// Team Requests Handler
function handleTeamRequests($pdo, $method, $requestBody = null) {
    switch ($method) {
        case 'GET':
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                sendResponse(false, null, 'Team ID is required', 400);
            }

            $query = "
                SELECT 
                    tjr.*,
                    u.username,
                    u.avatar
                FROM team_join_requests tjr
                LEFT JOIN users u ON tjr.name = u.username
                WHERE tjr.team_id = ? AND tjr.status = 'pending'
                ORDER BY tjr.created_at DESC
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([$teamId]);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            sendResponse(true, $requests);
            break;

        case 'POST':
            if (!isset($requestBody['request_id'], $requestBody['action'])) {
                sendResponse(false, null, 'Missing required fields', 400);
            }

            $pdo->beginTransaction();
            try {
                if ($requestBody['action'] === 'accepted') {
                    // Get request details
                    $requestQuery = "SELECT * FROM team_join_requests WHERE id = ?";
                    $requestStmt = $pdo->prepare($requestQuery);
                    $requestStmt->execute([$requestBody['request_id']]);
                    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Add to team members
                    $addMemberQuery = "
                        INSERT INTO team_members (team_id, name, role, rank, status)
                        VALUES (?, ?, ?, ?, 'online')
                    ";
                    $addMemberStmt = $pdo->prepare($addMemberQuery);
                    $addMemberStmt->execute([
                        $request['team_id'],
                        $request['name'],
                        $request['role'],
                        $request['rank']
                    ]);
                }
                
                // Update request status
                $status = $requestBody['action'] === 'accepted' ? 'accepted' : 'rejected';
                $updateQuery = "UPDATE team_join_requests SET status = ? WHERE id = ?";
                $updateStmt = $pdo->prepare($updateQuery);
                $updateStmt->execute([$status, $requestBody['request_id']]);
                
                $pdo->commit();
                sendResponse(true, null, 'Request ' . $status . ' successfully');
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            sendResponse(false, null, 'Method not allowed', 405);
    }
}

// Team Settings Handler
function handleTeamSettings($pdo, $method, $requestBody = null) {
    switch ($method) {
   
            case 'GET':
                $teamId = $_GET['team_id'] ?? null;
                if (!$teamId) {
                    sendResponse(false, null, 'Team ID is required', 400);
                }
    
                $query = "
                    SELECT 
                        t.*,
                        u.username as owner_username,
                        u.avatar as owner_avatar
                    FROM teams t
                    LEFT JOIN users u ON t.owner_id = u.id
                    WHERE t.id = ?
                ";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([$teamId]);
                $settings = $stmt->fetch(PDO::FETCH_ASSOC);
                
                sendResponse(true, $settings);
                break;
    
            case 'PUT':
                if (!isset($requestBody['team_id'])) {
                    sendResponse(false, null, 'Team ID is required', 400);
                }
    
                $allowedFields = [
                    'name', 
                    'description', 
                    'privacy_level', 
                    'division', 
                    'average_rank',
                    'team_game'  // Add team_game to allowed fields
                ];
                
                $updates = array_filter($requestBody, function($key) use ($allowedFields) {
                    return in_array($key, $allowedFields);
                }, ARRAY_FILTER_USE_KEY);
                
                if (empty($updates)) {
                    sendResponse(false, null, 'No valid fields to update', 400);
                }
    
                // Validate team_game if it's being updated
                if (isset($updates['team_game']) && !in_array($updates['team_game'], ['Valorant', 'Free Fire'])) {
                    sendResponse(false, null, 'Invalid team game value', 400);
                }
    
                $setClauses = array_map(function($field) {
                    return "{$field} = ?";
                }, array_keys($updates));
                
                $query = "UPDATE teams SET " . implode(', ', $setClauses) . " WHERE id = ?";
                $params = array_merge(array_values($updates), [$requestBody['team_id']]);
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                
                sendResponse(true, null, 'Settings updated successfully');
                break;
        case 'DELETE':
            $teamId = $_GET['team_id'] ?? null;
            if (!$teamId) {
                sendResponse(false, null, 'Team ID is required', 400);
            }

            $pdo->beginTransaction();
            try {
                // Delete team members
                $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = ?");
                $stmt->execute([$teamId]);
                
                // Delete join requests
                $stmt = $pdo->prepare("DELETE FROM team_join_requests WHERE team_id = ?");
                $stmt->execute([$teamId]);
                
                // Delete team
                $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
                $stmt->execute([$teamId]);
                
                $pdo->commit();
                sendResponse(true, null, 'Team deleted successfully');
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            sendResponse(false, null, 'Method not allowed', 405);
    }
}
function handleJoinRequest($pdo, $method, $requestBody = null) {
    if ($method !== 'POST') {
        sendResponse(false, null, 'Method not allowed', 405);
    }

    // Validate required fields
    if (!isset($requestBody['team_id'], $requestBody['user_id'])) {
        sendResponse(false, null, 'Missing required fields', 400);
    }

    try {
        // First check if user already has a pending request
        $checkQuery = "
            SELECT id FROM team_join_requests 
            WHERE team_id = ? AND name = (SELECT username FROM users WHERE id = ?) 
            AND status = 'pending'
        ";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$requestBody['team_id'], $requestBody['user_id']]);
        
        if ($checkStmt->rowCount() > 0) {
            sendResponse(false, null, 'You already have a pending request for this team', 400);
        }

        // Check if user is already a member
        $memberCheckQuery = "
            SELECT id FROM team_members 
            WHERE team_id = ? AND name = (SELECT username FROM users WHERE id = ?)
        ";
        $memberCheckStmt = $pdo->prepare($memberCheckQuery);
        $memberCheckStmt->execute([$requestBody['team_id'], $requestBody['user_id']]);
        
        if ($memberCheckStmt->rowCount() > 0) {
            sendResponse(false, null, 'You are already a member of this team', 400);
        }

        // Get user details
        $userQuery = "SELECT username, bio as experience FROM users WHERE id = ?";
        $userStmt = $pdo->prepare($userQuery);
        $userStmt->execute([$requestBody['user_id']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            sendResponse(false, null, 'User not found', 404);
        }

        // Insert join request
        $insertQuery = "
            INSERT INTO team_join_requests (
                team_id, 
                name, 
                role, 
                rank, 
                experience,
                status, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ";
        
        $stmt = $pdo->prepare($insertQuery);
        $stmt->execute([
            $requestBody['team_id'],
            $user['username'],
            $requestBody['role'] ?? 'Mid',  // Default role if not provided
            $requestBody['rank'] ?? 'Unranked',  // Default rank if not provided
            $user['experience'] ?? 'No experience listed'
        ]);

        sendResponse(true, null, 'Join request sent successfully');
    } catch (Exception $e) {
        sendResponse(false, null, 'Failed to send join request: ' . $e->getMessage(), 500);
    }
}
?>