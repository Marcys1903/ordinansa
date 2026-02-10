<?php
// ajax/mark_notification_read.php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$notification_id = $_GET['id'] ?? null;
if (!$notification_id) {
    echo json_encode(['success' => false, 'error' => 'Missing notification ID']);
    exit();
}

$database = new Database();
$conn = $database->getConnection();

$query = "UPDATE status_notifications SET is_read = 1, read_at = NOW() WHERE id = :id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $notification_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Update failed']);
}