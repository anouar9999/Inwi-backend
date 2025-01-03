<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to safely encode JSON
function safe_json_encode($data)
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo safe_json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

$db_config = require 'db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4;port={$db_config['port']}",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get form data
    $data = $_POST;

    // Validate required fields
    $required_fields = [
        'nom_des_qualifications',
        'competition_type',
        'participation_type',
        'nombre_maximum',
        'start_date',
        'end_date',
        'status'
    ];

    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            throw new Exception("Le champ {$field} est obligatoire");
        }
    }

    // Validate enums
    $valid_competition_types = ['Valorant', 'Free Fire'];
    if (!in_array($data['competition_type'], $valid_competition_types)) {
        throw new Exception("Type de compétition invalide. Doit être 'Valorant' ou 'Free Fire'");
    }

    $valid_participation_types = ['participant', 'team'];
    if (!in_array($data['participation_type'], $valid_participation_types)) {
        throw new Exception("Type de participation invalide. Doit être 'participant' ou 'team'");
    }

    $valid_status = ['Ouvert aux inscriptions', 'En cours', 'Terminé', 'Annulé'];
    if (!in_array($data['status'], $valid_status)) {
        throw new Exception("Statut invalide");
    }

    // Validate maximum number
    if (!is_numeric($data['nombre_maximum'])) {
        throw new Exception("Le nombre maximum doit être un nombre");
    }
    
    if ($data['nombre_maximum'] < 2) {
        $type = $data['participation_type'] === 'team' ? "d'équipes" : "de participants";
        throw new Exception("Le nombre minimum $type doit être de 2");
    }

    // Validate dates
    $start_date = new DateTime($data['start_date']);
    $end_date = new DateTime($data['end_date']);
    if ($end_date < $start_date) {
        throw new Exception("La date de fin doit être postérieure à la date de début");
    }

    // Generate slug from nom_des_qualifications
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['nom_des_qualifications'])));

    // Handle file upload
    $image_url = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/';
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Impossible de créer le répertoire d'upload");
            }
        }

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['image']['type'];
        if (!in_array($file_type, $allowed_types)) {
            throw new Exception("Type de fichier invalide. Seuls JPEG, PNG et GIF sont autorisés");
        }

        $file_name = uniqid() . '_' . basename($_FILES['image']['name']);
        $upload_path = $upload_dir . $file_name;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
            throw new Exception("Échec de l'upload de l'image");
        }

        $image_url = '/uploads/' . $file_name;
    }

    // Prepare SQL statement
    $sql = "INSERT INTO tournaments (
        nom_des_qualifications,
        competition_type,
        participation_type,
        nombre_maximum,
        slug,
        start_date,
        end_date,
        status,
        description_des_qualifications,
        rules,
        prize_pool,
        format_des_qualifications,
        type_de_match,
        type_de_jeu,
        image
    ) VALUES (
        :nom,
        :competition_type,
        :participation_type,
        :nombre_maximum,
        :slug,
        :start_date,
        :end_date,
        :status,
        :description,
        :rules,
        :prize_pool,
        :format,
        :match_type,
        :game_type,
        :image
    )";

    $stmt = $pdo->prepare($sql);

    // Format numeric values
    $nombre_maximum = filter_var($data['nombre_maximum'], FILTER_VALIDATE_INT);
    $prize_pool = isset($data['prize_pool']) ? 
        filter_var($data['prize_pool'], FILTER_VALIDATE_FLOAT) : 0.00;

    // Execute statement with data
    $stmt->execute([
        ':nom' => $data['nom_des_qualifications'],
        ':competition_type' => $data['competition_type'],
        ':participation_type' => $data['participation_type'],
        ':nombre_maximum' => $nombre_maximum,
        ':slug' => $slug,
        ':start_date' => $data['start_date'],
        ':end_date' => $data['end_date'],
        ':status' => $data['status'],
        ':description' => $data['description_des_qualifications'] ?? null,
        ':rules' => $data['rules'] ?? null,
        ':prize_pool' => $prize_pool,
        ':format' => $data['format_des_qualifications'] ?? 'Single Elimination',
        ':match_type' => $data['type_de_match'] ?? null,
        ':game_type' => $data['type_de_jeu'] ?? null,
        ':image' => $image_url
    ]);

    $newTournamentId = $pdo->lastInsertId();

    // Return success response
    echo safe_json_encode([
        'success' => true,
        'message' => 'Tournoi créé avec succès',
        'tournament_id' => $newTournamentId,
        'data' => [
            'slug' => $slug,
            'image_url' => $image_url,
            'participation_type' => $data['participation_type'],
            'competition_type' => $data['competition_type'],
            'nombre_maximum' => $nombre_maximum
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo safe_json_encode([
        'success' => false,
        'message' => 'Erreur de base de données: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo safe_json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}