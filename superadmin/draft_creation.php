<?php
// draft_creation.php - Draft Creation Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to create drafts
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

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        $document_type = $_POST['document_type'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $content = $_POST['content'];
        $template_id = $_POST['template_id'] ?? null;
        $authors = $_POST['authors'] ?? [];
        
        // Generate document number
        $prefix = ($document_type === 'ordinance') ? 'ORD' : 'RES';
        $year = date('Y');
        $month = date('m');
        
        // Get next sequence number for this document type
        $sequence_query = "SELECT COUNT(*) + 1 as next_num FROM " . 
                         ($document_type === 'ordinance' ? 'ordinances' : 'resolutions') . 
                         " WHERE YEAR(created_at) = :year";
        $stmt = $conn->prepare($sequence_query);
        $stmt->bindParam(':year', $year);
        $stmt->execute();
        $result = $stmt->fetch();
        $sequence = str_pad($result['next_num'], 3, '0', STR_PAD_LEFT);
        
        $document_number = "QC-$prefix-$year-$month-$sequence";
        
        if ($document_type === 'ordinance') {
            // Insert into ordinances table
            $query = "INSERT INTO ordinances (ordinance_number, title, description, status, created_by, created_at, updated_at) 
                     VALUES (:doc_number, :title, :description, 'draft', :created_by, NOW(), NOW())";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':doc_number', $document_number);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':created_by', $user_id);
            $stmt->execute();
            
            $document_id = $conn->lastInsertId();
            
            // Create initial version
            $version_query = "INSERT INTO document_versions (document_id, document_type, version_number, title, content, created_by, is_current) 
                            VALUES (:doc_id, 'ordinance', 1, :title, :content, :created_by, 1)";
            $stmt = $conn->prepare($version_query);
            $stmt->bindParam(':doc_id', $document_id);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':content', $content);
            $stmt->bindParam(':created_by', $user_id);
            $stmt->execute();
            
        } else {
            // Insert into resolutions table
            $query = "INSERT INTO resolutions (resolution_number, title, description, status, created_by, created_at, updated_at) 
                     VALUES (:doc_number, :title, :description, 'draft', :created_by, NOW(), NOW())";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':doc_number', $document_number);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':created_by', $user_id);
            $stmt->execute();
            
            $document_id = $conn->lastInsertId();
            
            // Create initial version
            $version_query = "INSERT INTO document_versions (document_id, document_type, version_number, title, content, created_by, is_current) 
                            VALUES (:doc_id, 'resolution', 1, :title, :content, :created_by, 1)";
            $stmt = $conn->prepare($version_query);
            $stmt->bindParam(':doc_id', $document_id);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':content', $content);
            $stmt->bindParam(':created_by', $user_id);
            $stmt->execute();
        }
        
        // Assign authors
        foreach ($authors as $author_id) {
            $author_query = "INSERT INTO document_authors (document_id, document_type, user_id, role, assigned_by) 
                           VALUES (:doc_id, :doc_type, :author_id, 'author', :assigned_by)";
            $stmt = $conn->prepare($author_query);
            $stmt->bindParam(':doc_id', $document_id);
            $stmt->bindParam(':doc_type', $document_type);
            $stmt->bindParam(':author_id', $author_id);
            $stmt->bindParam(':assigned_by', $user_id);
            $stmt->execute();
        }
        
        // Handle file uploads
        if (!empty($_FILES['supporting_docs']['name'][0])) {
            $upload_dir = '../uploads/supporting_docs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            for ($i = 0; $i < count($_FILES['supporting_docs']['name']); $i++) {
                if ($_FILES['supporting_docs']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_name = basename($_FILES['supporting_docs']['name'][$i]);
                    $file_tmp = $_FILES['supporting_docs']['tmp_name'][$i];
                    $file_size = $_FILES['supporting_docs']['size'][$i];
                    $file_type = $_FILES['supporting_docs']['type'][$i];
                    
                    // Generate unique filename
                    $unique_name = time() . '_' . uniqid() . '_' . $file_name;
                    $file_path = $upload_dir . $unique_name;
                    
                    if (move_uploaded_file($file_tmp, $file_path)) {
                        $file_query = "INSERT INTO supporting_documents (document_id, document_type, file_name, file_path, file_type, file_size, uploaded_by) 
                                     VALUES (:doc_id, :doc_type, :file_name, :file_path, :file_type, :file_size, :uploaded_by)";
                        $stmt = $conn->prepare($file_query);
                        $stmt->bindParam(':doc_id', $document_id);
                        $stmt->bindParam(':doc_type', $document_type);
                        $stmt->bindParam(':file_name', $file_name);
                        $stmt->bindParam(':file_path', $file_path);
                        $stmt->bindParam(':file_type', $file_type);
                        $stmt->bindParam(':file_size', $file_size);
                        $stmt->bindParam(':uploaded_by', $user_id);
                        $stmt->execute();
                    }
                }
            }
        }
        
        $conn->commit();
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'DRAFT_CREATE', 'Created new draft: {$document_number}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Draft created successfully! Document Number: {$document_number}";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error creating draft: " . $e->getMessage();
    }
}

// Get available templates
$templates_query = "SELECT * FROM document_templates WHERE is_active = 1 ORDER BY template_name";
$templates_stmt = $conn->query($templates_query);
$templates = $templates_stmt->fetchAll();

// Get available users for author assignment (councilors only)
$users_query = "SELECT id, first_name, last_name, role, department FROM users 
                WHERE is_active = 1 AND role IN ('councilor', 'admin', 'super_admin')
                ORDER BY last_name, first_name";
$users_stmt = $conn->query($users_query);
$available_users = $users_stmt->fetchAll();

// Get recent drafts for current user
$recent_drafts_query = "(
    SELECT 'ordinance' as doc_type, id, ordinance_number as doc_number, title, status, created_at 
    FROM ordinances 
    WHERE created_by = :user_id
    ORDER BY created_at DESC 
    LIMIT 5
) UNION ALL (
    SELECT 'resolution' as doc_type, id, resolution_number as doc_number, title, status, created_at 
    FROM resolutions 
    WHERE created_by = :user_id
    ORDER BY created_at DESC 
    LIMIT 5
) ORDER BY created_at DESC LIMIT 10";

$recent_stmt = $conn->prepare($recent_drafts_query);
$recent_stmt->bindParam(':user_id', $user_id);
$recent_stmt->execute();
$recent_drafts = $recent_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Draft Creation | QC Ordinance Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
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
        
        /* FIXED MODULE HEADER - IMPROVED */
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
            max-width: 800px;
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
        
        /* WORKING STEPS INDICATOR */
        .steps-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-light);
        }
        
        .steps-indicator::before {
            content: '';
            position: absolute;
            top: 58px;
            left: 50px;
            right: 50px;
            height: 4px;
            background: var(--gray-light);
            z-index: 1;
        }
        
        .step {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            flex: 1;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .step:hover .step-number {
            transform: scale(1.1);
        }
        
        .step-number {
            width: 60px;
            height: 60px;
            background: var(--white);
            border: 4px solid var(--gray-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: var(--gray);
            font-size: 1.2rem;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        
        .step.active .step-number {
            background: var(--qc-blue);
            border-color: var(--qc-blue);
            color: var(--white);
            box-shadow: 0 6px 20px rgba(0, 51, 102, 0.3);
        }
        
        .step.completed .step-number {
            background: var(--qc-green);
            border-color: var(--qc-green);
            color: var(--white);
        }
        
        .step.completed .step-number::after {
            content: '✓';
            position: absolute;
            font-size: 1.5rem;
        }
        
        .step-label {
            font-weight: 600;
            color: var(--gray);
            font-size: 1rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .step.active .step-label {
            color: var(--qc-blue);
            font-weight: bold;
            transform: translateY(5px);
        }
        
        .step-description {
            font-size: 0.85rem;
            color: var(--gray);
            text-align: center;
            max-width: 150px;
            margin-top: 5px;
            display: none;
        }
        
        .step.active .step-description {
            display: block;
        }
        
        /* FORM STYLES */
        .creation-form-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .creation-form-container {
                grid-template-columns: 1fr;
            }
        }
        
        .form-main {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .form-sidebar {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            color: var(--qc-blue);
            font-size: 1.2rem;
            font-weight: bold;
        }
        
        .section-title i {
            color: var(--qc-gold);
            font-size: 1.1rem;
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
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }
        
        .radio-group {
            display: flex;
            gap: 30px;
            margin-top: 10px;
        }
        
        .radio-option {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .radio-input {
            width: 20px;
            height: 20px;
            accent-color: var(--qc-blue);
        }
        
        .radio-label {
            font-weight: 500;
            color: var(--gray-dark);
        }
        
        /* Template Selector */
        .template-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .template-option {
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .template-option:hover {
            border-color: var(--qc-blue);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .template-option.selected {
            border-color: var(--qc-gold);
            background: rgba(212, 175, 55, 0.05);
        }
        
        .template-radio {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 20px;
            height: 20px;
            accent-color: var(--qc-gold);
        }
        
        .template-name {
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 5px;
            font-size: 1.1rem;
        }
        
        .template-type {
            display: inline-block;
            padding: 3px 10px;
            background: var(--gray-light);
            color: var(--gray-dark);
            border-radius: 20px;
            font-size: 0.8rem;
            margin-bottom: 10px;
        }
        
        .template-description {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        /* Author Selection */
        .author-selection {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-top: 10px;
        }
        
        .author-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .author-item:hover {
            background: var(--off-white);
        }
        
        .author-item:last-child {
            border-bottom: none;
        }
        
        .author-checkbox {
            width: 20px;
            height: 20px;
            accent-color: var(--qc-blue);
        }
        
        .author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
        }
        
        .author-info {
            flex: 1;
        }
        
        .author-name {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 2px;
        }
        
        .author-role {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .author-department {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        /* File Upload */
        .file-upload-area {
            border: 2px dashed var(--gray-light);
            border-radius: var(--border-radius);
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
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
        
        .file-list {
            margin-top: 20px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 15px;
            background: var(--off-white);
            border-radius: var(--border-radius);
            margin-bottom: 8px;
            border: 1px solid var(--gray-light);
        }
        
        .file-icon {
            color: var(--qc-blue);
            font-size: 1.2rem;
        }
        
        .file-name {
            flex: 1;
            color: var(--gray-dark);
            font-size: 0.9rem;
            word-break: break-all;
        }
        
        .file-remove {
            color: var(--red);
            cursor: pointer;
            font-size: 1.1rem;
        }
        
        /* Text Editor */
        #editor-container {
            height: 400px;
            margin-top: 10px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            overflow: hidden;
        }
        
        .ql-toolbar {
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
            border-bottom: 1px solid var(--gray-light);
        }
        
        .ql-container {
            border-bottom-left-radius: var(--border-radius);
            border-bottom-right-radius: var(--border-radius);
            height: calc(100% - 42px);
        }
        
        /* Recent Drafts */
        .recent-drafts {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .drafts-list {
            list-style: none;
        }
        
        .draft-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .draft-item:hover {
            background: var(--off-white);
            transform: translateX(5px);
        }
        
        .draft-item:last-child {
            border-bottom: none;
        }
        
        .draft-icon {
            width: 50px;
            height: 50px;
            background: var(--off-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-blue);
            font-size: 1.2rem;
        }
        
        .draft-content {
            flex: 1;
        }
        
        .draft-title {
            font-weight: 600;
            color: var(--qc-blue);
            margin-bottom: 5px;
            font-size: 1rem;
        }
        
        .draft-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .draft-number {
            font-weight: 600;
            color: var(--qc-gold);
        }
        
        .draft-status {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-pending { background: #dbeafe; color: #1e40af; }
        .status-approved { background: #d1fae5; color: #065f46; }
        
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
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
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
            padding: 25px 30px;
            border-top-left-radius: var(--border-radius-lg);
            border-top-right-radius: var(--border-radius-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--qc-gold);
        }
        
        .modal-header h2 {
            font-size: 1.8rem;
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
        
        .modal-section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .modal-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .modal-section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            color: var(--qc-blue);
            font-size: 1.3rem;
            font-weight: bold;
        }
        
        .modal-section-title i {
            color: var(--qc-gold);
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }
        
        /* Action Buttons */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 25px;
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
        
        /* Alerts */
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
        
        /* FOOTER */
        .government-footer {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 40px 0 20px;
            margin-top: 60px;
            position: relative;
            overflow: hidden;
            margin-left: var(--sidebar-width);
        }
        
        .government-footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                linear-gradient(45deg, transparent 49%, rgba(212, 175, 55, 0.05) 50%, transparent 51%),
                linear-gradient(-45deg, transparent 49%, rgba(212, 175, 55, 0.05) 50%, transparent 51%);
            background-size: 100px 100px;
        }
        
        .footer-content {
            position: relative;
            z-index: 2;
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
            position: relative;
            z-index: 2;
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
            
            .module-stats {
                flex-direction: column;
                gap: 20px;
            }
            
            .module-title-wrapper {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
        }
        
        @media (max-width: 768px) {
            .radio-group {
                flex-direction: column;
                gap: 15px;
            }
            
            .template-options {
                grid-template-columns: 1fr;
            }
            
            .form-actions, .modal-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .steps-indicator {
                flex-direction: column;
                gap: 30px;
                padding: 20px;
            }
            
            .steps-indicator::before {
                display: none;
            }
            
            .step {
                flex-direction: row;
                justify-content: flex-start;
                text-align: left;
            }
            
            .step-number {
                width: 50px;
                height: 50px;
            }
        }
        
        /* Mobile menu toggle button (hidden by default on desktop) */
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
                    <p>Draft Creation Module | Ordinance & Resolution Drafting</p>
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
                        <i class="fas fa-file-contract"></i> Creation Module
                    </h3>
                    <p class="sidebar-subtitle">Draft Creation Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="draft-creation-container">
                <!-- FIXED MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">DRAFT CREATION MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-edit"></i>
                            </div>
                            <div class="module-title">
                                <h1>Create New Draft Document</h1>
                                <p class="module-subtitle">
                                    Create new ordinance or resolution drafts with standard legal formats. 
                                    Assign sponsors and authors, upload supporting documents, and register draft records.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo count($recent_drafts); ?></h3>
                                    <p>Your Drafts</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo count($available_users); ?></h3>
                                    <p>Available Authors</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-templates"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo count($templates); ?></h3>
                                    <p>Templates Available</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WORKING STEPS INDICATOR -->
                <div class="steps-indicator fade-in">
                    <div class="step active" data-step="1">
                        <div class="step-number">1</div>
                        <div class="step-label">Document Type</div>
                        <div class="step-description">Select ordinance or resolution</div>
                    </div>
                    <div class="step" data-step="2">
                        <div class="step-number">2</div>
                        <div class="step-label">Template</div>
                        <div class="step-description">Choose a template or start blank</div>
                    </div>
                    <div class="step" data-step="3">
                        <div class="step-number">3</div>
                        <div class="step-label">Content</div>
                        <div class="step-description">Write your document content</div>
                    </div>
                    <div class="step" data-step="4">
                        <div class="step-number">4</div>
                        <div class="step-label">Authors & Files</div>
                        <div class="step-description">Assign authors and upload files</div>
                    </div>
                    <div class="step" data-step="5">
                        <div class="step-number">5</div>
                        <div class="step-label">Review & Submit</div>
                        <div class="step-description">Review and create draft</div>
                    </div>
                </div>

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

                <!-- Creation Form -->
                <form id="draftCreationForm" method="POST" enctype="multipart/form-data" class="fade-in">
                    <div class="creation-form-container">
                        <!-- Main Form -->
                        <div class="form-main">
                            <!-- Document Type Selection -->
                            <div class="form-section step-content" data-step="1">
                                <h3 class="section-title">
                                    <i class="fas fa-file-alt"></i>
                                    1. Select Document Type
                                </h3>
                                
                                <div class="form-group">
                                    <label class="form-label required">Document Type</label>
                                    <div class="radio-group">
                                        <label class="radio-option">
                                            <input type="radio" name="document_type" value="ordinance" class="radio-input" required checked>
                                            <span class="radio-label">
                                                <i class="fas fa-gavel" style="color: var(--qc-blue);"></i>
                                                Ordinance
                                            </span>
                                        </label>
                                        <label class="radio-option">
                                            <input type="radio" name="document_type" value="resolution" class="radio-input" required>
                                            <span class="radio-label">
                                                <i class="fas fa-file-signature" style="color: var(--qc-green);"></i>
                                                Resolution
                                            </span>
                                        </label>
                                    </div>
                                    <small class="form-text" style="color: var(--gray); margin-top: 8px; display: block;">
                                        <strong>Ordinance:</strong> A local law enacted by the Sangguniang Panlungsod<br>
                                        <strong>Resolution:</strong> A formal expression of opinion, will, or intent
                                    </small>
                                </div>
                            </div>

                            <!-- Document Details -->
                            <div class="form-section step-content" data-step="2" style="display: none;">
                                <h3 class="section-title">
                                    <i class="fas fa-info-circle"></i>
                                    2. Document Details
                                </h3>
                                
                                <div class="form-group">
                                    <label for="title" class="form-label required">Document Title</label>
                                    <input type="text" id="title" name="title" class="form-control" 
                                           placeholder="Enter the full title of the ordinance or resolution" 
                                           required maxlength="255">
                                </div>
                                
                                <div class="form-group">
                                    <label for="description" class="form-label required">Description/Summary</label>
                                    <textarea id="description" name="description" class="form-control" 
                                              placeholder="Provide a brief summary of what this document aims to accomplish" 
                                              rows="4" required></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Select Template (Optional)</label>
                                    <div class="template-options">
                                        <div class="template-option" onclick="selectTemplate('none')">
                                            <input type="radio" name="template_id" value="" class="template-radio" checked>
                                            <div class="template-name">Blank Document</div>
                                            <div class="template-type">No Template</div>
                                            <div class="template-description">
                                                Start with a completely blank document
                                            </div>
                                        </div>
                                        
                                        <?php foreach ($templates as $template): ?>
                                        <div class="template-option" onclick="selectTemplate(<?php echo $template['id']; ?>)">
                                            <input type="radio" name="template_id" value="<?php echo $template['id']; ?>" class="template-radio">
                                            <div class="template-name"><?php echo htmlspecialchars($template['template_name']); ?></div>
                                            <div class="template-type"><?php echo ucfirst($template['template_type']); ?></div>
                                            <div class="template-description">
                                                <?php echo htmlspecialchars($template['description']); ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Content Editor -->
                            <div class="form-section step-content" data-step="3" style="display: none;">
                                <h3 class="section-title">
                                    <i class="fas fa-edit"></i>
                                    3. Document Content
                                </h3>
                                
                                <div class="form-group">
                                    <label class="form-label required">Document Content</label>
                                    <div id="editor-container"></div>
                                    <textarea id="content" name="content" style="display: none;" required></textarea>
                                </div>
                            </div>

                            <!-- Authors and Files -->
                            <div class="form-section step-content" data-step="4" style="display: none;">
                                <h3 class="section-title">
                                    <i class="fas fa-user-edit"></i>
                                    4. Authors and Supporting Documents
                                </h3>
                                
                                <div class="form-group">
                                    <label class="form-label">Select Authors/Sponsors</label>
                                    <div class="author-selection">
                                        <?php foreach ($available_users as $available_user): 
                                            $is_current_user = $available_user['id'] == $user_id;
                                        ?>
                                        <div class="author-item">
                                            <input type="checkbox" name="authors[]" value="<?php echo $available_user['id']; ?>" 
                                                   class="author-checkbox" <?php echo $is_current_user ? 'checked' : ''; ?>>
                                            <div class="author-avatar">
                                                <?php echo strtoupper(substr($available_user['first_name'], 0, 1) . substr($available_user['last_name'], 0, 1)); ?>
                                            </div>
                                            <div class="author-info">
                                                <div class="author-name">
                                                    <?php echo htmlspecialchars($available_user['first_name'] . ' ' . $available_user['last_name']); ?>
                                                    <?php if ($is_current_user): ?>
                                                    <span style="color: var(--qc-gold); font-size: 0.8rem;">(You)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="author-role"><?php echo ucfirst(str_replace('_', ' ', $available_user['role'])); ?></div>
                                                <div class="author-department"><?php echo htmlspecialchars($available_user['department']); ?></div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Upload Supporting Files</label>
                                    <div class="file-upload-area" id="fileUploadArea">
                                        <i class="fas fa-cloud-upload-alt upload-icon"></i>
                                        <div class="upload-text">Drag & drop files here</div>
                                        <div class="upload-subtext">or click to browse</div>
                                        <div class="btn btn-secondary">
                                            <i class="fas fa-folder-open"></i> Browse Files
                                        </div>
                                        <input type="file" name="supporting_docs[]" id="fileInput" 
                                               class="file-input-hidden" multiple 
                                               accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                                    </div>
                                    <div class="file-list" id="fileList"></div>
                                    <small class="form-text" style="color: var(--gray); margin-top: 8px; display: block;">
                                        Maximum file size: 10MB each<br>
                                        Allowed formats: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG
                                    </small>
                                </div>
                            </div>

                            <!-- Review and Submit -->
                            <div class="form-section step-content" data-step="5" style="display: none;">
                                <h3 class="section-title">
                                    <i class="fas fa-check-circle"></i>
                                    5. Review and Submit
                                </h3>
                                
                                <div class="review-summary" id="reviewSummary">
                                    <p>Please fill in all the previous steps to see the review summary.</p>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Additional Notes (Optional)</label>
                                    <textarea id="notes" name="notes" class="form-control" 
                                              placeholder="Any additional notes or instructions for this draft..." 
                                              rows="3"></textarea>
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="form-actions">
                                <button type="button" class="btn btn-secondary" id="prevBtn" style="display: none;">
                                    <i class="fas fa-arrow-left"></i> Previous Step
                                </button>
                                <button type="button" class="btn btn-primary" id="nextBtn">
                                    Next Step <i class="fas fa-arrow-right"></i>
                                </button>
                                <button type="submit" class="btn btn-success" id="submitBtn" style="display: none;">
                                    <i class="fas fa-check"></i> Create Draft
                                </button>
                            </div>
                        </div>

                        <!-- Sidebar -->
                        <div class="form-sidebar">
                            <!-- Quick Actions -->
                            <div class="form-section">
                                <h3 class="section-title">
                                    <i class="fas fa-bolt"></i>
                                    Quick Actions
                                </h3>
                                
                                <div style="display: flex; flex-direction: column; gap: 10px;">
                                    <button type="button" class="btn btn-secondary" onclick="saveAsDraft()">
                                        <i class="fas fa-save"></i> Save as Draft
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                        <i class="fas fa-eraser"></i> Clear Form
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="previewDocument()">
                                        <i class="fas fa-eye"></i> Preview
                                    </button>
                                    <a href="templates.php" class="btn btn-secondary">
                                        <i class="fas fa-file-alt"></i> Manage Templates
                                    </a>
                                </div>
                            </div>

                            <!-- Progress Summary -->
                            <div class="form-section">
                                <h3 class="section-title">
                                    <i class="fas fa-chart-line"></i>
                                    Progress Summary
                                </h3>
                                
                                <div id="progressSummary">
                                    <div style="margin-bottom: 15px;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                            <span>Overall Progress</span>
                                            <span id="progressPercent">20%</span>
                                        </div>
                                        <div style="height: 10px; background: var(--gray-light); border-radius: 5px; overflow: hidden;">
                                            <div id="progressBar" style="height: 100%; width: 20%; background: var(--qc-green); border-radius: 5px; transition: width 0.3s ease;"></div>
                                        </div>
                                    </div>
                                    
                                    <div style="font-size: 0.9rem;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                            <span>Current Step:</span>
                                            <strong id="currentStepDisplay">1/5</strong>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                            <span>Document Type:</span>
                                            <span id="docTypeDisplay">Ordinance</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between;">
                                            <span>Authors Selected:</span>
                                            <span id="authorsCount">1</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Help Section -->
                            <div class="form-section">
                                <h3 class="section-title">
                                    <i class="fas fa-question-circle"></i>
                                    Need Help?
                                </h3>
                                
                                <div style="font-size: 0.9rem; color: var(--gray);">
                                    <p style="margin-bottom: 10px;">For assistance with:</p>
                                    <ul style="padding-left: 20px; margin-bottom: 15px;">
                                        <li>Document formats</li>
                                        <li>Template selection</li>
                                        <li>Author assignment</li>
                                        <li>File uploads</li>
                                    </ul>
                                    <p>Contact the <strong>Council Secretariat</strong> at extension 1234 or email <strong>secretariat@qc.gov.ph</strong></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Recent Drafts -->
                <div class="recent-drafts fade-in">
                    <div class="section-title">
                        <i class="fas fa-history"></i>
                        <h2>Your Recent Drafts</h2>
                    </div>
                    
                    <?php if (empty($recent_drafts)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                        <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Drafts Found</h3>
                        <p>You haven't created any drafts yet. Start by creating your first draft above.</p>
                    </div>
                    <?php else: ?>
                    <ul class="drafts-list">
                        <?php foreach ($recent_drafts as $draft): 
                            $status_class = 'status-' . $draft['status'];
                            $icon = $draft['doc_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                            $type_color = $draft['doc_type'] === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
                        ?>
                        <li class="draft-item">
                            <div class="draft-icon" style="color: <?php echo $type_color; ?>;">
                                <i class="fas <?php echo $icon; ?>"></i>
                            </div>
                            <div class="draft-content">
                                <div class="draft-title"><?php echo htmlspecialchars($draft['title']); ?></div>
                                <div class="draft-meta">
                                    <span class="draft-number"><?php echo htmlspecialchars($draft['doc_number']); ?></span>
                                    <span class="draft-status <?php echo $status_class; ?>">
                                        <?php echo ucfirst($draft['status']); ?>
                                    </span>
                                    <span><?php echo date('M d, Y', strtotime($draft['created_at'])); ?></span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary edit-draft-btn" 
                                    data-draft-id="<?php echo $draft['id']; ?>"
                                    data-draft-type="<?php echo $draft['doc_type']; ?>"
                                    data-draft-number="<?php echo htmlspecialchars($draft['doc_number']); ?>"
                                    data-draft-title="<?php echo htmlspecialchars($draft['title']); ?>"
                                    data-draft-status="<?php echo $draft['status']; ?>">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- EDIT DRAFT MODAL -->
    <div class="modal-overlay" id="editDraftModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Draft</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-info-circle"></i>
                        Draft Information
                    </h3>
                    <div id="modalDraftInfo">
                        <!-- Draft info will be loaded here -->
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-edit"></i>
                        Quick Actions
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <button type="button" class="btn btn-primary" id="modalViewBtn">
                            <i class="fas fa-eye"></i> View Full
                        </button>
                        <button type="button" class="btn btn-success" id="modalUpdateBtn">
                            <i class="fas fa-sync-alt"></i> Update
                        </button>
                        <button type="button" class="btn btn-secondary" id="modalVersionBtn">
                            <i class="fas fa-code-branch"></i> Versions
                        </button>
                        <button type="button" class="btn btn-danger" id="modalDeleteBtn">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-history"></i>
                        Recent Activity
                    </h3>
                    <div id="modalActivityLog">
                        <!-- Activity log will be loaded here -->
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="modalCancelBtn">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-primary" id="modalContinueBtn">
                        <i class="fas fa-external-link-alt"></i> Continue to Edit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>Draft Creation Module</h3>
                    <p>
                        Create new ordinance and resolution drafts with standard legal formats. 
                        Assign sponsors and authors, upload supporting documents, and register draft records.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="creation.php"><i class="fas fa-file-contract"></i> Document Creation</a></li>
                    <li><a href="templates.php"><i class="fas fa-file-alt"></i> Template Library</a></li>
                    <li><a href="my_documents.php"><i class="fas fa-folder-open"></i> My Documents</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> User Guide</a></li>
                    <li><a href="#"><i class="fas fa-video"></i> Video Tutorials</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Technical Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback & Suggestions</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Draft Creation Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All document creation activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Document Drafting Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
            </div>
        </div>
    </footer>

    <!-- Include Quill.js for rich text editor -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script>
        // Initialize Quill editor
        const quill = new Quill('#editor-container', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    [{ 'script': 'sub'}, { 'script': 'super' }],
                    [{ 'indent': '-1'}, { 'indent': '+1' }],
                    [{ 'direction': 'rtl' }],
                    [{ 'color': [] }, { 'background': [] }],
                    [{ 'align': [] }],
                    ['clean']
                ]
            },
            placeholder: 'Start writing your ordinance or resolution here...'
        });

        // Sync Quill content with form textarea
        quill.on('text-change', function() {
            document.getElementById('content').value = quill.root.innerHTML;
            updateProgress();
        });

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
        
        // Template selection
        function selectTemplate(templateId) {
            // Update radio button
            const radio = document.querySelector(`input[name="template_id"][value="${templateId}"]`);
            if (radio) {
                radio.checked = true;
                
                // Remove selected class from all templates
                document.querySelectorAll('.template-option').forEach(option => {
                    option.classList.remove('selected');
                });
                
                // Add selected class to clicked template
                radio.closest('.template-option').classList.add('selected');
                
                // If template has content, load it into editor
                if (templateId !== 'none' && templateId !== '') {
                    // In a real application, you would fetch the template content via AJAX
                    console.log('Loading template:', templateId);
                    // Example: fetchTemplateContent(templateId);
                }
            }
        }
        
        // File upload handling
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        
        let uploadedFiles = [];
        
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
            [...files].forEach(file => {
                if (validateFile(file)) {
                    uploadedFiles.push(file);
                    addFileToList(file);
                }
            });
            
            // Update the file input
            const dataTransfer = new DataTransfer();
            uploadedFiles.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
            updateProgress();
        }
        
        function validateFile(file) {
            const validTypes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/jpeg',
                'image/png'
            ];
            
            const maxSize = 10 * 1024 * 1024; // 10MB
            
            if (!validTypes.includes(file.type)) {
                alert(`File "${file.name}" is not a supported file type.`);
                return false;
            }
            
            if (file.size > maxSize) {
                alert(`File "${file.name}" exceeds the maximum file size of 10MB.`);
                return false;
            }
            
            return true;
        }
        
        function addFileToList(file) {
            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';
            
            const fileIcon = document.createElement('i');
            fileIcon.className = 'file-icon fas ' + getFileIcon(file.type);
            
            const fileName = document.createElement('div');
            fileName.className = 'file-name';
            fileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
            
            const fileRemove = document.createElement('i');
            fileRemove.className = 'file-remove fas fa-times';
            fileRemove.addEventListener('click', function() {
                removeFile(file);
                fileItem.remove();
                updateProgress();
            });
            
            fileItem.appendChild(fileIcon);
            fileItem.appendChild(fileName);
            fileItem.appendChild(fileRemove);
            
            fileList.appendChild(fileItem);
        }
        
        function getFileIcon(fileType) {
            if (fileType.includes('pdf')) return 'fa-file-pdf';
            if (fileType.includes('word') || fileType.includes('document')) return 'fa-file-word';
            if (fileType.includes('excel') || fileType.includes('sheet')) return 'fa-file-excel';
            if (fileType.includes('image')) return 'fa-file-image';
            return 'fa-file';
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        function removeFile(fileToRemove) {
            uploadedFiles = uploadedFiles.filter(file => file !== fileToRemove);
            
            // Update the file input
            const dataTransfer = new DataTransfer();
            uploadedFiles.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
        }
        
        // Form actions
        function saveAsDraft() {
            // In a real application, this would save the form data without submitting
            alert('Draft saved successfully!');
        }
        
        function clearForm() {
            if (confirm('Are you sure you want to clear the form? All unsaved changes will be lost.')) {
                document.getElementById('draftCreationForm').reset();
                quill.setText('');
                uploadedFiles = [];
                fileList.innerHTML = '';
                document.querySelectorAll('.template-option').forEach(option => {
                    option.classList.remove('selected');
                });
                document.querySelector('input[name="template_id"][value=""]').checked = true;
                document.querySelector('input[name="template_id"][value=""]').closest('.template-option').classList.add('selected');
                currentStep = 1;
                showStep(currentStep);
            }
        }
        
        function previewDocument() {
            // In a real application, this would open a preview window
            alert('Document preview feature coming soon!');
        }
        
        // Form validation
        document.getElementById('draftCreationForm').addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const description = document.getElementById('description').value.trim();
            const content = quill.getText().trim();
            
            if (!title) {
                e.preventDefault();
                alert('Please enter a document title.');
                return;
            }
            
            if (!description) {
                e.preventDefault();
                alert('Please enter a document description.');
                return;
            }
            
            if (!content) {
                e.preventDefault();
                alert('Please enter document content.');
                return;
            }
            
            // Update content textarea with Quill HTML
            document.getElementById('content').value = quill.root.innerHTML;
            
            // Show confirmation
            if (!confirm('Are you sure you want to create this draft?')) {
                e.preventDefault();
            }
        });
        
        // WORKING STEPS FUNCTIONALITY
        let currentStep = 1;
        const totalSteps = 5;
        
        function showStep(step) {
            // Hide all step content
            document.querySelectorAll('.step-content').forEach(content => {
                content.style.display = 'none';
            });
            
            // Show current step content
            document.querySelector(`.step-content[data-step="${step}"]`).style.display = 'block';
            
            // Update steps indicator
            document.querySelectorAll('.step').forEach((stepEl, index) => {
                stepEl.classList.remove('active', 'completed');
                if (index + 1 < step) {
                    stepEl.classList.add('completed');
                } else if (index + 1 === step) {
                    stepEl.classList.add('active');
                }
            });
            
            // Update navigation buttons
            document.getElementById('prevBtn').style.display = step === 1 ? 'none' : 'block';
            document.getElementById('nextBtn').style.display = step === totalSteps ? 'none' : 'block';
            document.getElementById('submitBtn').style.display = step === totalSteps ? 'block' : 'none';
            
            // Update progress
            updateProgress();
            
            // Update review summary on last step
            if (step === totalSteps) {
                updateReviewSummary();
            }
        }
        
        function nextStep() {
            if (validateStep(currentStep)) {
                if (currentStep < totalSteps) {
                    currentStep++;
                    showStep(currentStep);
                }
            }
        }
        
        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
            }
        }
        
        function validateStep(step) {
            switch(step) {
                case 1:
                    // Document type is always valid (has default)
                    return true;
                    
                case 2:
                    const title = document.getElementById('title').value.trim();
                    const description = document.getElementById('description').value.trim();
                    
                    if (!title) {
                        alert('Please enter a document title.');
                        return false;
                    }
                    
                    if (!description) {
                        alert('Please enter a document description.');
                        return false;
                    }
                    
                    return true;
                    
                case 3:
                    const content = quill.getText().trim();
                    
                    if (!content) {
                        alert('Please enter document content.');
                        return false;
                    }
                    
                    return true;
                    
                case 4:
                    // Authors and files are optional
                    return true;
                    
                case 5:
                    return true;
                    
                default:
                    return true;
            }
        }
        
        function updateProgress() {
            let progress = 0;
            
            // Calculate progress based on form completion
            if (document.querySelector('input[name="document_type"]:checked')) progress += 20;
            
            const title = document.getElementById('title').value.trim();
            const description = document.getElementById('description').value.trim();
            if (title) progress += 10;
            if (description) progress += 10;
            
            const content = quill.getText().trim();
            if (content) progress += 30;
            
            const authorsChecked = document.querySelectorAll('input[name="authors[]"]:checked').length;
            if (authorsChecked > 0) progress += 10;
            
            if (uploadedFiles.length > 0) progress += 10;
            
            // Ensure max 100%
            progress = Math.min(progress, 100);
            
            // Update progress bar
            document.getElementById('progressBar').style.width = progress + '%';
            document.getElementById('progressPercent').textContent = progress + '%';
            
            // Update current step display
            document.getElementById('currentStepDisplay').textContent = currentStep + '/5';
            
            // Update document type display
            const docType = document.querySelector('input[name="document_type"]:checked');
            document.getElementById('docTypeDisplay').textContent = docType ? (docType.value === 'ordinance' ? 'Ordinance' : 'Resolution') : 'Not selected';
            
            // Update authors count
            document.getElementById('authorsCount').textContent = authorsChecked;
        }
        
        function updateReviewSummary() {
            const title = document.getElementById('title').value.trim();
            const description = document.getElementById('description').value.trim();
            const content = quill.getText().trim();
            const docType = document.querySelector('input[name="document_type"]:checked');
            const authors = document.querySelectorAll('input[name="authors[]"]:checked').length;
            const files = uploadedFiles.length;
            
            let summaryHtml = `
                <div style="background: var(--off-white); padding: 20px; border-radius: var(--border-radius); border: 1px solid var(--gray-light);">
                    <h4 style="color: var(--qc-blue); margin-bottom: 15px;">Draft Summary</h4>
                    
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 10px; margin-bottom: 15px;">
                        <div style="font-weight: 600;">Document Type:</div>
                        <div>${docType ? (docType.value === 'ordinance' ? 'Ordinance' : 'Resolution') : 'Not selected'}</div>
                        
                        <div style="font-weight: 600;">Title:</div>
                        <div>${title || 'Not entered'}</div>
                        
                        <div style="font-weight: 600;">Description:</div>
                        <div>${description || 'Not entered'}</div>
                        
                        <div style="font-weight: 600;">Content Length:</div>
                        <div>${content.length} characters</div>
                        
                        <div style="font-weight: 600;">Authors:</div>
                        <div>${authors} selected</div>
                        
                        <div style="font-weight: 600;">Files:</div>
                        <div>${files} uploaded</div>
                    </div>
                    
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--gray-light);">
                        <p style="color: var(--qc-green); font-weight: 600;">
                            <i class="fas fa-check-circle"></i> Ready to submit!
                        </p>
                        <p style="color: var(--gray); font-size: 0.9rem;">
                            Click "Create Draft" to submit this document to the drafting system.
                        </p>
                    </div>
                </div>
            `;
            
            document.getElementById('reviewSummary').innerHTML = summaryHtml;
        }
        
        // Initialize steps functionality
        document.getElementById('nextBtn').addEventListener('click', nextStep);
        document.getElementById('prevBtn').addEventListener('click', prevStep);
        
        // Step indicator click navigation
        document.querySelectorAll('.step').forEach(stepEl => {
            stepEl.addEventListener('click', function() {
                const step = parseInt(this.getAttribute('data-step'));
                if (step < currentStep) {
                    currentStep = step;
                    showStep(currentStep);
                } else if (step === currentStep + 1) {
                    if (validateStep(currentStep)) {
                        currentStep = step;
                        showStep(currentStep);
                    }
                }
            });
        });
        
        // Form field change listeners for progress updates
        document.querySelectorAll('input[name="document_type"]').forEach(input => {
            input.addEventListener('change', updateProgress);
        });
        
        document.getElementById('title').addEventListener('input', updateProgress);
        document.getElementById('description').addEventListener('input', updateProgress);
        document.querySelectorAll('input[name="authors[]"]').forEach(input => {
            input.addEventListener('change', updateProgress);
        });
        
        // Initialize first step
        showStep(currentStep);
        
        // EDIT DRAFT MODAL FUNCTIONALITY
        const editDraftModal = document.getElementById('editDraftModal');
        const modalClose = document.getElementById('modalClose');
        const modalCancelBtn = document.getElementById('modalCancelBtn');
        let currentDraftId = null;
        let currentDraftType = null;
        
        // Open modal when edit button is clicked
        document.querySelectorAll('.edit-draft-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                currentDraftId = this.getAttribute('data-draft-id');
                currentDraftType = this.getAttribute('data-draft-type');
                const draftNumber = this.getAttribute('data-draft-number');
                const draftTitle = this.getAttribute('data-draft-title');
                const draftStatus = this.getAttribute('data-draft-status');
                
                // Load draft info into modal
                document.getElementById('modalDraftInfo').innerHTML = `
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 10px; margin-bottom: 15px;">
                        <div style="font-weight: 600;">Document Number:</div>
                        <div>${draftNumber}</div>
                        
                        <div style="font-weight: 600;">Title:</div>
                        <div>${draftTitle}</div>
                        
                        <div style="font-weight: 600;">Type:</div>
                        <div>${currentDraftType === 'ordinance' ? 'Ordinance' : 'Resolution'}</div>
                        
                        <div style="font-weight: 600;">Status:</div>
                        <div>
                            <span class="draft-status status-${draftStatus}">
                                ${draftStatus.charAt(0).toUpperCase() + draftStatus.slice(1)}
                            </span>
                        </div>
                        
                        <div style="font-weight: 600;">Last Modified:</div>
                        <div>Just now</div>
                    </div>
                `;
                
                // Load activity log (simulated)
                document.getElementById('modalActivityLog').innerHTML = `
                    <div style="font-size: 0.9rem;">
                        <div style="display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid var(--gray-light);">
                            <i class="fas fa-user-edit" style="color: var(--qc-gold);"></i>
                            <div>
                                <div>You created this draft</div>
                                <div style="color: var(--gray); font-size: 0.85rem;">Just now</div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; padding: 10px;">
                            <i class="fas fa-save" style="color: var(--qc-blue);"></i>
                            <div>
                                <div>Draft saved automatically</div>
                                <div style="color: var(--gray); font-size: 0.85rem;">A few moments ago</div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Open modal
                editDraftModal.classList.add('active');
            });
        });
        
        // Close modal
        modalClose.addEventListener('click', closeModal);
        modalCancelBtn.addEventListener('click', closeModal);
        editDraftModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        function closeModal() {
            editDraftModal.classList.remove('active');
        }
        
        // Modal action buttons
        document.getElementById('modalViewBtn').addEventListener('click', function() {
            if (currentDraftId && currentDraftType) {
                window.location.href = `edit_draft.php?type=${currentDraftType}&id=${currentDraftId}`;
            }
        });
        
        document.getElementById('modalUpdateBtn').addEventListener('click', function() {
            alert('Update feature coming soon!');
        });
        
        document.getElementById('modalVersionBtn').addEventListener('click', function() {
            alert('Version history feature coming soon!');
        });
        
        document.getElementById('modalDeleteBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to delete this draft? This action cannot be undone.')) {
                alert('Draft deleted successfully!');
                closeModal();
                // In a real application, you would redirect or reload the page
                window.location.reload();
            }
        });
        
        document.getElementById('modalContinueBtn').addEventListener('click', function() {
            if (currentDraftId && currentDraftType) {
                window.location.href = `edit_draft.php?type=${currentDraftType}&id=${currentDraftId}`;
            }
        });
        
        // Auto-save draft every 30 seconds
        let autoSaveTimer;
        function startAutoSave() {
            autoSaveTimer = setInterval(() => {
                const title = document.getElementById('title').value.trim();
                const description = document.getElementById('description').value.trim();
                
                if (title || description || quill.getText().trim()) {
                    console.log('Auto-saving draft...');
                    // In a real application, save via AJAX
                }
            }, 30000);
        }
        
        // Start auto-save
        startAutoSave();
        
        // Stop auto-save when leaving page
        window.addEventListener('beforeunload', function(e) {
            const title = document.getElementById('title').value.trim();
            const description = document.getElementById('description').value.trim();
            
            if (title || description || quill.getText().trim()) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        // Initialize template selection
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="template_id"][value=""]').closest('.template-option').classList.add('selected');
            
            // Add animation to form elements
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
            
            // Observe form sections
            document.querySelectorAll('.form-section, .recent-drafts').forEach(section => {
                observer.observe(section);
            });
        });
    </script>
</body>
</html>