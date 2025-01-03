<?php
// File: api/dashboard-stats.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Load database configuration
$db_config = require 'db_config.php';

// Establish database connection
try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

try {
    // Fetch total users
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $totalUsers = $stmt->fetchColumn();

    // Fetch total tournaments
    $stmt = $pdo->query("SELECT COUNT(*) FROM tournaments");
    $totalTournaments = $stmt->fetchColumn();

    // Fetch recent logins (last 30 days)
    $stmt = $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Connexion réussie' AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $recentLogins = $stmt->fetchColumn();

    // Fetch upcoming tournaments
    $stmt = $pdo->query("SELECT COUNT(*) FROM tournaments WHERE status = 'Ouvert aux inscriptions'");
    $upcomingTournaments = $stmt->fetchColumn();

    // Fetch new users (last 30 days)
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $newUsers = $stmt->fetchColumn();

    // Fetch total admins
    $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
    $totalAdmins = $stmt->fetchColumn();

    // Calculate average tournament duration
    $stmt = $pdo->query("SELECT AVG(DATEDIFF(end_date, start_date)) FROM tournaments");
    $avgTournamentDuration = round($stmt->fetchColumn(), 1);

    // Calculate total prize pool
    $stmt = $pdo->query("SELECT SUM(prize_pool) FROM tournaments");
    $totalPrizePool = $stmt->fetchColumn();

    // Fetch tournament status distribution
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM tournaments GROUP BY status");
    $tournamentStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch user type distribution
    $stmt = $pdo->query("SELECT type, COUNT(*) as count FROM users GROUP BY type");
    $userTypes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch login activity for the last 5 days
    $stmt = $pdo->query("
        SELECT DATE(timestamp) as date, COUNT(*) as count 
        FROM activity_log 
        WHERE action = 'Connexion réussie' 
        AND timestamp >= DATE_SUB(NOW(), INTERVAL 5 DAY) 
        GROUP BY DATE(timestamp) 
        ORDER BY date DESC
    ");
    $loginActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'totalUsers' => $totalUsers,
        'totalTournaments' => $totalTournaments,
        'recentLogins' => $recentLogins,
        'upcomingTournaments' => $upcomingTournaments,
        'newUsers' => $newUsers,
        'totalAdmins' => $totalAdmins,
        'avgTournamentDuration' => $avgTournamentDuration,
        'totalPrizePool' => $totalPrizePool,
        'tournamentStatus' => [
            $tournamentStatus['Ouvert aux inscriptions'] ?? 0,
            $tournamentStatus['En cours'] ?? 0,
            $tournamentStatus['Terminé'] ?? 0,
            $tournamentStatus['Annulé'] ?? 0
        ],
        'userTypes' => [
            $userTypes['participant'] ?? 0,
            $userTypes['viewer'] ?? 0,
            $userTypes['admin'] ?? 0
        ],
        'loginActivity' => $loginActivity
    ];

    echo json_encode(['success' => true, 'data' => $response]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch dashboard statistics']);
}