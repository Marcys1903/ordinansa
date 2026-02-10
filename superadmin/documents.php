<?php
// documents.php - Supporting Documents Management Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to manage documents
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'admin', 'councilor'])) {
    header("Location: ../unauthorized.php");
    exit();
}

// Include database configuration
require_once '../config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Get user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$query = "SELECT * FROM users WHERE id = :id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch();

// Handle file upload
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        $description = $_POST['description'] ?? '';
        
        // Handle file upload
        if (!empty($_FILES['document_file']['name'])) {
            $upload_dir = '../uploads/supporting_docs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = basename($_FILES['document_file']['name']);
            $file_tmp = $_FILES['document_file']['tmp_name'];
            $file_size = $_FILES['document_file']['size'];
            $file_type = $_FILES['document_file']['type'];
            
            // Generate unique filename
            $unique_name = time() . '_' . uniqid() . '_' . $file_name;
            $file_path = $upload_dir . $unique_name;
            
            // Validate file
            $valid_types = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/jpeg',
                'image/png',
                'text/plain',
                'application/zip',
                'application/vnd.rar'
            ];
            
            $max_size = 50 * 1024 * 1024; // 50MB
            
            if (!in_array($file_type, $valid_types)) {
                throw new Exception("File type not allowed. Please upload PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, ZIP, or RAR files.");
            }
            
            if ($file_size > $max_size) {
                throw new Exception("File size exceeds maximum limit of 50MB.");
            }
            
            if (move_uploaded_file($file_tmp, $file_path)) {
                // Get document title for audit log
                $doc_query = "SELECT title FROM " . ($document_type === 'ordinance' ? 'ordinances' : 'resolutions') . " WHERE id = :doc_id";
                $doc_stmt = $conn->prepare($doc_query);
                $doc_stmt->bindParam(':doc_id', $document_id);
                $doc_stmt->execute();
                $document = $doc_stmt->fetch();
                $document_title = $document['title'] ?? 'Unknown Document';
                
                // Insert into database
                $file_query = "INSERT INTO supporting_documents (document_id, document_type, file_name, file_path, file_type, file_size, description, uploaded_by) 
                             VALUES (:doc_id, :doc_type, :file_name, :file_path, :file_type, :file_size, :description, :uploaded_by)";
                $stmt = $conn->prepare($file_query);
                $stmt->bindParam(':doc_id', $document_id);
                $stmt->bindParam(':doc_type', $document_type);
                $stmt->bindParam(':file_name', $file_name);
                $stmt->bindParam(':file_path', $file_path);
                $stmt->bindParam(':file_type', $file_type);
                $stmt->bindParam(':file_size', $file_size);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':uploaded_by', $user_id);
                $stmt->execute();
                
                $conn->commit();
                
                // Log the action
                $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                               VALUES (:user_id, 'DOCUMENT_UPLOAD', 'Uploaded supporting document for {$document_type}: {$document_title}', :ip, :agent)";
                $stmt = $conn->prepare($audit_query);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
                $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
                $stmt->execute();
                
                $success_message = "Document uploaded successfully!";
                
                // Clear form
                $_POST = [];
            } else {
                throw new Exception("Failed to upload file. Please try again.");
            }
        } else {
            throw new Exception("Please select a file to upload.");
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Handle document deletion
if (isset($_GET['delete']) && isset($_GET['id'])) {
    try {
        $doc_id = $_GET['id'];
        
        // Get file info before deletion
        $query = "SELECT * FROM supporting_documents WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $doc_id);
        $stmt->execute();
        $document = $stmt->fetch();
        
        if ($document) {
            // Delete file from server
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            
            // Delete from database
            $delete_query = "DELETE FROM supporting_documents WHERE id = :id";
            $stmt = $conn->prepare($delete_query);
            $stmt->bindParam(':id', $doc_id);
            $stmt->execute();
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                           VALUES (:user_id, 'DOCUMENT_DELETE', 'Deleted supporting document: {$document['file_name']}', :ip, :agent)";
            $stmt = $conn->prepare($audit_query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
            $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
            $stmt->execute();
            
            $success_message = "Document deleted successfully!";
        }
        
    } catch (Exception $e) {
        $error_message = "Error deleting document: " . $e->getMessage();
    }
}

// Get all documents with filter options
$filter_type = $_GET['type'] ?? 'all';
$filter_document = $_GET['document'] ?? '';
$filter_date = $_GET['date'] ?? '';
$filter_uploader = $_GET['uploader'] ?? '';

// Build query with filters
$query = "SELECT sd.*, 
                 u.first_name, u.last_name, u.role,
                 o.title as ordinance_title, o.ordinance_number,
                 r.title as resolution_title, r.resolution_number
          FROM supporting_documents sd
          LEFT JOIN users u ON sd.uploaded_by = u.id
          LEFT JOIN ordinances o ON sd.document_id = o.id AND sd.document_type = 'ordinance'
          LEFT JOIN resolutions r ON sd.document_id = r.id AND sd.document_type = 'resolution'
          WHERE 1=1";

$params = [];

if ($filter_type !== 'all') {
    $query .= " AND sd.document_type = :doc_type";
    $params[':doc_type'] = $filter_type;
}

if ($filter_document) {
    $query .= " AND (o.title LIKE :doc_title OR r.title LIKE :doc_title)";
    $params[':doc_title'] = "%$filter_document%";
}

if ($filter_date) {
    $query .= " AND DATE(sd.uploaded_at) = :upload_date";
    $params[':upload_date'] = $filter_date;
}

if ($filter_uploader) {
    $query .= " AND (u.first_name LIKE :uploader OR u.last_name LIKE :uploader)";
    $params[':uploader'] = "%$filter_uploader%";
}

$query .= " ORDER BY sd.uploaded_at DESC";

$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$documents = $stmt->fetchAll();

// Get recent documents for current user
$recent_query = "SELECT sd.*, 
                        o.title as ordinance_title, o.ordinance_number,
                        r.title as resolution_title, r.resolution_number
                 FROM supporting_documents sd
                 LEFT JOIN ordinances o ON sd.document_id = o.id AND sd.document_type = 'ordinance'
                 LEFT JOIN resolutions r ON sd.document_id = r.id AND sd.document_type = 'resolution'
                 WHERE sd.uploaded_by = :user_id
                 ORDER BY sd.uploaded_at DESC
                 LIMIT 10";
$recent_stmt = $conn->prepare($recent_query);
$recent_stmt->bindParam(':user_id', $user_id);
$recent_stmt->execute();
$recent_documents = $recent_stmt->fetchAll();

// Get all ordinances and resolutions for dropdown
$ordinances_query = "SELECT id, ordinance_number as doc_number, title, status FROM ordinances ORDER BY created_at DESC";
$ordinances_stmt = $conn->query($ordinances_query);
$ordinances = $ordinances_stmt->fetchAll();

$resolutions_query = "SELECT id, resolution_number as doc_number, title, status FROM resolutions ORDER BY created_at DESC";
$resolutions_stmt = $conn->query($resolutions_query);
$resolutions = $resolutions_stmt->fetchAll();

// Get storage statistics
$storage_query = "SELECT 
                    COUNT(*) as total_files,
                    SUM(file_size) as total_size,
                    AVG(file_size) as avg_size,
                    document_type,
                    COUNT(CASE WHEN file_type LIKE 'image/%' THEN 1 END) as image_count,
                    COUNT(CASE WHEN file_type LIKE 'application/pdf' THEN 1 END) as pdf_count,
                    COUNT(CASE WHEN file_type LIKE 'application/%word%' THEN 1 END) as word_count,
                    COUNT(CASE WHEN file_type LIKE 'application/%excel%' THEN 1 END) as excel_count
                  FROM supporting_documents
                  GROUP BY document_type";
$storage_stmt = $conn->query($storage_query);
$storage_stats = $storage_stmt->fetchAll();

// Calculate totals
$total_files = 0;
$total_size = 0;
foreach ($storage_stats as $stat) {
    $total_files += $stat['total_files'];
    $total_size += $stat['total_size'];
}

// Format file size
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return number_format($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// Get file icon class
function getFileIcon($file_type) {
    if (strpos($file_type, 'pdf') !== false) return 'fa-file-pdf';
    if (strpos($file_type, 'word') !== false) return 'fa-file-word';
    if (strpos($file_type, 'excel') !== false) return 'fa-file-excel';
    if (strpos($file_type, 'image') !== false) return 'fa-file-image';
    if (strpos($file_type, 'text') !== false) return 'fa-file-alt';
    if (strpos($file_type, 'zip') !== false || strpos($file_type, 'rar') !== false) return 'fa-file-archive';
    return 'fa-file';
}

// Get file color
function getFileColor($file_type) {
    if (strpos($file_type, 'pdf') !== false) return '#ff4444';
    if (strpos($file_type, 'word') !== false) return '#2b579a';
    if (strpos($file_type, 'excel') !== false) return '#217346';
    if (strpos($file_type, 'image') !== false) return '#ff66cc';
    if (strpos($file_type, 'text') !== false) return '#666666';
    return '#999999';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supporting Documents | QC Ordinance Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --qc-blue: #003366;
            --qc-blue-dark: #002244;
            --qc-blue-light: #004488;
            --qc-gold: #D4AF37;
            --qc-gold-dark: #B8941F;
            --qc-green: #2D8C47;
            --qc-green-dark: #1F6C37;
            --white: #FFFFFF;
            --off-white: #F8F9FA;
            --gray-light: #E9ECEF;
            --gray: #6C757D;
            --gray-dark: #343A40;
            --red: #C53030;
            --green: #2D8C47;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.12);
            --shadow-xl: 0 12px 24px rgba(0,0,0,0.15);
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --sidebar-width: 320px;
            --header-height: 180px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            background: var(--off-white);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* MAIN CONTENT WRAPPER */
        .main-wrapper {
            display: flex;
            min-height: calc(100vh - var(--header-height));
            margin-top: var(--header-height);
        }
        
        /* SIDEBAR STYLES */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--qc-blue-dark) 0%, var(--qc-blue) 100%);
            color: var(--white);
            position: fixed;
            top: var(--header-height);
            left: 0;
            height: calc(100vh - var(--header-height));
            z-index: 100;
            overflow-y: auto;
            box-shadow: 2px 0 8px rgba(0,0,0,0.1);
            border-right: 3px solid var(--qc-gold);
            transition: transform 0.3s ease;
        }
        
        .sidebar-content {
            padding: 30px 20px;
            height: 100%;
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-menu li {
            margin-bottom: 5px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            transition: all 0.3s ease;
            font-size: 0.95rem;
            letter-spacing: 0.3px;
            border-left: 3px solid transparent;
        }
        
        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            border-left: 3px solid var(--qc-gold);
            transform: translateX(5px);
        }
        
        .sidebar-menu a.active {
            background: rgba(212, 175, 55, 0.2);
            color: var(--qc-gold);
            border-left: 3px solid var(--qc-gold);
            font-weight: bold;
        }
        
        .sidebar-menu i {
            width: 25px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .sidebar-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sidebar-subtitle {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
        }
        
        /* MAIN CONTENT AREA */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            overflow-y: auto;
            background: var(--off-white);
        }
        
        /* GOVERNMENT HEADER STYLE */
        .government-header {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 15px 0;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: var(--shadow-md);
            border-bottom: 3px solid var(--qc-gold);
            height: var(--header-height);
        }
        
        .header-top-bar {
            background: rgba(0, 0, 0, 0.2);
            padding: 8px 0;
            font-size: 0.85rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
        }
        
        .header-top-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .government-seal {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .seal-icon-mini {
            font-size: 20px;
            color: var(--qc-gold);
        }
        
        .current-date {
            font-weight: 600;
            color: var(--qc-gold);
            background: rgba(0, 0, 0, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }
        
        .main-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            padding: 15px 20px;
        }
        
        .qc-logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .qc-seal-large {
            width: 60px;
            height: 60px;
            border: 3px solid var(--qc-gold);
            border-radius: 50%;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .qc-seal-large::before {
            content: '';
            position: absolute;
            width: 85%;
            height: 85%;
            border: 2px solid var(--qc-blue);
            border-radius: 50%;
        }
        
        .seal-icon-large {
            font-size: 28px;
            color: var(--qc-blue);
            z-index: 2;
        }
        
        .site-title-container h1 {
            font-size: 1.6rem;
            font-weight: bold;
            color: var(--white);
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .site-title-container p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.85rem;
            letter-spacing: 0.3px;
        }
        
        .user-auth-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-welcome-panel {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255, 255, 255, 0.1);
            padding: 10px 20px;
            border-radius: var(--border-radius);
            border: 1px solid rgba(212, 175, 55, 0.3);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--qc-gold);
            color: var(--qc-blue-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        .user-info {
            color: var(--white);
        }
        
        .user-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 3px;
        }
        
        .user-role {
            font-size: 0.8rem;
            color: var(--qc-gold);
            background: rgba(0, 0, 0, 0.2);
            padding: 3px 10px;
            border-radius: 20px;
            display: inline-block;
            text-transform: uppercase;
            font-weight: bold;
        }
        
        .logout-button {
            padding: 8px 20px;
            background: transparent;
            color: var(--white);
            border: 2px solid var(--qc-gold);
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-button:hover {
            background: var(--qc-gold);
            color: var(--qc-blue-dark);
        }
        
        /* MODULE HEADER */
        .module-header {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 40px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-xl);
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
            border: 2px solid var(--qc-gold);
        }
        
        .module-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(212, 175, 55, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(212, 175, 55, 0.1) 0%, transparent 50%),
                repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(212, 175, 55, 0.05) 10px, rgba(212, 175, 55, 0.05) 20px);
        }
        
        .module-header-content {
            position: relative;
            z-index: 2;
        }
        
        .module-badge {
            display: inline-block;
            background: rgba(212, 175, 55, 0.2);
            border: 2px solid var(--qc-gold);
            color: var(--qc-gold);
            padding: 12px 30px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: bold;
            letter-spacing: 1.5px;
            margin-bottom: 25px;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .module-title-wrapper {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .module-icon {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid var(--qc-gold);
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--qc-gold);
        }
        
        .module-title {
            flex: 1;
        }
        
        .module-title h1 {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .module-subtitle {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .module-stats {
            display: flex;
            gap: 30px;
            margin-top: 25px;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .stat-icon {
            background: rgba(212, 175, 55, 0.2);
            border: 1px solid var(--qc-gold);
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--qc-gold);
        }
        
        .stat-info h3 {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--white);
            line-height: 1;
        }
        
        .stat-info p {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
        }
        
        /* ALERTS */
        .alert {
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease-out;
        }
        
        .alert-success {
            background: rgba(45, 140, 71, 0.1);
            border: 1px solid var(--qc-green);
            color: var(--qc-green-dark);
        }
        
        .alert-error {
            background: rgba(197, 48, 48, 0.1);
            border: 1px solid var(--red);
            color: var(--red);
        }
        
        .alert-icon {
            font-size: 1.5rem;
        }
        
        /* UPLOAD FORM */
        .upload-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .upload-form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .upload-form {
                grid-template-columns: 1fr;
            }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.95rem;
        }
        
        .form-label.required::after {
            content: ' *';
            color: var(--red);
        }
        
        .form-control, .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }
        
        /* File Upload Area */
        .file-upload-area {
            border: 2px dashed var(--gray-light);
            border-radius: var(--border-radius);
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            grid-column: span 2;
        }
        
        .file-upload-area:hover {
            border-color: var(--qc-blue);
            background: var(--off-white);
        }
        
        .file-upload-area.dragover {
            border-color: var(--qc-gold);
            background: rgba(212, 175, 55, 0.05);
        }
        
        .upload-icon {
            font-size: 3rem;
            color: var(--qc-blue);
            margin-bottom: 15px;
        }
        
        .upload-text {
            color: var(--gray-dark);
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        
        .upload-subtext {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        
        .file-input-hidden {
            display: none;
        }
        
        /* Form Actions */
        .form-actions {
            grid-column: span 2;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-secondary {
            background: var(--gray-light);
            color: var(--gray-dark);
        }
        
        .btn-secondary:hover {
            background: var(--gray);
            color: var(--white);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%);
            color: var(--white);
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--red) 0%, #9b2c2c 100%);
            color: var(--white);
        }
        
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        /* FILTER BAR */
        .filter-bar {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 30px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-label {
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.9rem;
        }
        
        /* DOCUMENTS TABLE */
        .documents-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .documents-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .documents-table th {
            background: var(--off-white);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--qc-blue);
            border-bottom: 2px solid var(--qc-gold);
            white-space: nowrap;
        }
        
        .documents-table td {
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: middle;
        }
        
        .documents-table tr:hover {
            background: var(--off-white);
        }
        
        /* File Preview */
        .file-preview {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .file-icon-large {
            width: 50px;
            height: 50px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--white);
        }
        
        .file-info {
            flex: 1;
        }
        
        .file-name {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 3px;
            word-break: break-all;
        }
        
        .file-meta {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        /* Document Badge */
        .document-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-ordinance {
            background: rgba(0, 51, 102, 0.1);
            color: var(--qc-blue);
            border: 1px solid rgba(0, 51, 102, 0.2);
        }
        
        .badge-resolution {
            background: rgba(45, 140, 71, 0.1);
            color: var(--qc-green);
            border: 1px solid rgba(45, 140, 71, 0.2);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--white);
        }
        
        .btn-icon-view {
            background: var(--qc-blue);
        }
        
        .btn-icon-download {
            background: var(--qc-green);
        }
        
        .btn-icon-delete {
            background: var(--red);
        }
        
        .btn-icon:hover {
            transform: scale(1.1);
        }
        
        /* STORAGE STATS */
        .storage-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .storage-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-light);
        }
        
        .storage-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .storage-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--qc-blue);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .storage-title {
            font-weight: 600;
            color: var(--gray-dark);
        }
        
        .storage-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 5px;
        }
        
        .storage-label {
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        /* Progress Bar */
        .progress-bar {
            height: 10px;
            background: var(--gray-light);
            border-radius: 5px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--qc-blue) 0%, var(--qc-blue-light) 100%);
            border-radius: 5px;
            transition: width 0.3s ease;
        }
        
        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 20px;
        }
        
        /* MODAL STYLES */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-xl);
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 20px 30px;
            border-top-left-radius: var(--border-radius-lg);
            border-top-right-radius: var(--border-radius-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--qc-gold);
        }
        
        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--white);
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--white);
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .modal-content {
            text-align: center;
        }
        
        .modal-icon {
            font-size: 4rem;
            color: var(--red);
            margin-bottom: 20px;
        }
        
        .modal-message {
            margin-bottom: 25px;
            color: var(--gray-dark);
            line-height: 1.6;
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        
        /* FOOTER */
        .government-footer {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 40px 0 20px;
            margin-top: 60px;
            margin-left: var(--sidebar-width);
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px 30px;
        }
        
        .footer-column h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--white);
            font-weight: 600;
            letter-spacing: 0.5px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background: var(--qc-gold);
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 12px;
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }
        
        .footer-links a:hover {
            color: var(--qc-gold);
            transform: translateX(5px);
        }
        
        .footer-links i {
            width: 20px;
            color: var(--qc-gold);
            font-size: 0.9rem;
        }
        
        .copyright {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            max-width: 1400px;
            margin: 0 auto;
            padding-left: 20px;
            padding-right: 20px;
        }
        
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            padding: 8px 20px;
            border-radius: 20px;
            margin-top: 15px;
            color: var(--qc-gold);
            font-size: 0.8rem;
        }
        
        /* ANIMATIONS */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        .slide-in {
            animation: slideIn 0.4s ease-out;
        }
        
        /* Mobile Responsiveness */
        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .government-footer {
                margin-left: 0;
            }
            
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .mobile-menu-toggle {
                display: block;
                position: fixed;
                top: var(--header-height);
                right: 20px;
                background: var(--qc-gold);
                color: var(--qc-blue-dark);
                border: none;
                padding: 10px 15px;
                border-radius: var(--border-radius);
                cursor: pointer;
                z-index: 1001;
                font-size: 1.2rem;
            }
        }
        
        @media (max-width: 768px) {
            .module-stats {
                flex-direction: column;
                gap: 20px;
            }
            
            .module-title-wrapper {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-icon {
                width: 100%;
                border-radius: var(--border-radius);
                margin-bottom: 5px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Mobile menu toggle button */
        .mobile-menu-toggle {
            display: none;
        }
        
        /* Sidebar overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
    </style>
</head>
<body>
    <!-- Government Header -->
    <header class="government-header">
        <div class="header-top-bar">
            <div class="header-top-content">
                <div class="government-seal">
                    <i class="fas fa-city seal-icon-mini"></i>
                    <span>City Government of Quezon</span>
                </div>
                <div class="current-date" id="currentDate">Loading date...</div>
            </div>
        </div>
        
        <div class="main-header-content">
            <div class="qc-logo-container">
                <div class="qc-seal-large">
                    <i class="fas fa-landmark seal-icon-large"></i>
                </div>
                <div class="site-title-container">
                    <h1>QC Ordinance Tracker System</h1>
                    <p>Supporting Documents Module | File Management & Attachments</p>
                </div>
            </div>
            
            <div class="user-auth-section">
                <div class="user-welcome-panel">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                        <div class="user-role">
                            <?php echo strtoupper(str_replace('_', ' ', $user_role)); ?>
                        </div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-button">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>
    
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-content">
                <div class="sidebar-header">
                    <h3 class="sidebar-title">
                        <i class="fas fa-paperclip"></i> Documents Module
                    </h3>
                    <p class="sidebar-subtitle">Supporting Documents Management</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
            <div class="alert alert-success fade-in">
                <i class="fas fa-check-circle alert-icon"></i>
                <div><?php echo htmlspecialchars($success_message); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="alert alert-error fade-in">
                <i class="fas fa-exclamation-circle alert-icon"></i>
                <div><?php echo htmlspecialchars($error_message); ?></div>
            </div>
            <?php endif; ?>

            <!-- MODULE HEADER -->
            <div class="module-header fade-in">
                <div class="module-header-content">
                    <div class="module-badge">SUPPORTING DOCUMENTS MODULE</div>
                    
                    <div class="module-title-wrapper">
                        <div class="module-icon">
                            <i class="fas fa-paperclip"></i>
                        </div>
                        <div class="module-title">
                            <h1>Supporting Documents Management</h1>
                            <p class="module-subtitle">
                                Upload, manage, and track supporting documents for ordinances and resolutions. 
                                Attach files, view document history, and maintain organized records.
                            </p>
                        </div>
                    </div>
                    
                    <div class="module-stats">
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-file"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $total_files; ?></h3>
                                <p>Total Files</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-database"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo formatFileSize($total_size); ?></h3>
                                <p>Storage Used</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-upload"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo count($recent_documents); ?></h3>
                                <p>Your Uploads</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- UPLOAD FORM -->
            <div class="upload-container fade-in">
                <h2 style="color: var(--qc-blue); margin-bottom: 25px; font-size: 1.5rem;">
                    <i class="fas fa-cloud-upload-alt" style="color: var(--qc-gold); margin-right: 10px;"></i>
                    Upload New Document
                </h2>
                
                <form method="POST" enctype="multipart/form-data" class="upload-form" id="uploadForm">
                    <div class="form-group">
                        <label class="form-label required">Document Type</label>
                        <select name="document_type" class="form-select" required onchange="updateDocumentList()">
                            <option value="">Select Document Type</option>
                            <option value="ordinance">Ordinance</option>
                            <option value="resolution">Resolution</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Select Document</label>
                        <select name="document_id" id="documentSelect" class="form-select" required disabled>
                            <option value="">Select a document first</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description (Optional)</label>
                        <textarea name="description" class="form-control" placeholder="Brief description of this document..."></textarea>
                    </div>
                    
                    <div class="file-upload-area" id="fileUploadArea">
                        <i class="fas fa-cloud-upload-alt upload-icon"></i>
                        <div class="upload-text">Drag & drop files here</div>
                        <div class="upload-subtext">or click to browse (Max: 50MB)</div>
                        <div class="btn btn-secondary">
                            <i class="fas fa-folder-open"></i> Browse Files
                        </div>
                        <input type="file" name="document_file" id="fileInput" class="file-input-hidden" required>
                        <div class="file-list" id="fileList" style="margin-top: 20px;"></div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary" onclick="clearUploadForm()">
                            <i class="fas fa-eraser"></i> Clear Form
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-upload"></i> Upload Document
                        </button>
                    </div>
                </form>
            </div>

            <!-- FILTER BAR -->
            <div class="filter-bar fade-in">
                <form method="GET" style="display: flex; flex-wrap: wrap; gap: 15px; width: 100%;">
                    <div class="filter-group">
                        <label class="filter-label">Type:</label>
                        <select name="type" class="form-select" style="width: 150px;" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="ordinance" <?php echo $filter_type === 'ordinance' ? 'selected' : ''; ?>>Ordinance</option>
                            <option value="resolution" <?php echo $filter_type === 'resolution' ? 'selected' : ''; ?>>Resolution</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Document:</label>
                        <input type="text" name="document" class="form-control" placeholder="Search by title..." 
                               value="<?php echo htmlspecialchars($filter_document); ?>" style="width: 200px;">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date:</label>
                        <input type="date" name="date" class="form-control" 
                               value="<?php echo htmlspecialchars($filter_date); ?>" style="width: 150px;">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Uploader:</label>
                        <input type="text" name="uploader" class="form-control" placeholder="Search uploader..." 
                               value="<?php echo htmlspecialchars($filter_uploader); ?>" style="width: 150px;">
                    </div>
                    
                    <div style="margin-left: auto; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="documents.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- DOCUMENTS TABLE -->
            <div class="documents-container fade-in">
                <h2 style="color: var(--qc-blue); margin-bottom: 25px; font-size: 1.5rem;">
                    <i class="fas fa-file-alt" style="color: var(--qc-gold); margin-right: 10px;"></i>
                    All Supporting Documents
                </h2>
                
                <div class="table-responsive">
                    <?php if (empty($documents)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open empty-state-icon"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Documents Found</h3>
                        <p>No supporting documents have been uploaded yet. Start by uploading your first document.</p>
                    </div>
                    <?php else: ?>
                    <table class="documents-table">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Document</th>
                                <th>Type</th>
                                <th>Uploaded By</th>
                                <th>Date</th>
                                <th>Size</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc): 
                                $file_icon = getFileIcon($doc['file_type']);
                                $file_color = getFileColor($doc['file_type']);
                                $file_size_formatted = formatFileSize($doc['file_size']);
                                $upload_date = date('M d, Y H:i', strtotime($doc['uploaded_at']));
                                
                                // Determine document title and number
                                if ($doc['document_type'] === 'ordinance') {
                                    $doc_title = $doc['ordinance_title'] ?? 'Ordinance';
                                    $doc_number = $doc['ordinance_number'] ?? 'N/A';
                                    $badge_class = 'badge-ordinance';
                                } else {
                                    $doc_title = $doc['resolution_title'] ?? 'Resolution';
                                    $doc_number = $doc['resolution_number'] ?? 'N/A';
                                    $badge_class = 'badge-resolution';
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="file-preview">
                                        <div class="file-icon-large" style="background: <?php echo $file_color; ?>;">
                                            <i class="fas <?php echo $file_icon; ?>"></i>
                                        </div>
                                        <div class="file-info">
                                            <div class="file-name"><?php echo htmlspecialchars($doc['file_name']); ?></div>
                                            <div class="file-meta"><?php echo htmlspecialchars($doc['file_type']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--gray-dark); margin-bottom: 5px;">
                                        <?php echo htmlspecialchars($doc_title); ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo htmlspecialchars($doc_number); ?>
                                    </div>
                                    <?php if ($doc['description']): ?>
                                    <div style="font-size: 0.85rem; color: var(--gray); margin-top: 5px;">
                                        <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($doc['description']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="document-badge <?php echo $badge_class; ?>">
                                        <?php echo ucfirst($doc['document_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--gray-dark);">
                                        <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--gray); text-transform: capitalize;">
                                        <?php echo str_replace('_', ' ', $doc['role']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo $upload_date; ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--qc-blue);">
                                        <?php echo $file_size_formatted; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-icon btn-icon-view" title="View" onclick="viewDocument('<?php echo $doc['file_path']; ?>', '<?php echo $doc['file_type']; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="<?php echo $doc['file_path']; ?>" download class="btn-icon btn-icon-download" title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <?php if ($user_role === 'super_admin' || $user_role === 'admin' || $doc['uploaded_by'] == $user_id): ?>
                                        <button class="btn-icon btn-icon-delete" title="Delete" 
                                                onclick="confirmDelete(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['file_name']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STORAGE STATISTICS -->
            <div class="documents-container fade-in">
                <h2 style="color: var(--qc-blue); margin-bottom: 25px; font-size: 1.5rem;">
                    <i class="fas fa-chart-pie" style="color: var(--qc-gold); margin-right: 10px;"></i>
                    Storage Statistics
                </h2>
                
                <div class="storage-stats">
                    <?php 
                    $total_ordinance_size = 0;
                    $total_resolution_size = 0;
                    
                    foreach ($storage_stats as $stat):
                        if ($stat['document_type'] === 'ordinance') {
                            $total_ordinance_size = $stat['total_size'];
                        } elseif ($stat['document_type'] === 'resolution') {
                            $total_resolution_size = $stat['total_size'];
                        }
                    ?>
                    
                    <?php if ($stat['document_type'] === 'ordinance'): ?>
                    <div class="storage-card">
                        <div class="storage-card-header">
                            <div class="storage-icon" style="background: var(--qc-blue);">
                                <i class="fas fa-gavel"></i>
                            </div>
                            <div class="storage-title">Ordinance Documents</div>
                        </div>
                        <div class="storage-value"><?php echo $stat['total_files']; ?> files</div>
                        <div class="storage-label"><?php echo formatFileSize($stat['total_size']); ?></div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $total_size > 0 ? ($total_ordinance_size / $total_size * 100) : 0; ?>%;"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($stat['document_type'] === 'resolution'): ?>
                    <div class="storage-card">
                        <div class="storage-card-header">
                            <div class="storage-icon" style="background: var(--qc-green);">
                                <i class="fas fa-file-signature"></i>
                            </div>
                            <div class="storage-title">Resolution Documents</div>
                        </div>
                        <div class="storage-value"><?php echo $stat['total_files']; ?> files</div>
                        <div class="storage-label"><?php echo formatFileSize($stat['total_size']); ?></div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $total_size > 0 ? ($total_resolution_size / $total_size * 100) : 0; ?>%; background: var(--qc-green);"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php endforeach; ?>
                    
                    <div class="storage-card">
                        <div class="storage-card-header">
                            <div class="storage-icon" style="background: var(--qc-gold);">
                                <i class="fas fa-database"></i>
                            </div>
                            <div class="storage-title">Total Storage</div>
                        </div>
                        <div class="storage-value"><?php echo $total_files; ?> files</div>
                        <div class="storage-label"><?php echo formatFileSize($total_size); ?></div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 100%; background: linear-gradient(90deg, var(--qc-gold) 0%, var(--qc-gold-dark) 100%);"></div>
                        </div>
                    </div>
                    
                    <div class="storage-card">
                        <div class="storage-card-header">
                            <div class="storage-icon" style="background: #ff4444;">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <div class="storage-title">PDF Files</div>
                        </div>
                        <div class="storage-value">
                            <?php 
                            $pdf_count = 0;
                            foreach ($storage_stats as $stat) {
                                $pdf_count += $stat['pdf_count'];
                            }
                            echo $pdf_count; 
                            ?>
                        </div>
                        <div class="storage-label">Documents</div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-content">
                    <div class="modal-icon">
                        <i class="fas fa-trash"></i>
                    </div>
                    <div class="modal-message" id="deleteMessage">
                        Are you sure you want to delete this file? This action cannot be undone.
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="cancelDelete">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <a href="#" class="btn btn-danger" id="confirmDelete">
                            <i class="fas fa-trash"></i> Delete
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>Supporting Documents Module</h3>
                    <p>
                        Upload, manage, and track supporting documents for ordinances and resolutions. 
                        Attach files, view document history, and maintain organized records.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="creation.php"><i class="fas fa-file-contract"></i> Document Creation</a></li>
                    <li><a href="documents.php"><i class="fas fa-paperclip"></i> Supporting Documents</a></li>
                    <li><a href="my_documents.php"><i class="fas fa-folder-open"></i> My Documents</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> File Upload Guide</a></li>
                    <li><a href="#"><i class="fas fa-video"></i> Tutorial Videos</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Technical Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback & Suggestions</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Supporting Documents Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All file upload activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Document Management Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
            </div>
        </div>
    </footer>

    <script>
        // Set current date
        const currentDate = new Date();
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        };
        document.getElementById('currentDate').textContent = currentDate.toLocaleDateString('en-US', options);
        
        // Mobile menu toggle functionality
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        if (mobileMenuToggle && sidebar) {
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
            });
            
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            });
            
            // Close sidebar when clicking on a link (for mobile)
            sidebar.addEventListener('click', function(e) {
                if (e.target.tagName === 'A' && window.innerWidth <= 992) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                }
            });
        }
        
        // Document type change handler
        function updateDocumentList() {
            const docType = document.querySelector('select[name="document_type"]').value;
            const docSelect = document.getElementById('documentSelect');
            
            if (!docType) {
                docSelect.innerHTML = '<option value="">Select a document first</option>';
                docSelect.disabled = true;
                return;
            }
            
            // Get documents based on type
            let documents = [];
            <?php 
            // Pass PHP data to JavaScript
            echo "const ordinances = " . json_encode($ordinances) . ";\n";
            echo "const resolutions = " . json_encode($resolutions) . ";\n";
            ?>
            
            if (docType === 'ordinance') {
                documents = ordinances;
            } else {
                documents = resolutions;
            }
            
            // Update select options
            let options = '<option value="">Select a document</option>';
            documents.forEach(doc => {
                const statusBadge = doc.status === 'draft' ? ' (Draft)' : 
                                  doc.status === 'approved' ? ' (Approved)' : 
                                  doc.status === 'pending' ? ' (Pending)' : '';
                options += `<option value="${doc.id}">${doc.doc_number}: ${doc.title}${statusBadge}</option>`;
            });
            
            docSelect.innerHTML = options;
            docSelect.disabled = false;
        }
        
        // File upload handling
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        
        fileUploadArea.addEventListener('click', function() {
            fileInput.click();
        });
        
        fileInput.addEventListener('change', handleFileSelect);
        
        // Drag and drop functionality
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            fileUploadArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            fileUploadArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            fileUploadArea.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            fileUploadArea.classList.add('dragover');
        }
        
        function unhighlight() {
            fileUploadArea.classList.remove('dragover');
        }
        
        fileUploadArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }
        
        function handleFileSelect(e) {
            const files = e.target.files;
            handleFiles(files);
        }
        
        function handleFiles(files) {
            fileList.innerHTML = '';
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                if (validateFile(file)) {
                    addFileToList(file);
                }
            }
        }
        
        function validateFile(file) {
            const validTypes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/jpeg',
                'image/png',
                'text/plain',
                'application/zip',
                'application/vnd.rar'
            ];
            
            const maxSize = 50 * 1024 * 1024; // 50MB
            
            if (!validTypes.includes(file.type)) {
                alert(`File "${file.name}" is not a supported file type. Please upload PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, ZIP, or RAR files.`);
                return false;
            }
            
            if (file.size > maxSize) {
                alert(`File "${file.name}" exceeds the maximum file size of 50MB.`);
                return false;
            }
            
            return true;
        }
        
        function addFileToList(file) {
            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';
            fileItem.style.cssText = 'display: flex; align-items: center; gap: 12px; padding: 10px; background: var(--off-white); border-radius: var(--border-radius); margin-bottom: 8px;';
            
            const fileIcon = document.createElement('i');
            fileIcon.className = 'fas fa-file';
            fileIcon.style.color = getFileColor(file.type);
            
            const fileName = document.createElement('div');
            fileName.className = 'file-name';
            fileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
            fileName.style.flex = '1';
            
            fileItem.appendChild(fileIcon);
            fileItem.appendChild(fileName);
            
            fileList.appendChild(fileItem);
        }
        
        function getFileColor(fileType) {
            if (fileType.includes('pdf')) return '#ff4444';
            if (fileType.includes('word')) return '#2b579a';
            if (fileType.includes('excel')) return '#217346';
            if (fileType.includes('image')) return '#ff66cc';
            if (fileType.includes('text')) return '#666666';
            if (fileType.includes('zip') || fileType.includes('rar')) return '#ff9900';
            return '#999999';
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // Form validation
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const docType = document.querySelector('select[name="document_type"]').value;
            const docId = document.getElementById('documentSelect').value;
            const file = document.getElementById('fileInput').files[0];
            
            if (!docType) {
                e.preventDefault();
                alert('Please select a document type.');
                return;
            }
            
            if (!docId) {
                e.preventDefault();
                alert('Please select a document.');
                return;
            }
            
            if (!file) {
                e.preventDefault();
                alert('Please select a file to upload.');
                return;
            }
        });
        
        function clearUploadForm() {
            document.getElementById('uploadForm').reset();
            document.getElementById('documentSelect').innerHTML = '<option value="">Select a document first</option>';
            document.getElementById('documentSelect').disabled = true;
            fileList.innerHTML = '';
        }
        
        // View document
        function viewDocument(filePath, fileType) {
            // Open in new tab for PDFs and images
            if (fileType.includes('pdf') || fileType.includes('image')) {
                window.open(filePath, '_blank');
            } else {
                // For other files, try to download
                window.open(filePath, '_blank');
            }
        }
        
        // Delete confirmation
        const deleteModal = document.getElementById('deleteModal');
        const modalClose = document.getElementById('modalClose');
        const cancelDelete = document.getElementById('cancelDelete');
        let deleteUrl = '';
        
        function confirmDelete(docId, fileName) {
            document.getElementById('deleteMessage').textContent = 
                `Are you sure you want to delete "${fileName}"? This action cannot be undone.`;
            
            deleteUrl = `documents.php?delete=true&id=${docId}`;
            document.getElementById('confirmDelete').href = deleteUrl;
            
            deleteModal.classList.add('active');
        }
        
        // Close modal
        modalClose.addEventListener('click', closeModal);
        cancelDelete.addEventListener('click', closeModal);
        deleteModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        function closeModal() {
            deleteModal.classList.remove('active');
        }
        
        // Add animation to elements
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('fade-in');
                    }
                });
            }, observerOptions);
            
            // Observe all containers
            document.querySelectorAll('.upload-container, .filter-bar, .documents-container').forEach(section => {
                observer.observe(section);
            });
        });
    </script>
</body>
</html>