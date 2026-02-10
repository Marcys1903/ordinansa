<?php
// categorization.php - Subject Categorization Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to categorize documents
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
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

// Handle form submissions
$success_message = '';
$error_message = '';

// Handle bulk categorization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_categorize'])) {
    try {
        $conn->beginTransaction();
        
        $document_ids = $_POST['document_ids'] ?? [];
        $document_type = $_POST['document_type'];
        $category_id = $_POST['category_id'];
        $classification_notes = $_POST['classification_notes'] ?? '';
        
        $categorized_count = 0;
        
        foreach ($document_ids as $doc_id) {
            // Check if classification already exists
            $check_query = "SELECT id FROM document_classification 
                           WHERE document_id = :doc_id AND document_type = :doc_type";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':doc_id', $doc_id);
            $check_stmt->bindParam(':doc_type', $document_type);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                // Update existing classification
                $update_query = "UPDATE document_classification 
                                SET category_id = :cat_id, 
                                    classification_notes = :notes,
                                    classified_by = :user_id,
                                    classified_at = NOW(),
                                    status = 'classified',
                                    updated_at = NOW()
                                WHERE document_id = :doc_id AND document_type = :doc_type";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bindParam(':cat_id', $category_id);
                $update_stmt->bindParam(':notes', $classification_notes);
                $update_stmt->bindParam(':user_id', $user_id);
                $update_stmt->bindParam(':doc_id', $doc_id);
                $update_stmt->bindParam(':doc_type', $document_type);
                $update_stmt->execute();
            } else {
                // Insert new classification
                $insert_query = "INSERT INTO document_classification 
                                (document_id, document_type, classification_type, 
                                 category_id, classification_notes, classified_by, 
                                 classified_at, status, created_at, updated_at) 
                                VALUES (:doc_id, :doc_type, 'ordinance', 
                                        :cat_id, :notes, :user_id, 
                                        NOW(), 'classified', NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bindParam(':doc_id', $doc_id);
                $insert_stmt->bindParam(':doc_type', $document_type);
                $insert_stmt->bindParam(':cat_id', $category_id);
                $insert_stmt->bindParam(':notes', $classification_notes);
                $insert_stmt->bindParam(':user_id', $user_id);
                $insert_stmt->execute();
            }
            
            $categorized_count++;
        }
        
        $conn->commit();
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'BULK_CATEGORIZATION', 'Bulk categorized {$categorized_count} documents to category ID {$category_id}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Successfully categorized {$categorized_count} document(s)!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error during bulk categorization: " . $e->getMessage();
    }
}

// Handle single document categorization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['categorize_single'])) {
    try {
        $conn->beginTransaction();
        
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        $category_id = $_POST['category_id'];
        $classification_notes = $_POST['classification_notes'] ?? '';
        $priority_level = $_POST['priority_level'] ?? 'medium';
        
        // Get document title for logging
        $doc_table = ($document_type === 'ordinance') ? 'ordinances' : 'resolutions';
        $doc_query = "SELECT title FROM {$doc_table} WHERE id = :doc_id";
        $doc_stmt = $conn->prepare($doc_query);
        $doc_stmt->bindParam(':doc_id', $document_id);
        $doc_stmt->execute();
        $document = $doc_stmt->fetch();
        $doc_title = $document['title'] ?? 'Unknown Document';
        
        // Check if classification already exists
        $check_query = "SELECT id, category_id FROM document_classification 
                       WHERE document_id = :doc_id AND document_type = :doc_type";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':doc_id', $document_id);
        $check_stmt->bindParam(':doc_type', $document_type);
        $check_stmt->execute();
        
        $old_category_id = null;
        
        if ($check_stmt->rowCount() > 0) {
            $existing = $check_stmt->fetch();
            $old_category_id = $existing['category_id'];
            
            // Update existing classification
            $update_query = "UPDATE document_classification 
                            SET category_id = :cat_id, 
                                priority_level = :priority,
                                classification_notes = :notes,
                                classified_by = :user_id,
                                classified_at = NOW(),
                                status = 'classified',
                                updated_at = NOW()
                            WHERE document_id = :doc_id AND document_type = :doc_type";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':cat_id', $category_id);
            $update_stmt->bindParam(':priority', $priority_level);
            $update_stmt->bindParam(':notes', $classification_notes);
            $update_stmt->bindParam(':user_id', $user_id);
            $update_stmt->bindParam(':doc_id', $document_id);
            $update_stmt->bindParam(':doc_type', $document_type);
            $update_stmt->execute();
        } else {
            // Insert new classification
            $insert_query = "INSERT INTO document_classification 
                            (document_id, document_type, classification_type, 
                             category_id, priority_level, classification_notes, 
                             classified_by, classified_at, status, created_at, updated_at) 
                            VALUES (:doc_id, :doc_type, 'ordinance', 
                                    :cat_id, :priority, :notes, :user_id, 
                                    NOW(), 'classified', NOW(), NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bindParam(':doc_id', $document_id);
            $insert_stmt->bindParam(':doc_type', $document_type);
            $insert_stmt->bindParam(':cat_id', $category_id);
            $insert_stmt->bindParam(':priority', $priority_level);
            $insert_stmt->bindParam(':notes', $classification_notes);
            $insert_stmt->bindParam(':user_id', $user_id);
            $insert_stmt->execute();
        }
        
        // Log categorization in the new table (if it exists)
        try {
            $log_query = "INSERT INTO categorization_logs 
                         (document_id, document_type, old_category_id, 
                          new_category_id, categorized_by, categorized_at, notes) 
                         VALUES (:doc_id, :doc_type, :old_cat, :new_cat, :user_id, NOW(), :notes)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bindParam(':doc_id', $document_id);
            $log_stmt->bindParam(':doc_type', $document_type);
            $log_stmt->bindParam(':old_cat', $old_category_id);
            $log_stmt->bindParam(':new_cat', $category_id);
            $log_stmt->bindParam(':user_id', $user_id);
            $log_stmt->bindParam(':notes', $classification_notes);
            $log_stmt->execute();
        } catch (PDOException $e) {
            // Table might not exist yet, that's OK
        }
        
        $conn->commit();
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'CATEGORIZATION', 'Categorized document: {$doc_title} to category ID {$category_id}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Document '{$doc_title}' categorized successfully!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error categorizing document: " . $e->getMessage();
    }
}

// Handle category management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    try {
        $category_name = $_POST['category_name'];
        $category_code = $_POST['category_code'];
        $description = $_POST['description'] ?? '';
        $parent_id = $_POST['parent_id'] ?? null;
        
        $query = "INSERT INTO document_categories 
                 (category_name, category_code, description, parent_id, created_by, created_at, updated_at) 
                 VALUES (:name, :code, :desc, :parent, :user_id, NOW(), NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':name', $category_name);
        $stmt->bindParam(':code', $category_code);
        $stmt->bindParam(':desc', $description);
        $stmt->bindParam(':parent', $parent_id);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'CATEGORY_CREATE', 'Created new category: {$category_name}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Category '{$category_name}' created successfully!";
        
    } catch (PDOException $e) {
        $error_message = "Error creating category: " . $e->getMessage();
    }
}

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Get all categories
$categories_query = "SELECT * FROM document_categories WHERE is_active = 1 ORDER BY category_name";
$categories_stmt = $conn->query($categories_query);
$categories = $categories_stmt->fetchAll();

// Get uncategorized documents
$uncategorized_query = "
    SELECT 'ordinance' as doc_type, o.id, o.ordinance_number as doc_number, o.title, o.description, o.status, o.created_at
    FROM ordinances o
    LEFT JOIN document_classification dc ON dc.document_id = o.id AND dc.document_type = 'ordinance'
    WHERE dc.id IS NULL
    AND o.status IN ('draft', 'pending')
    
    UNION ALL
    
    SELECT 'resolution' as doc_type, r.id, r.resolution_number as doc_number, r.title, r.description, r.status, r.created_at
    FROM resolutions r
    LEFT JOIN document_classification dc ON dc.document_id = r.id AND dc.document_type = 'resolution'
    WHERE dc.id IS NULL
    AND r.status IN ('draft', 'pending')
    
    ORDER BY created_at DESC
    LIMIT 50
";
$uncategorized_stmt = $conn->query($uncategorized_query);
$uncategorized_docs = $uncategorized_stmt->fetchAll();

// Get categorized documents with filters
$where_conditions = [];
$params = [];

if ($filter_type !== 'all') {
    $where_conditions[] = "dc.document_type = :doc_type";
    $params[':doc_type'] = $filter_type;
}

if ($filter_category !== 'all') {
    $where_conditions[] = "dc.category_id = :category_id";
    $params[':category_id'] = $filter_category;
}

if ($filter_status !== 'all') {
    $where_conditions[] = "doc.status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($search_query)) {
    $where_conditions[] = "(doc.title LIKE :search OR doc.description LIKE :search)";
    $params[':search'] = "%{$search_query}%";
}

$where_clause = count($where_conditions) > 0 ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$categorized_query = "
    SELECT 
        dc.document_type,
        dc.document_id,
        CASE 
            WHEN dc.document_type = 'ordinance' THEN o.ordinance_number
            WHEN dc.document_type = 'resolution' THEN r.resolution_number
        END as doc_number,
        CASE 
            WHEN dc.document_type = 'ordinance' THEN o.title
            WHEN dc.document_type = 'resolution' THEN r.title
        END as title,
        CASE 
            WHEN dc.document_type = 'ordinance' THEN o.description
            WHEN dc.document_type = 'resolution' THEN r.description
        END as description,
        CASE 
            WHEN dc.document_type = 'ordinance' THEN o.status
            WHEN dc.document_type = 'resolution' THEN r.status
        END as status,
        dc.category_id,
        c.category_name,
        dc.priority_level,
        dc.classified_at,
        u.first_name,
        u.last_name,
        dc.classification_notes
    FROM document_classification dc
    LEFT JOIN document_categories c ON dc.category_id = c.id
    LEFT JOIN users u ON dc.classified_by = u.id
    LEFT JOIN ordinances o ON dc.document_type = 'ordinance' AND dc.document_id = o.id
    LEFT JOIN resolutions r ON dc.document_type = 'resolution' AND dc.document_id = r.id
    {$where_clause}
    ORDER BY dc.classified_at DESC
    LIMIT 50
";

$categorized_stmt = $conn->prepare($categorized_query);
foreach ($params as $key => $value) {
    $categorized_stmt->bindValue($key, $value);
}
$categorized_stmt->execute();
$categorized_docs = $categorized_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM ordinances WHERE status IN ('draft', 'pending')) as total_ordinances,
        (SELECT COUNT(*) FROM resolutions WHERE status IN ('draft', 'pending')) as total_resolutions,
        (SELECT COUNT(*) FROM document_classification) as categorized_count,
        (SELECT COUNT(DISTINCT category_id) FROM document_classification WHERE category_id IS NOT NULL) as categories_used,
        (SELECT COUNT(*) FROM document_categories WHERE is_active = 1) as total_categories,
        (SELECT COUNT(*) FROM (
            SELECT o.id FROM ordinances o LEFT JOIN document_classification dc ON dc.document_id = o.id AND dc.document_type = 'ordinance' WHERE dc.id IS NULL AND o.status IN ('draft', 'pending')
            UNION ALL
            SELECT r.id FROM resolutions r LEFT JOIN document_classification dc ON dc.document_id = r.id AND dc.document_type = 'resolution' WHERE dc.id IS NULL AND r.status IN ('draft', 'pending')
        ) as uncategorized) as uncategorized_count
";
$stats_stmt = $conn->query($stats_query);
$stats = $stats_stmt->fetch();

// Get category usage statistics
$category_stats_query = "
    SELECT 
        c.id,
        c.category_name,
        c.category_code,
        COUNT(dc.id) as document_count,
        ROUND(COUNT(dc.id) * 100.0 / (SELECT COUNT(*) FROM document_classification WHERE category_id IS NOT NULL), 1) as percentage
    FROM document_categories c
    LEFT JOIN document_classification dc ON c.id = dc.category_id
    WHERE c.is_active = 1
    GROUP BY c.id, c.category_name, c.category_code
    ORDER BY document_count DESC
";
$category_stats_stmt = $conn->query($category_stats_query);
$category_stats = $category_stats_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Categorization | QC Ordinance Tracker</title>
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
            --qc-orange: #F59E0B;
            --qc-red: #DC2626;
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
            max-width: 800px;
        }
        
        .module-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }
        
        .stat-item {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-item:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-5px);
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--white);
            line-height: 1;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.9);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* CONTENT SECTIONS */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .content-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--qc-blue-light);
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--qc-blue);
            font-size: 1.4rem;
            font-weight: bold;
        }
        
        .card-title i {
            color: var(--qc-gold);
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
        }
        
        /* FILTERS AND SEARCH */
        .filters-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            margin-bottom: 30px;
            border: 1px solid var(--gray-light);
        }
        
        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.9rem;
        }
        
        .filter-select {
            padding: 10px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            background: var(--white);
            color: var(--gray-dark);
            cursor: pointer;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        .search-box {
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
        }
        
        .search-button {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--qc-blue);
            font-size: 1.2rem;
            cursor: pointer;
        }
        
        /* DOCUMENT LISTS */
        .document-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .document-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .document-item:hover {
            background: var(--off-white);
        }
        
        .document-item:last-child {
            border-bottom: none;
        }
        
        .document-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .ordinance-icon {
            background: rgba(0, 51, 102, 0.1);
            color: var(--qc-blue);
        }
        
        .resolution-icon {
            background: rgba(45, 140, 71, 0.1);
            color: var(--qc-green);
        }
        
        .document-content {
            flex: 1;
        }
        
        .document-title {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 5px;
            font-size: 1.1rem;
        }
        
        .document-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .document-number {
            font-weight: 600;
            color: var(--qc-gold);
        }
        
        .document-description {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 10px;
        }
        
        .document-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* CATEGORY TAGS */
        .category-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: var(--off-white);
            border: 1px solid var(--gray-light);
            border-radius: 20px;
            font-size: 0.85rem;
            color: var(--gray-dark);
            margin-right: 8px;
            margin-bottom: 8px;
        }
        
        .category-tag i {
            color: var(--qc-gold);
            font-size: 0.8rem;
        }
        
        /* STATUS BADGES */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-pending { background: #dbeafe; color: #1e40af; }
        .status-classified { background: #d1fae5; color: #065f46; }
        .status-approved { background: #dcfce7; color: #166534; }
        
        /* PRIORITY BADGES */
        .priority-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .priority-low { background: #f3f4f6; color: #6b7280; }
        .priority-medium { background: #fef3c7; color: #92400e; }
        .priority-high { background: #fde68a; color: #92400e; }
        .priority-urgent { background: #fecaca; color: #b91c1c; }
        .priority-emergency { background: #fca5a5; color: #7f1d1d; }
        
        /* BUTTONS */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%);
            color: var(--white);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background: var(--gray-light);
            color: var(--gray-dark);
        }
        
        .btn-secondary:hover {
            background: var(--gray);
            color: var(--white);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--red) 0%, #9b2c2c 100%);
            color: var(--white);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        /* CATEGORY MANAGEMENT */
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .category-card {
            background: var(--off-white);
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .category-card:hover {
            border-color: var(--qc-blue);
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .category-name {
            font-weight: bold;
            color: var(--qc-blue);
            font-size: 1.1rem;
        }
        
        .category-code {
            background: var(--qc-blue);
            color: var(--white);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .category-description {
            color: var(--gray);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 15px;
        }
        
        .category-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--gray);
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
            max-width: 600px;
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
        }
        
        .modal-section:last-child {
            margin-bottom: 0;
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
            min-height: 100px;
            resize: vertical;
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
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
        
        .empty-state-title {
            font-size: 1.5rem;
            color: var(--gray-dark);
            margin-bottom: 10px;
        }
        
        .empty-state-text {
            font-size: 1rem;
            margin-bottom: 30px;
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
                grid-template-columns: 1fr;
            }
            
            .module-title-wrapper {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
        }
        
        @media (max-width: 768px) {
            .filters-row {
                grid-template-columns: 1fr;
            }
            
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .document-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .categories-grid {
                grid-template-columns: 1fr;
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
                    <p>Subject Categorization Module | Module 2: Classification & Organization</p>
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
                        <i class="fas fa-tags"></i> Classification Module
                    </h3>
                    <p class="sidebar-subtitle">Subject Categorization Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="categorization-container">
                <!-- MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">SUBJECT CATEGORIZATION MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-folder"></i>
                            </div>
                            <div class="module-title">
                                <h1>Subject Categorization</h1>
                                <p class="module-subtitle">
                                    Categorize ordinances and resolutions by subject, assign priority levels, 
                                    and organize documents for efficient management and retrieval.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['uncategorized_count'] ?? 0; ?></div>
                                <div class="stat-label">Awaiting Categorization</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['categorized_count'] ?? 0; ?></div>
                                <div class="stat-label">Documents Categorized</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['total_categories'] ?? 0; ?></div>
                                <div class="stat-label">Available Categories</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo $stats['categories_used'] ?? 0; ?></div>
                                <div class="stat-label">Categories in Use</div>
                            </div>
                        </div>
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

                <!-- FILTERS SECTION -->
                <div class="filters-section fade-in">
                    <h3 style="margin-bottom: 20px; color: var(--qc-blue); font-size: 1.3rem;">
                        <i class="fas fa-filter"></i> Filter Documents
                    </h3>
                    
                    <form method="GET" action="" id="filtersForm">
                        <div class="filters-row">
                            <div class="filter-group">
                                <label class="filter-label">Document Type</label>
                                <select name="type" class="filter-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="ordinance" <?php echo $filter_type === 'ordinance' ? 'selected' : ''; ?>>Ordinances Only</option>
                                    <option value="resolution" <?php echo $filter_type === 'resolution' ? 'selected' : ''; ?>>Resolutions Only</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select name="status" class="filter-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="classified" <?php echo $filter_status === 'classified' ? 'selected' : ''; ?>>Classified</option>
                                    <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Category</label>
                                <select name="category" class="filter-select" onchange="this.form.submit()">
                                    <option value="all" <?php echo $filter_category === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo $filter_category == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Search Documents</label>
                            <div class="search-box">
                                <input type="text" name="search" class="search-input" 
                                       placeholder="Search by title or description..." 
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                                <button type="submit" class="search-button">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="categorization.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear Filters
                            </a>
                            <button type="button" class="btn btn-success" onclick="openBulkCategorizationModal()">
                                <i class="fas fa-layer-group"></i> Bulk Categorize
                            </button>
                            <button type="button" class="btn btn-primary" onclick="openAddCategoryModal()">
                                <i class="fas fa-plus-circle"></i> Add Category
                            </button>
                        </div>
                    </form>
                </div>

                <!-- MAIN CONTENT GRID -->
                <div class="content-grid fade-in">
                    <!-- LEFT COLUMN: Uncategorized Documents -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-clock"></i>
                                Awaiting Categorization
                                <span class="status-badge status-draft" style="margin-left: 10px;">
                                    <?php echo count($uncategorized_docs); ?> documents
                                </span>
                            </h2>
                            <div class="card-actions">
                                <button type="button" class="btn btn-sm btn-primary" onclick="selectAllUncategorized()">
                                    <i class="fas fa-check-square"></i> Select All
                                </button>
                            </div>
                        </div>
                        
                        <form id="bulkCategorizeForm" method="POST" action="">
                            <input type="hidden" name="bulk_categorize" value="1">
                            <input type="hidden" name="document_type" id="bulkDocumentType" value="ordinance">
                            
                            <div class="document-list">
                                <?php if (empty($uncategorized_docs)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle empty-state-icon"></i>
                                    <h3 class="empty-state-title">All Caught Up!</h3>
                                    <p class="empty-state-text">All documents have been categorized.</p>
                                    <button type="button" class="btn btn-primary" onclick="location.reload()">
                                        <i class="fas fa-sync-alt"></i> Refresh List
                                    </button>
                                </div>
                                <?php else: ?>
                                <?php foreach ($uncategorized_docs as $doc): 
                                    $icon_class = $doc['doc_type'] === 'ordinance' ? 'fa-gavel ordinance-icon' : 'fa-file-signature resolution-icon';
                                    $status_class = 'status-' . $doc['status'];
                                ?>
                                <div class="document-item">
                                    <div class="document-checkbox" style="flex-shrink: 0;">
                                        <input type="checkbox" name="document_ids[]" 
                                               value="<?php echo $doc['id']; ?>" 
                                               class="uncategorized-checkbox"
                                               data-doc-type="<?php echo $doc['doc_type']; ?>">
                                    </div>
                                    <div class="document-icon <?php echo $doc['doc_type'] === 'ordinance' ? 'ordinance-icon' : 'resolution-icon'; ?>">
                                        <i class="fas <?php echo $icon_class; ?>"></i>
                                    </div>
                                    <div class="document-content">
                                        <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                        <div class="document-meta">
                                            <span class="document-number"><?php echo htmlspecialchars($doc['doc_number']); ?></span>
                                            <span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($doc['status']); ?></span>
                                            <span><?php echo date('M d, Y', strtotime($doc['created_at'])); ?></span>
                                            <span style="text-transform: capitalize;"><?php echo $doc['doc_type']; ?></span>
                                        </div>
                                        <?php if (!empty($doc['description'])): ?>
                                        <div class="document-description">
                                            <?php echo htmlspecialchars(substr($doc['description'], 0, 150)); ?>...
                                        </div>
                                        <?php endif; ?>
                                        <div class="document-actions">
                                            <button type="button" class="btn btn-sm btn-primary" 
                                                    onclick="categorizeSingleDocument(<?php echo $doc['id']; ?>, '<?php echo $doc['doc_type']; ?>', '<?php echo htmlspecialchars(addslashes($doc['title'])); ?>')">
                                                <i class="fas fa-tag"></i> Categorize
                                            </button>
                                            <a href="view_document.php?type=<?php echo $doc['doc_type']; ?>&id=<?php echo $doc['id']; ?>" 
                                               class="btn btn-sm btn-secondary" target="_blank">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($uncategorized_docs)): ?>
                            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--gray-light);">
                                <div class="form-group">
                                    <label class="form-label">Category for Selected Documents</label>
                                    <select name="category_id" class="form-control" required>
                                        <option value="">Select a category...</option>
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo $category['category_code']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Classification Notes (Optional)</label>
                                    <textarea name="classification_notes" class="form-control" 
                                              placeholder="Add notes about this categorization..." 
                                              rows="3"></textarea>
                                </div>
                                
                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-layer-group"></i> Apply Category to Selected
                                    </button>
                                    <span id="selectedCount" style="align-self: center; color: var(--gray);">
                                        0 documents selected
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- RIGHT COLUMN: Categorized Documents -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-check-circle"></i>
                                Recently Categorized
                                <span class="status-badge status-classified" style="margin-left: 10px;">
                                    <?php echo count($categorized_docs); ?> documents
                                </span>
                            </h2>
                        </div>
                        
                        <div class="document-list">
                            <?php if (empty($categorized_docs)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox empty-state-icon"></i>
                                <h3 class="empty-state-title">No Categorized Documents</h3>
                                <p class="empty-state-text">Start categorizing documents using the left panel.</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($categorized_docs as $doc): 
                                $icon_class = $doc['document_type'] === 'ordinance' ? 'fa-gavel ordinance-icon' : 'fa-file-signature resolution-icon';
                                $status_class = 'status-' . $doc['status'];
                                $priority_class = 'priority-' . ($doc['priority_level'] ?? 'medium');
                            ?>
                            <div class="document-item">
                                <div class="document-icon <?php echo $doc['document_type'] === 'ordinance' ? 'ordinance-icon' : 'resolution-icon'; ?>">
                                    <i class="fas <?php echo $icon_class; ?>"></i>
                                </div>
                                <div class="document-content">
                                    <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                    <div class="document-meta">
                                        <span class="document-number"><?php echo htmlspecialchars($doc['doc_number']); ?></span>
                                        <span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($doc['status']); ?></span>
                                        <span class="priority-badge <?php echo $priority_class; ?>">
                                            <?php echo ucfirst($doc['priority_level'] ?? 'Medium'); ?>
                                        </span>
                                        <span><?php echo date('M d, Y', strtotime($doc['classified_at'])); ?></span>
                                    </div>
                                    
                                    <div style="margin: 10px 0;">
                                        <span class="category-tag">
                                            <i class="fas fa-folder"></i>
                                            <?php echo htmlspecialchars($doc['category_name'] ?? 'Uncategorized'); ?>
                                        </span>
                                        <?php if (!empty($doc['first_name'])): ?>
                                        <span class="category-tag">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($doc['classification_notes'])): ?>
                                    <div class="document-description" style="font-style: italic;">
                                        <i class="fas fa-sticky-note" style="color: var(--qc-gold);"></i>
                                        <?php echo htmlspecialchars(substr($doc['classification_notes'], 0, 100)); ?>...
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="document-actions">
                                        <button type="button" class="btn btn-sm btn-primary" 
                                                onclick="recategorizeDocument(<?php echo $doc['document_id']; ?>, '<?php echo $doc['document_type']; ?>', <?php echo $doc['category_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['title'])); ?>')">
                                            <i class="fas fa-edit"></i> Recategorize
                                        </button>
                                        <a href="view_document.php?type=<?php echo $doc['document_type']; ?>&id=<?php echo $doc['document_id']; ?>" 
                                           class="btn btn-sm btn-secondary" target="_blank">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <?php if ($user_role === 'super_admin'): ?>
                                        <button type="button" class="btn btn-sm btn-danger" 
                                                onclick="removeCategorization(<?php echo $doc['document_id']; ?>, '<?php echo $doc['document_type']; ?>')">
                                            <i class="fas fa-times"></i> Remove
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- CATEGORY MANAGEMENT SECTION -->
                <div class="content-card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-folder-open"></i>
                            Document Categories
                            <span class="status-badge status-classified" style="margin-left: 10px;">
                                <?php echo count($categories); ?> categories
                            </span>
                        </h2>
                        <div class="card-actions">
                            <button type="button" class="btn btn-sm btn-primary" onclick="openAddCategoryModal()">
                                <i class="fas fa-plus"></i> Add Category
                            </button>
                        </div>
                    </div>
                    
                    <!-- Category Statistics -->
                    <?php if (!empty($category_stats)): ?>
                    <div style="margin-bottom: 25px;">
                        <h3 style="color: var(--qc-blue); margin-bottom: 15px; font-size: 1.1rem;">
                            <i class="fas fa-chart-pie"></i> Category Usage Statistics
                        </h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
                            <?php foreach ($category_stats as $stat): ?>
                            <div style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); border: 1px solid var(--gray-light);">
                                <div style="font-weight: bold; color: var(--qc-blue); margin-bottom: 5px;">
                                    <?php echo htmlspecialchars($stat['category_name']); ?>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                    <span style="color: var(--gray);">Documents:</span>
                                    <span style="font-weight: bold; color: var(--qc-gold);"><?php echo $stat['document_count']; ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                                    <span style="color: var(--gray);">Percentage:</span>
                                    <span style="font-weight: bold; color: var(--qc-green);"><?php echo $stat['percentage'] ?? 0; ?>%</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Categories Grid -->
                    <div class="categories-grid">
                        <?php foreach ($categories as $category): ?>
                        <div class="category-card">
                            <div class="category-header">
                                <div class="category-name"><?php echo htmlspecialchars($category['category_name']); ?></div>
                                <div class="category-code"><?php echo $category['category_code']; ?></div>
                            </div>
                            
                            <?php if (!empty($category['description'])): ?>
                            <div class="category-description">
                                <?php echo htmlspecialchars($category['description']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="category-stats">
                                <span>
                                    <i class="fas fa-file-alt" style="color: var(--qc-blue);"></i>
                                    Created by: <?php echo $category['created_by'] == 1 ? 'System' : 'Admin'; ?>
                                </span>
                                <span>
                                    <i class="fas fa-calendar-alt" style="color: var(--qc-gold);"></i>
                                    <?php echo date('M d, Y', strtotime($category['created_at'])); ?>
                                </span>
                            </div>
                            
                            <?php if ($user_role === 'super_admin'): ?>
                            <div style="margin-top: 15px; display: flex; gap: 10px;">
                                <button type="button" class="btn btn-sm btn-primary" 
                                        onclick="editCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars(addslashes($category['category_name'])); ?>', '<?php echo htmlspecialchars(addslashes($category['category_code'])); ?>', '<?php echo htmlspecialchars(addslashes($category['description'])); ?>')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        onclick="deleteCategory(<?php echo $category['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- MODALS -->
    
    <!-- Single Document Categorization Modal -->
    <div class="modal-overlay" id="categorizeModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-tag"></i> Categorize Document</h2>
                <button class="modal-close" onclick="closeCategorizeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="singleCategorizeForm" method="POST" action="">
                    <input type="hidden" name="categorize_single" value="1">
                    <input type="hidden" name="document_id" id="modalDocumentId">
                    <input type="hidden" name="document_type" id="modalDocumentType">
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-file-alt"></i>
                            Document Information
                        </h3>
                        <div id="modalDocumentInfo">
                            <!-- Document info will be loaded here -->
                        </div>
                    </div>
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-folder"></i>
                            Categorization Details
                        </h3>
                        
                        <div class="form-group">
                            <label class="form-label">Select Category *</label>
                            <select name="category_id" id="modalCategoryId" class="form-control" required>
                                <option value="">Choose a category...</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo $category['category_code']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Priority Level</label>
                            <select name="priority_level" id="modalPriorityLevel" class="form-control">
                                <option value="low">Low Priority</option>
                                <option value="medium" selected>Medium Priority</option>
                                <option value="high">High Priority</option>
                                <option value="urgent">Urgent</option>
                                <option value="emergency">Emergency</option>
                            </select>
                            <small style="color: var(--gray); display: block; margin-top: 5px;">
                                <i class="fas fa-info-circle"></i> Priority affects review timelines and notifications.
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Classification Notes (Optional)</label>
                            <textarea name="classification_notes" id="modalClassificationNotes" 
                                      class="form-control" rows="4"
                                      placeholder="Add notes about why this category was selected, any special considerations, or additional context..."></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeCategorizeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Apply Category
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bulk Categorization Modal -->
    <div class="modal-overlay" id="bulkCategorizeModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-layer-group"></i> Bulk Categorization</h2>
                <button class="modal-close" onclick="closeBulkCategorizeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-info-circle"></i>
                        Bulk Operation Information
                    </h3>
                    <div style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius);">
                        <p style="margin-bottom: 10px;">
                            <i class="fas fa-exclamation-triangle" style="color: var(--qc-orange);"></i>
                            <strong>Important:</strong> This will apply the same category to multiple documents at once.
                        </p>
                        <p style="color: var(--gray); font-size: 0.9rem;">
                            Use this feature when you need to categorize several similar documents to the same category.
                            All selected documents must be of the same type (either all ordinances or all resolutions).
                        </p>
                    </div>
                </div>
                
                <form id="bulkCategorizeModalForm" method="POST" action="">
                    <input type="hidden" name="bulk_categorize" value="1">
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-file-alt"></i>
                            Document Selection
                        </h3>
                        
                        <div class="form-group">
                            <label class="form-label">Document Type *</label>
                            <select name="document_type" id="bulkModalDocumentType" class="form-control" required>
                                <option value="">Select document type...</option>
                                <option value="ordinance">Ordinances Only</option>
                                <option value="resolution">Resolutions Only</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Select Documents *</label>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid var(--gray-light); border-radius: var(--border-radius); padding: 10px;">
                                <?php foreach ($uncategorized_docs as $doc): ?>
                                <div style="display: flex; align-items: center; gap: 10px; padding: 8px; border-bottom: 1px solid var(--gray-light);">
                                    <input type="checkbox" name="document_ids[]" 
                                           value="<?php echo $doc['id']; ?>" 
                                           class="bulk-modal-checkbox"
                                           data-doc-type="<?php echo $doc['doc_type']; ?>">
                                    <div style="flex: 1;">
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($doc['title']); ?></div>
                                        <div style="font-size: 0.85rem; color: var(--gray);">
                                            <?php echo htmlspecialchars($doc['doc_number']); ?> • <?php echo ucfirst($doc['doc_type']); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top: 10px; display: flex; gap: 10px;">
                                <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllBulkModal()">
                                    <i class="fas fa-check-square"></i> Select All
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="deselectAllBulkModal()">
                                    <i class="fas fa-square"></i> Deselect All
                                </button>
                                <span id="bulkSelectedCount" style="align-self: center; color: var(--gray);">
                                    0 documents selected
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-folder"></i>
                            Categorization Details
                        </h3>
                        
                        <div class="form-group">
                            <label class="form-label">Select Category *</label>
                            <select name="category_id" id="bulkModalCategoryId" class="form-control" required>
                                <option value="">Choose a category...</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo $category['category_code']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Classification Notes (Optional)</label>
                            <textarea name="classification_notes" id="bulkModalClassificationNotes" 
                                      class="form-control" rows="3"
                                      placeholder="Add notes about this bulk categorization..."></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeBulkCategorizeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Apply to Selected
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Category Modal -->
    <div class="modal-overlay" id="categoryModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-folder-plus"></i> <span id="categoryModalTitle">Add New Category</span></h2>
                <button class="modal-close" onclick="closeCategoryModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="categoryForm" method="POST" action="">
                    <input type="hidden" name="add_category" value="1">
                    <input type="hidden" id="categoryId" name="category_id">
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-info-circle"></i>
                            Category Information
                        </h3>
                        
                        <div class="form-group">
                            <label class="form-label">Category Name *</label>
                            <input type="text" name="category_name" id="modalCategoryName" 
                                   class="form-control" required 
                                   placeholder="e.g., Environmental Protection, Public Safety, Revenue">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Category Code *</label>
                            <input type="text" name="category_code" id="modalCategoryCode" 
                                   class="form-control" required 
                                   placeholder="e.g., ENVI, SAFETY, TAX" 
                                   style="text-transform: uppercase;">
                            <small style="color: var(--gray); display: block; margin-top: 5px;">
                                <i class="fas fa-info-circle"></i> Use 2-8 letter uppercase code (e.g., ENVI for Environmental)
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Parent Category (Optional)</label>
                            <select name="parent_id" id="modalParentCategory" class="form-control">
                                <option value="">No parent (top-level category)</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Description (Optional)</label>
                            <textarea name="description" id="modalCategoryDescription" 
                                      class="form-control" rows="4"
                                      placeholder="Describe what types of documents belong in this category..."></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeCategoryModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success" id="categoryModalSubmit">
                            <i class="fas fa-check"></i> Save Category
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>Subject Categorization Module</h3>
                    <p>
                        Categorize ordinances and resolutions by subject matter for efficient organization, 
                        retrieval, and management. Supports Quezon City's legislative document workflow.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="classification.php"><i class="fas fa-sitemap"></i> Classification Dashboard</a></li>
                    <li><a href="type_identification.php"><i class="fas fa-fingerprint"></i> Type Identification</a></li>
                    <li><a href="priority.php"><i class="fas fa-flag"></i> Priority Setting</a></li>
                    <li><a href="tagging.php"><i class="fas fa-tag"></i> Keyword Tagging</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Categorization Guide</a></li>
                    <li><a href="#"><i class="fas fa-list-alt"></i> Category Definitions</a></li>
                    <li><a href="#"><i class="fas fa-question-circle"></i> FAQ & Help</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Technical Support</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Subject Categorization Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All categorization activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Categorization Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
        
        // Document selection handling
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.uncategorized-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('selectedCount').textContent = `${count} document${count !== 1 ? 's' : ''} selected`;
            
            // Update bulk document type if all selected are same type
            if (count > 0) {
                const types = new Set();
                check.forEach(cb => types.add(cb.dataset.docType));
                if (types.size === 1) {
                    document.getElementById('bulkDocumentType').value = Array.from(types)[0];
                }
            }
        }
        
        function selectAllUncategorized() {
            const checkboxes = document.querySelectorAll('.uncategorized-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            
            checkboxes.forEach(cb => {
                cb.checked = !allChecked;
            });
            
            updateSelectedCount();
        }
        
        // Attach event listeners to checkboxes
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('.uncategorized-checkbox');
            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateSelectedCount);
            });
            
            updateSelectedCount();
        });
        
        // Bulk categorization form handling
        document.getElementById('bulkCategorizeForm').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('.uncategorized-checkbox:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one document to categorize.');
                return;
            }
            
            const types = new Set();
            checkboxes.forEach(cb => types.add(cb.dataset.docType));
            
            if (types.size > 1) {
                e.preventDefault();
                alert('Please select documents of the same type (all ordinances or all resolutions).');
                return;
            }
            
            if (!confirm(`Are you sure you want to categorize ${checkboxes.length} document(s)?`)) {
                e.preventDefault();
            }
        });
        
        // Single document categorization modal
        function categorizeSingleDocument(docId, docType, docTitle) {
            document.getElementById('modalDocumentId').value = docId;
            document.getElementById('modalDocumentType').value = docType;
            
            // Load document info
            const docInfo = `
                <div style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius);">
                    <div style="font-weight: bold; color: var(--qc-blue); margin-bottom: 5px;">${docTitle}</div>
                    <div style="display: flex; gap: 15px; font-size: 0.9rem; color: var(--gray);">
                        <span>ID: ${docId}</span>
                        <span>Type: ${docType.charAt(0).toUpperCase() + docType.slice(1)}</span>
                    </div>
                </div>
            `;
            
            document.getElementById('modalDocumentInfo').innerHTML = docInfo;
            
            // Reset form
            document.getElementById('modalCategoryId').value = '';
            document.getElementById('modalPriorityLevel').value = 'medium';
            document.getElementById('modalClassificationNotes').value = '';
            
            // Show modal
            document.getElementById('categorizeModal').classList.add('active');
        }
        
        function recategorizeDocument(docId, docType, currentCategoryId, docTitle) {
            document.getElementById('modalDocumentId').value = docId;
            document.getElementById('modalDocumentType').value = docType;
            
            // Load document info
            const docInfo = `
                <div style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius);">
                    <div style="font-weight: bold; color: var(--qc-blue); margin-bottom: 5px;">${docTitle}</div>
                    <div style="display: flex; gap: 15px; font-size: 0.9rem; color: var(--gray);">
                        <span>ID: ${docId}</span>
                        <span>Type: ${docType.charAt(0).toUpperCase() + docType.slice(1)}</span>
                        <span><i class="fas fa-info-circle"></i> Already categorized</span>
                    </div>
                </div>
            `;
            
            document.getElementById('modalDocumentInfo').innerHTML = docInfo;
            
            // Set current category
            document.getElementById('modalCategoryId').value = currentCategoryId;
            document.getElementById('modalPriorityLevel').value = 'medium';
            document.getElementById('modalClassificationNotes').value = '';
            
            // Show modal
            document.getElementById('categorizeModal').classList.add('active');
        }
        
        function closeCategorizeModal() {
            document.getElementById('categorizeModal').classList.remove('active');
        }
        
        // Bulk categorization modal
        function openBulkCategorizationModal() {
            // Reset form
            document.getElementById('bulkModalDocumentType').value = '';
            document.getElementById('bulkModalCategoryId').value = '';
            document.getElementById('bulkModalClassificationNotes').value = '';
            
            // Clear all selections
            document.querySelectorAll('.bulk-modal-checkbox').forEach(cb => {
                cb.checked = false;
            });
            
            updateBulkSelectedCount();
            
            // Show modal
            document.getElementById('bulkCategorizeModal').classList.add('active');
        }
        
        function closeBulkCategorizeModal() {
            document.getElementById('bulkCategorizeModal').classList.remove('active');
        }
        
        function selectAllBulkModal() {
            document.querySelectorAll('.bulk-modal-checkbox').forEach(cb => {
                cb.checked = true;
            });
            updateBulkSelectedCount();
        }
        
        function deselectAllBulkModal() {
            document.querySelectorAll('.bulk-modal-checkbox').forEach(cb => {
                cb.checked = false;
            });
            updateBulkSelectedCount();
        }
        
        function updateBulkSelectedCount() {
            const checkboxes = document.querySelectorAll('.bulk-modal-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('bulkSelectedCount').textContent = `${count} document${count !== 1 ? 's' : ''} selected`;
        }
        
        // Attach event listeners to bulk modal checkboxes
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('.bulk-modal-checkbox');
            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateBulkSelectedCount);
            });
            
            updateBulkSelectedCount();
        });
        
        // Bulk modal form validation
        document.getElementById('bulkCategorizeModalForm').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('.bulk-modal-checkbox:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one document to categorize.');
                return;
            }
            
            const selectedDocType = document.getElementById('bulkModalDocumentType').value;
            if (!selectedDocType) {
                e.preventDefault();
                alert('Please select a document type.');
                return;
            }
            
            // Verify all selected documents match the selected type
            const mismatched = Array.from(checkboxes).filter(cb => cb.dataset.docType !== selectedDocType);
            if (mismatched.length > 0) {
                e.preventDefault();
                alert(`Some selected documents don't match the selected type (${selectedDocType}). Please check your selection.`);
                return;
            }
            
            if (!confirm(`Are you sure you want to categorize ${checkboxes.length} document(s)?`)) {
                e.preventDefault();
            }
        });
        
        // Category management modal
        function openAddCategoryModal() {
            document.getElementById('categoryModalTitle').textContent = 'Add New Category';
            document.getElementById('categoryForm').action = '';
            document.getElementById('categoryForm').method = 'POST';
            document.getElementById('categoryId').value = '';
            document.getElementById('modalCategoryName').value = '';
            document.getElementById('modalCategoryCode').value = '';
            document.getElementById('modalParentCategory').value = '';
            document.getElementById('modalCategoryDescription').value = '';
            document.getElementById('categoryModalSubmit').innerHTML = '<i class="fas fa-check"></i> Add Category';
            
            document.getElementById('categoryModal').classList.add('active');
        }
        
        function editCategory(categoryId, categoryName, categoryCode, description) {
            document.getElementById('categoryModalTitle').textContent = 'Edit Category';
            document.getElementById('categoryForm').action = 'update_category.php';
            document.getElementById('categoryForm').method = 'POST';
            document.getElementById('categoryId').value = categoryId;
            document.getElementById('modalCategoryName').value = categoryName;
            document.getElementById('modalCategoryCode').value = categoryCode;
            document.getElementById('modalCategoryDescription').value = description;
            document.getElementById('categoryModalSubmit').innerHTML = '<i class="fas fa-check"></i> Update Category';
            
            // Note: In a full implementation, you would need to fetch the current parent_id
            // For now, we'll leave it blank
            document.getElementById('modalParentCategory').value = '';
            
            document.getElementById('categoryModal').classList.add('active');
        }
        
        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.remove('active');
        }
        
        function deleteCategory(categoryId) {
            if (confirm('Are you sure you want to delete this category? This action cannot be undone.')) {
                // In a real implementation, you would submit a form or make an AJAX request
                window.location.href = `delete_category.php?id=${categoryId}`;
            }
        }
        
        // Remove categorization
        function removeCategorization(docId, docType) {
            if (confirm('Are you sure you want to remove the categorization from this document? The document will return to the uncategorized list.')) {
                // In a real implementation, you would submit a form or make an AJAX request
                window.location.href = `remove_categorization.php?doc_id=${docId}&doc_type=${docType}`;
            }
        }
        
        // Category form validation
        document.getElementById('categoryForm').addEventListener('submit', function(e) {
            const categoryName = document.getElementById('modalCategoryName').value.trim();
            const categoryCode = document.getElementById('modalCategoryCode').value.trim();
            
            if (!categoryName) {
                e.preventDefault();
                alert('Please enter a category name.');
                return;
            }
            
            if (!categoryCode) {
                e.preventDefault();
                alert('Please enter a category code.');
                return;
            }
            
            // Validate category code format
            const codeRegex = /^[A-Z]{2,8}$/;
            if (!codeRegex.test(categoryCode.toUpperCase())) {
                e.preventDefault();
                alert('Category code must be 2-8 uppercase letters (e.g., ENVI, SAFETY, TAX).');
                return;
            }
        });
        
        // Auto-uppercase category code
        document.getElementById('modalCategoryCode').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
        
        // Single categorization form validation
        document.getElementById('singleCategorizeForm').addEventListener('submit', function(e) {
            const categoryId = document.getElementById('modalCategoryId').value;
            
            if (!categoryId) {
                e.preventDefault();
                alert('Please select a category.');
                return;
            }
            
            if (!confirm('Apply this category to the document?')) {
                e.preventDefault();
            }
        });
        
        // Auto-refresh page after 5 minutes of inactivity
        let activityTimer;
        function resetActivityTimer() {
            clearTimeout(activityTimer);
            activityTimer = setTimeout(() => {
                location.reload();
            }, 300000); // 5 minutes
        }
        
        // Reset timer on user activity
        ['click', 'keypress', 'scroll', 'mousemove'].forEach(event => {
            document.addEventListener(event, resetActivityTimer);
        });
        
        // Initialize timer
        resetActivityTimer();
        
        // Add animation to elements when they come into view
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
        
        // Observe content cards
        document.querySelectorAll('.content-card').forEach(card => {
            observer.observe(card);
        });
    </script>
</body>
</html>