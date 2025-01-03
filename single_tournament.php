<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $db_config = require 'db_config.php';

    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if (!isset($_GET['slug'])) {
        throw new Exception('Slug parameter is required');
    }

    $slug = $_GET['slug'];

    $query = "
        SELECT t.*,
            COALESCE((
                SELECT COUNT(*) 
                FROM tournament_registrations tr
                WHERE tr.tournament_id = t.id 
                AND tr.status IN ('pending', 'accepted')
                AND (
                    CASE 
                        WHEN t.participation_type = 'team' THEN tr.team_id IS NOT NULL
                        ELSE tr.user_id IS NOT NULL
                    END
                )
            ), 0) as registered_count
        FROM tournaments t 
        WHERE t.slug = :slug
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':slug' => $slug]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        throw new Exception('Tournament not found');
    }

    // Calculate spots based on participation type
    $tournament['max_spots'] = (int)$tournament['nombre_maximum'];
    
    // Set spots remaining
    $tournament['spots_remaining'] = max(0, $tournament['max_spots'] - (int)$tournament['registered_count']);
    
    // Ensure registered_count is an integer
    $tournament['registered_count'] = (int)$tournament['registered_count'];

    echo json_encode([
        'success' => true,
        'tournament' => $tournament
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>