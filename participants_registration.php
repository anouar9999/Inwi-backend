<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $tournament_id = isset($_GET['tournament_id']) ? $_GET['tournament_id'] : null;

    if (!$tournament_id) {
        throw new Exception('Tournament ID parameter is required');
    }

    // First get tournament type
    $stmt = $pdo->prepare("
        SELECT participation_type, competition_type
        FROM tournaments 
        WHERE id = :tournament_id
    ");
    $stmt->execute([':tournament_id' => $tournament_id]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tournament) {
        throw new Exception('Tournament not found');
    }

    if ($tournament['participation_type'] === 'team') {
        // Fetch team registrations
        $query = "
            SELECT 
                tr.id,
                tr.tournament_id,
                tr.team_id,
                tr.registration_date,
                tr.status,
                t.name as team_name,
                t.team_game,
                t.image as team_image,
                t.description,
                t.mmr,
                t.win_rate,
                u.username as owner_name,
                u.email as owner_email,
                u.avatar as owner_avatar,
                (SELECT COUNT(*) FROM team_members WHERE team_id = t.id AND is_active = 1) as member_count
            FROM tournament_registrations tr
            JOIN teams t ON tr.team_id = t.id
            JOIN users u ON t.owner_id = u.id
            WHERE tr.tournament_id = :tournament_id
            ORDER BY tr.registration_date DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute([':tournament_id' => $tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch team members for each team
        foreach ($participants as &$participant) {
            $memberQuery = "
                SELECT 
                    tm.name,
                    tm.role,
                    tm.rank,
                    tm.avatar_url as avatar,
                    tm.status as member_status,
                    CASE WHEN tm.team_id IN (
                        SELECT id FROM teams WHERE owner_id = t.owner_id
                    ) THEN 'Captain' ELSE 'Member' END as position
                FROM team_members tm
                JOIN teams t ON tm.team_id = t.id
                WHERE tm.team_id = :team_id 
                AND tm.is_active = 1
                ORDER BY position DESC, name ASC";
            
            $memberStmt = $pdo->prepare($memberQuery);
            $memberStmt->execute([':team_id' => $participant['team_id']]);
            $participant['members'] = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // Fetch individual registrations
        $query = "
            SELECT 
                tr.id,
                tr.tournament_id,
                tr.user_id,
                tr.registration_date,
                tr.status,
                u.username as name,
                u.email,
                u.avatar,
                u.bio,
                u.points,
                u.rank
            FROM tournament_registrations tr
            JOIN users u ON tr.user_id = u.id
            WHERE tr.tournament_id = :tournament_id
            ORDER BY tr.registration_date DESC";
            
        $stmt = $pdo->prepare($query);
        $stmt->execute([':tournament_id' => $tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'tournament_type' => $tournament['participation_type'],
        'game_type' => $tournament['competition_type'],
        'profiles' => $participants,
        'total_count' => count($participants),
        'message' => count($participants) > 0 ? null : 'No registrations found for this tournament'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>