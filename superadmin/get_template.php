<?php
// get_template.php - AJAX endpoint for template data
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('HTTP/1.1 401 Unauthorized');
    exit(json_encode(['error' => 'Unauthorized']));
}

if (!in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    exit(json_encode(['error' => 'Forbidden']));
}

if (!isset($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    exit(json_encode(['error' => 'Template ID required']));
}

$database = new Database();
$conn = $database->getConnection();

$template_id = $_GET['id'];
$query = "SELECT * FROM document_templates WHERE id = :id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $template_id);
$stmt->execute();

$template = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$template) {
    header('HTTP/1.1 404 Not Found');
    exit(json_encode(['error' => 'Template not found']));
}

header('Content-Type: application/json');
echo json_encode($template);
?>