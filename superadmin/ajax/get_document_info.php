<?php
// ajax/get_document_info.php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$conn = $database->getConnection();

$document_id = $_GET['id'] ?? null;
$document_type = $_GET['type'] ?? null;

if (!$document_id || !$document_type) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

if ($document_type === 'ordinance') {
    $query = "SELECT o.*, u.first_name, u.last_name, dc.priority_level 
              FROM ordinances o 
              LEFT JOIN users u ON o.created_by = u.id 
              LEFT JOIN document_classification dc ON o.id = dc.document_id AND dc.document_type = 'ordinance'
              WHERE o.id = :id";
} else {
    $query = "SELECT r.*, u.first_name, u.last_name, dc.priority_level 
              FROM resolutions r 
              LEFT JOIN users u ON r.created_by = u.id 
              LEFT JOIN document_classification dc ON r.id = dc.document_id AND dc.document_type = 'resolution'
              WHERE r.id = :id";
}

$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $document_id);
$stmt->execute();
$document = $stmt->fetch();

if ($document) {
    echo json_encode(['success' => true, 'document' => $document]);
} else {
    echo json_encode(['success' => false, 'error' => 'Document not found']);
}