<?php
// type_identification.php - Type Identification Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission (admin and super_admin only)
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
$action_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        if (isset($_POST['classify_document'])) {
            // Classify a document
            $document_id = $_POST['document_id'];
            $document_type = $_POST['document_type'];
            $classification_type = $_POST['classification_type'];
            $category_id = $_POST['category_id'];
            $priority_level = $_POST['priority_level'];
            $classification_notes = $_POST['classification_notes'] ?? '';
            
            // Check if classification already exists
            $check_query = "SELECT * FROM document_classification 
                           WHERE document_id = :doc_id AND document_type = :doc_type";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':doc_id', $document_id);
            $check_stmt->bindParam(':doc_type', $document_type);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                // Update existing classification
                $update_query = "UPDATE document_classification 
                                SET classification_type = :class_type,
                                    category_id = :cat_id,
                                    priority_level = :priority,
                                    classification_notes = :notes,
                                    classified_by = :classified_by,
                                    classified_at = NOW(),
                                    status = 'classified',
                                    updated_at = NOW()
                                WHERE document_id = :doc_id AND document_type = :doc_type";
                $stmt = $conn->prepare($update_query);
            } else {
                // Insert new classification
                $insert_query = "INSERT INTO document_classification 
                                (document_id, document_type, classification_type, category_id, 
                                 priority_level, classification_notes, classified_by, classified_at, 
                                 status, created_at, updated_at)
                                VALUES (:doc_id, :doc_type, :class_type, :cat_id, :priority, 
                                        :notes, :classified_by, NOW(), 'classified', NOW(), NOW())";
                $stmt = $conn->prepare($insert_query);
            }
            
            $stmt->bindParam(':doc_id', $document_id);
            $stmt->bindParam(':doc_type', $document_type);
            $stmt->bindParam(':class_type', $classification_type);
            $stmt->bindParam(':cat_id', $category_id);
            $stmt->bindParam(':priority', $priority_level);
            $stmt->bindParam(':notes', $classification_notes);
            $stmt->bindParam(':classified_by', $user_id);
            $stmt->execute();
            
            // Add keywords if provided
            if (isset($_POST['keywords']) && !empty($_POST['keywords'])) {
                $keywords = explode(',', $_POST['keywords']);
                foreach ($keywords as $keyword) {
                    $keyword = trim($keyword);
                    if (!empty($keyword)) {
                        $keyword_query = "INSERT INTO document_keywords 
                                         (document_id, document_type, keyword, added_by)
                                         VALUES (:doc_id, :doc_type, :keyword, :added_by)
                                         ON DUPLICATE KEY UPDATE weight = weight + 1";
                        $keyword_stmt = $conn->prepare($keyword_query);
                        $keyword_stmt->bindParam(':doc_id', $document_id);
                        $keyword_stmt->bindParam(':doc_type', $document_type);
                        $keyword_stmt->bindParam(':keyword', $keyword);
                        $keyword_stmt->bindParam(':added_by', $user_id);
                        $keyword_stmt->execute();
                    }
                }
            }
            
            $action_message = "Document successfully classified!";
            
        } elseif (isset($_POST['auto_classify'])) {
            // Auto-classify multiple documents
            $document_ids = $_POST['document_ids'];
            $document_type = $_POST['doc_type'];
            
            $classified_count = 0;
            foreach ($document_ids as $doc_id) {
                // Get document title and content for analysis
                $doc_query = "SELECT o.title, o.description, dv.content 
                             FROM ordinances o 
                             LEFT JOIN document_versions dv ON o.id = dv.document_id 
                             AND dv.document_type = 'ordinance' AND dv.is_current = 1
                             WHERE o.id = :doc_id
                             UNION ALL
                             SELECT r.title, r.description, dv.content 
                             FROM resolutions r 
                             LEFT JOIN document_versions dv ON r.id = dv.document_id 
                             AND dv.document_type = 'resolution' AND dv.is_current = 1
                             WHERE r.id = :doc_id2";
                
                $doc_stmt = $conn->prepare($doc_query);
                $doc_stmt->bindParam(':doc_id', $doc_id);
                $doc_stmt->bindParam(':doc_id2', $doc_id);
                $doc_stmt->execute();
                $document = $doc_stmt->fetch();
                
                if ($document) {
                    // Simple auto-classification logic (in real app, use NLP/AI)
                    $title = strtolower($document['title']);
                    $description = strtolower($document['description'] ?? '');
                    $content = strtolower($document['content'] ?? '');
                    
                    // Determine classification type based on keywords
                    $classification_type = 'ordinance';
                    $category_id = 1; // Default to Administrative
                    $priority_level = 'medium';
                    
                    // Keywords for ordinance vs resolution
                    $ordinance_keywords = ['regulating', 'prohibiting', 'penalty', 'ordinance', 'law', 'enact', 'prohibition'];
                    $resolution_keywords = ['resolution', 'resolved', 'recommend', 'endorse', 'express', 'support'];
                    
                    $ord_count = 0;
                    $res_count = 0;
                    
                    $all_text = $title . ' ' . $description . ' ' . $content;
                    
                    foreach ($ordinance_keywords as $keyword) {
                        if (stripos($all_text, $keyword) !== false) $ord_count++;
                    }
                    
                    foreach ($resolution_keywords as $keyword) {
                        if (stripos($all_text, $keyword) !== false) $res_count++;
                    }
                    
                    if ($res_count > $ord_count) {
                        $classification_type = 'resolution';
                    }
                    
                    // Determine category based on keywords
                    $category_keywords = [
                        'tax' => ['tax', 'fee', 'revenue', 'assessment', 'levy'],
                        'safety' => ['safety', 'police', 'fire', 'emergency', 'disaster'],
                        'health' => ['health', 'sanitation', 'hospital', 'medical', 'clinic'],
                        'education' => ['education', 'school', 'student', 'teacher', 'scholarship'],
                        'environment' => ['environment', 'waste', 'garbage', 'pollution', 'tree'],
                        'infra' => ['infrastructure', 'road', 'bridge', 'building', 'construction'],
                        'transport' => ['transport', 'traffic', 'vehicle', 'parking', 'puv'],
                        'business' => ['business', 'permit', 'trade', 'commerce', 'market']
                    ];
                    
                    $max_matches = 0;
                    foreach ($category_keywords as $cat_code => $keywords) {
                        $matches = 0;
                        foreach ($keywords as $keyword) {
                            if (stripos($all_text, $keyword) !== false) $matches++;
                        }
                        if ($matches > $max_matches) {
                            $max_matches = $matches;
                            // Find category ID
                            $cat_query = "SELECT id FROM document_categories WHERE category_code = :code";
                            $cat_stmt = $conn->prepare($cat_query);
                            $cat_stmt->bindParam(':code', $cat_code);
                            $cat_stmt->execute();
                            if ($cat_result = $cat_stmt->fetch()) {
                                $category_id = $cat_result['id'];
                            }
                        }
                    }
                    
                    // Determine priority
                    if (stripos($all_text, 'urgent') !== false || stripos($all_text, 'emergency') !== false) {
                        $priority_level = 'urgent';
                    } elseif (stripos($all_text, 'important') !== false || stripos($all_text, 'critical') !== false) {
                        $priority_level = 'high';
                    } elseif (stripos($all_text, 'routine') !== false || stripos($all_text, 'regular') !== false) {
                        $priority_level = 'low';
                    }
                    
                    // Auto-extract keywords from title and description
                    $auto_keywords = [];
                    $common_words = ['the', 'and', 'for', 'that', 'this', 'with', 'from', 'have', 'which', 'their'];
                    $words = array_merge(
                        explode(' ', $title),
                        explode(' ', $description)
                    );
                    
                    foreach ($words as $word) {
                        $clean_word = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($word));
                        if (strlen($clean_word) > 3 && !in_array($clean_word, $common_words)) {
                            $auto_keywords[] = $clean_word;
                        }
                    }
                    $auto_keywords = array_unique(array_slice($auto_keywords, 0, 10));
                    
                    // Save classification
                    $class_query = "INSERT INTO document_classification 
                                   (document_id, document_type, classification_type, category_id, 
                                    priority_level, classified_by, classified_at, status)
                                   VALUES (:doc_id, :doc_type, :class_type, :cat_id, 
                                           :priority, :classified_by, NOW(), 'classified')
                                   ON DUPLICATE KEY UPDATE 
                                   classification_type = :class_type2,
                                   category_id = :cat_id2,
                                   priority_level = :priority2,
                                   classified_by = :classified_by2,
                                   classified_at = NOW(),
                                   status = 'classified'";
                    
                    $class_stmt = $conn->prepare($class_query);
                    $class_stmt->bindParam(':doc_id', $doc_id);
                    $class_stmt->bindParam(':doc_type', $document_type);
                    $class_stmt->bindParam(':class_type', $classification_type);
                    $class_stmt->bindParam(':cat_id', $category_id);
                    $class_stmt->bindParam(':priority', $priority_level);
                    $class_stmt->bindParam(':classified_by', $user_id);
                    $class_stmt->bindParam(':class_type2', $classification_type);
                    $class_stmt->bindParam(':cat_id2', $category_id);
                    $class_stmt->bindParam(':priority2', $priority_level);
                    $class_stmt->bindParam(':classified_by2', $user_id);
                    $class_stmt->execute();
                    
                    // Save auto-generated keywords
                    foreach ($auto_keywords as $keyword) {
                        $keyword_query = "INSERT INTO document_keywords 
                                         (document_id, document_type, keyword, added_by)
                                         VALUES (:doc_id, :doc_type, :keyword, :added_by)
                                         ON DUPLICATE KEY UPDATE weight = weight + 1";
                        $keyword_stmt = $conn->prepare($keyword_query);
                        $keyword_stmt->bindParam(':doc_id', $doc_id);
                        $keyword_stmt->bindParam(':doc_type', $document_type);
                        $keyword_stmt->bindParam(':keyword', $keyword);
                        $keyword_stmt->bindParam(':added_by', $user_id);
                        $keyword_stmt->execute();
                    }
                    
                    $classified_count++;
                }
            }
            
            $action_message = "Successfully auto-classified $classified_count document(s)!";
            
        } elseif (isset($_POST['update_keywords'])) {
            // Update keywords for a document
            $document_id = $_POST['doc_id'];
            $document_type = $_POST['doc_type'];
            $keywords = $_POST['new_keywords'];
            
            // Clear existing keywords
            $clear_query = "DELETE FROM document_keywords 
                           WHERE document_id = :doc_id AND document_type = :doc_type";
            $clear_stmt = $conn->prepare($clear_query);
            $clear_stmt->bindParam(':doc_id', $document_id);
            $clear_stmt->bindParam(':doc_type', $document_type);
            $clear_stmt->execute();
            
            // Add new keywords
            $keyword_array = explode(',', $keywords);
            foreach ($keyword_array as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword)) {
                    $keyword_query = "INSERT INTO document_keywords 
                                     (document_id, document_type, keyword, added_by)
                                     VALUES (:doc_id, :doc_type, :keyword, :added_by)";
                    $keyword_stmt = $conn->prepare($keyword_query);
                    $keyword_stmt->bindParam(':doc_id', $document_id);
                    $keyword_stmt->bindParam(':doc_type', $document_type);
                    $keyword_stmt->bindParam(':keyword', $keyword);
                    $keyword_stmt->bindParam(':added_by', $user_id);
                    $keyword_stmt->execute();
                }
            }
            
            $action_message = "Keywords updated successfully!";
        }
        
        $conn->commit();
        $success_message = $action_message;
        
        // Log the action
        $action = isset($_POST['classify_document']) ? 'TYPE_CLASSIFY' : 
                 (isset($_POST['auto_classify']) ? 'TYPE_AUTO_CLASSIFY' : 'KEYWORDS_UPDATE');
        
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, :action, :desc, :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':desc', $action_message);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get documents that need classification
$unclassified_query = "
    SELECT 'ordinance' as doc_type, o.id, o.ordinance_number as doc_number, o.title, o.description, o.created_at, 
           o.created_by, u.first_name, u.last_name, dc.status as classification_status
    FROM ordinances o
    LEFT JOIN users u ON o.created_by = u.id
    LEFT JOIN document_classification dc ON o.id = dc.document_id AND dc.document_type = 'ordinance'
    WHERE dc.id IS NULL OR dc.status = 'pending'
    UNION ALL
    SELECT 'resolution' as doc_type, r.id, r.resolution_number as doc_number, r.title, r.description, r.created_at,
           r.created_by, u.first_name, u.last_name, dc.status as classification_status
    FROM resolutions r
    LEFT JOIN users u ON r.created_by = u.id
    LEFT JOIN document_classification dc ON r.id = dc.document_id AND dc.document_type = 'resolution'
    WHERE dc.id IS NULL OR dc.status = 'pending'
    ORDER BY created_at DESC
    LIMIT 50";

$unclassified_stmt = $conn->query($unclassified_query);
$unclassified_docs = $unclassified_stmt->fetchAll();

// Get recently classified documents
$classified_query = "
    SELECT dc.document_id, dc.document_type, dc.classification_type, dc.priority_level, 
           dc.classified_at, dc.status, c.category_name,
           CASE WHEN dc.document_type = 'ordinance' THEN o.ordinance_number ELSE r.resolution_number END as doc_number,
           CASE WHEN dc.document_type = 'ordinance' THEN o.title ELSE r.title END as title,
           u.first_name, u.last_name
    FROM document_classification dc
    LEFT JOIN document_categories c ON dc.category_id = c.id
    LEFT JOIN ordinances o ON dc.document_type = 'ordinance' AND dc.document_id = o.id
    LEFT JOIN resolutions r ON dc.document_type = 'resolution' AND dc.document_id = r.id
    LEFT JOIN users u ON dc.classified_by = u.id
    WHERE dc.status IN ('classified', 'reviewed', 'approved')
    ORDER BY dc.classified_at DESC
    LIMIT 20";

$classified_stmt = $conn->query($classified_query);
$classified_docs = $classified_stmt->fetchAll();

// Get all categories
$categories_query = "SELECT * FROM document_categories WHERE is_active = 1 ORDER BY category_name";
$categories_stmt = $conn->query($categories_query);
$categories = $categories_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM document_classification WHERE status = 'classified') as classified_count,
        (SELECT COUNT(*) FROM (
            SELECT o.id FROM ordinances o LEFT JOIN document_classification dc ON o.id = dc.document_id AND dc.document_type = 'ordinance' WHERE dc.id IS NULL
            UNION ALL
            SELECT r.id FROM resolutions r LEFT JOIN document_classification dc ON r.id = dc.document_id AND dc.document_type = 'resolution' WHERE dc.id IS NULL
        ) as unclassified) as unclassified_count,
        (SELECT COUNT(DISTINCT document_type) FROM document_classification) as types_count,
        (SELECT COUNT(DISTINCT category_id) FROM document_classification WHERE category_id IS NOT NULL) as categories_count";

$stats_stmt = $conn->query($stats_query);
$stats = $stats_stmt->fetch();

// Get keywords statistics
$keywords_query = "SELECT keyword, COUNT(*) as usage_count 
                  FROM document_keywords 
                  GROUP BY keyword 
                  ORDER BY usage_count DESC 
                  LIMIT 20";
$keywords_stmt = $conn->query($keywords_query);
$top_keywords = $keywords_stmt->fetchAll();

// Get documents for dropdown selection
$documents_query = "
    SELECT 'ordinance' as doc_type, id, ordinance_number as doc_number, title, created_at
    FROM ordinances
    WHERE status IN ('draft', 'pending')
    UNION ALL
    SELECT 'resolution' as doc_type, id, resolution_number as doc_number, title, created_at
    FROM resolutions
    WHERE status IN ('draft', 'pending')
    ORDER BY created_at DESC
    LIMIT 100";

$documents_stmt = $conn->query($documents_query);
$documents = $documents_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Type Identification | QC Ordinance Tracker</title>
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
            --yellow: #D97706;
            --purple: #7C3AED;
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
        
        /* MAIN GRID LAYOUT */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* CARD STYLES */
        .card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 30px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .card-title {
            font-size: 1.4rem;
            font-weight: bold;
            color: var(--qc-blue);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .card-title i {
            color: var(--qc-gold);
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
        }
        
        /* FORM STYLES */
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
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23003366' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 40px;
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        /* DOCUMENT LIST STYLES */
        .document-list {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
        }
        
        .document-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .document-item:hover {
            background: var(--off-white);
        }
        
        .document-item:last-child {
            border-bottom: none;
        }
        
        .document-checkbox {
            width: 20px;
            height: 20px;
            accent-color: var(--qc-blue);
        }
        
        .document-icon {
            width: 50px;
            height: 50px;
            background: var(--off-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .ordinance-icon {
            color: var(--qc-blue);
            background: rgba(0, 51, 102, 0.1);
        }
        
        .resolution-icon {
            color: var(--qc-green);
            background: rgba(45, 140, 71, 0.1);
        }
        
        .document-content {
            flex: 1;
        }
        
        .document-title {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 5px;
            font-size: 1rem;
        }
        
        .document-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .document-number {
            font-weight: 600;
            color: var(--qc-gold);
        }
        
        .document-type {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .type-ordinance {
            background: rgba(0, 51, 102, 0.1);
            color: var(--qc-blue);
        }
        
        .type-resolution {
            background: rgba(45, 140, 71, 0.1);
            color: var(--qc-green);
        }
        
        .document-date {
            color: var(--gray);
        }
        
        .document-actions {
            display: flex;
            gap: 10px;
        }
        
        /* BADGE STYLES */
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-classified {
            background: rgba(45, 140, 71, 0.1);
            color: var(--qc-green);
        }
        
        .badge-pending {
            background: rgba(217, 119, 6, 0.1);
            color: var(--yellow);
        }
        
        .badge-urgent {
            background: rgba(197, 48, 48, 0.1);
            color: var(--red);
        }
        
        .badge-high {
            background: rgba(217, 119, 6, 0.1);
            color: var(--yellow);
        }
        
        .badge-medium {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .badge-low {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }
        
        /* KEYWORDS CLOUD */
        .keywords-cloud {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 20px;
            min-height: 200px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            background: var(--off-white);
        }
        
        .keyword-item {
            padding: 8px 15px;
            background: var(--white);
            border: 1px solid var(--gray-light);
            border-radius: 20px;
            font-size: 0.9rem;
            color: var(--gray-dark);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .keyword-item:hover {
            background: var(--qc-blue);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .keyword-weight-1 { font-size: 0.8rem; padding: 6px 12px; }
        .keyword-weight-2 { font-size: 0.9rem; padding: 7px 14px; }
        .keyword-weight-3 { font-size: 1rem; padding: 8px 16px; background: rgba(0, 51, 102, 0.1); }
        .keyword-weight-4 { font-size: 1.1rem; padding: 9px 18px; background: rgba(0, 51, 102, 0.2); }
        .keyword-weight-5 { font-size: 1.2rem; padding: 10px 20px; background: rgba(0, 51, 102, 0.3); }
        
        /* STATISTICS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 25px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .stat-icon-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.5rem;
        }
        
        .stat-count {
            font-size: 2rem;
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
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
        
        .btn-warning {
            background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
            color: var(--white);
        }
        
        .btn-warning:hover {
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
        
        /* TABS */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--gray-light);
            margin-bottom: 25px;
        }
        
        .tab {
            padding: 12px 25px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .tab:hover {
            color: var(--qc-blue);
        }
        
        .tab.active {
            color: var(--qc-blue);
            border-bottom-color: var(--qc-gold);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
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
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .document-actions {
                flex-direction: column;
            }
            
            .stats-grid {
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
                    <p>Module 2: Classification & Organization | Type Identification</p>
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
                    <p class="sidebar-subtitle">Type Identification Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- FIXED MODULE HEADER -->
            <div class="module-header fade-in">
                <div class="module-header-content">
                    <div class="module-badge">MODULE 2: CLASSIFICATION & ORGANIZATION</div>
                    
                    <div class="module-title-wrapper">
                        <div class="module-icon">
                            <i class="fas fa-fingerprint"></i>
                        </div>
                        <div class="module-title">
                            <h1>Type Identification System</h1>
                            <p class="module-subtitle">
                                Identify and classify ordinances vs resolutions. Auto-detect document types, 
                                assign categories, set priority levels, and manage search keywords for 
                                Quezon City legislative documents.
                            </p>
                        </div>
                    </div>
                    
                    <div class="module-stats">
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['classified_count'] ?? 0; ?></h3>
                                <p>Classified Documents</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['unclassified_count'] ?? 0; ?></h3>
                                <p>Pending Classification</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-tags"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['categories_count'] ?? 0; ?></h3>
                                <p>Active Categories</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-key"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo count($top_keywords); ?></h3>
                                <p>Top Keywords</p>
                            </div>
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

            <!-- TABS NAVIGATION -->
            <div class="tabs">
                <button class="tab active" data-tab="unclassified">Pending Classification</button>
                <button class="tab" data-tab="classify">Manual Classification</button>
                <button class="tab" data-tab="classified">Classified Documents</button>
                <button class="tab" data-tab="keywords">Keyword Management</button>
            </div>

            <!-- TAB 1: PENDING CLASSIFICATION -->
            <div class="tab-content active" id="unclassified">
                <div class="card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-clock"></i>
                            Documents Pending Classification
                        </h2>
                        <div class="card-actions">
                            <button class="btn btn-warning" onclick="selectAllDocuments()">
                                <i class="fas fa-check-double"></i> Select All
                            </button>
                            <button class="btn btn-danger" onclick="deselectAllDocuments()">
                                <i class="fas fa-times-circle"></i> Deselect All
                            </button>
                        </div>
                    </div>
                    
                    <?php if (empty($unclassified_docs)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                        <i class="fas fa-check-circle" style="font-size: 3rem; margin-bottom: 15px; color: var(--qc-green);"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">All Documents Classified!</h3>
                        <p>Great job! There are no documents pending classification.</p>
                    </div>
                    <?php else: ?>
                    <form id="autoClassifyForm" method="POST">
                        <input type="hidden" name="auto_classify" value="1">
                        <input type="hidden" name="doc_type" id="docTypeField">
                        
                        <div class="document-list">
                            <?php foreach ($unclassified_docs as $doc): ?>
                            <div class="document-item">
                                <input type="checkbox" name="document_ids[]" value="<?php echo $doc['id']; ?>" 
                                       class="document-checkbox" data-doc-type="<?php echo $doc['doc_type']; ?>">
                                <div class="document-icon <?php echo $doc['doc_type'] == 'ordinance' ? 'ordinance-icon' : 'resolution-icon'; ?>">
                                    <i class="fas <?php echo $doc['doc_type'] == 'ordinance' ? 'fa-gavel' : 'fa-file-signature'; ?>"></i>
                                </div>
                                <div class="document-content">
                                    <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                    <div class="document-meta">
                                        <span class="document-number"><?php echo htmlspecialchars($doc['doc_number']); ?></span>
                                        <span class="document-type type-<?php echo $doc['doc_type']; ?>">
                                            <?php echo ucfirst($doc['doc_type']); ?>
                                        </span>
                                        <span class="document-date"><?php echo date('M d, Y', strtotime($doc['created_at'])); ?></span>
                                        <span>Created by: <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></span>
                                    </div>
                                    <?php if ($doc['description']): ?>
                                    <div style="margin-top: 8px; color: var(--gray); font-size: 0.9rem;">
                                        <?php echo htmlspecialchars(substr($doc['description'], 0, 150)); ?>...
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="document-actions">
                                    <button type="button" class="btn btn-secondary" onclick="quickClassifyModal(<?php echo $doc['id']; ?>, '<?php echo $doc['doc_type']; ?>', '<?php echo htmlspecialchars($doc['title'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-edit"></i> Classify
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-robot"></i> Auto-Classify Selected Documents
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB 2: MANUAL CLASSIFICATION -->
            <div class="tab-content" id="classify">
                <div class="card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-edit"></i>
                            Manual Document Classification
                        </h2>
                        <div class="card-actions">
                            <button type="button" class="btn btn-secondary" onclick="clearClassificationForm()">
                                <i class="fas fa-eraser"></i> Clear Form
                            </button>
                        </div>
                    </div>
                    
                    <form id="classificationForm" method="POST">
                        <input type="hidden" name="classify_document" value="1">
                        
                        <div class="form-group">
                            <label class="form-label required">Select Document</label>
                            <select name="document_id" id="documentSelect" class="form-control" required 
                                    onchange="loadDocumentInfo(this.value, this.options[this.selectedIndex].getAttribute('data-type'))">
                                <option value="">-- Select a document to classify --</option>
                                <?php foreach ($documents as $doc): ?>
                                <option value="<?php echo $doc['id']; ?>" data-type="<?php echo $doc['doc_type']; ?>">
                                    <?php echo htmlspecialchars($doc['doc_number'] . ' - ' . $doc['title']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="documentInfo" style="display: none;">
                            <div class="form-group">
                                <label class="form-label">Document Information</label>
                                <div style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); border: 1px solid var(--gray-light);">
                                    <div id="docPreview"></div>
                                </div>
                            </div>
                            
                            <input type="hidden" name="document_type" id="docTypeInput">
                            
                            <div class="form-group">
                                <label class="form-label required">Classification Type</label>
                                <select name="classification_type" class="form-control" required>
                                    <option value="">-- Select classification type --</option>
                                    <option value="ordinance">Ordinance (Local Law)</option>
                                    <option value="resolution">Resolution (Formal Expression)</option>
                                    <option value="amendment">Amendment (Modification)</option>
                                    <option value="memorandum">Memorandum (Internal Directive)</option>
                                    <option value="order">Executive Order (Administrative Order)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Subject Category</label>
                                <select name="category_id" class="form-control" required>
                                    <option value="">-- Select subject category --</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php echo htmlspecialchars($category['category_name'] . ' (' . $category['category_code'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text" style="color: var(--gray); margin-top: 5px; display: block;">
                                    Select the most appropriate category for this document's subject matter
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Priority Level</label>
                                <select name="priority_level" class="form-control" required>
                                    <option value="medium">Medium Priority (Standard)</option>
                                    <option value="low">Low Priority (Routine)</option>
                                    <option value="high">High Priority (Important)</option>
                                    <option value="urgent">Urgent (Time-sensitive)</option>
                                    <option value="emergency">Emergency (Immediate Action)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Keywords (comma-separated)</label>
                                <textarea name="keywords" class="form-control" 
                                          placeholder="Enter relevant keywords for search, e.g., traffic, parking, regulations, fees" 
                                          rows="3"></textarea>
                                <small class="form-text" style="color: var(--gray); margin-top: 5px; display: block;">
                                    These keywords will be used for searching and categorizing similar documents
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Classification Notes</label>
                                <textarea name="classification_notes" class="form-control" 
                                          placeholder="Any additional notes about this classification..." 
                                          rows="4"></textarea>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-check"></i> Save Classification
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TAB 3: CLASSIFIED DOCUMENTS -->
            <div class="tab-content" id="classified">
                <div class="card fade-in">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-check-circle"></i>
                            Recently Classified Documents
                        </h2>
                        <div class="card-actions">
                            <button class="btn btn-secondary" onclick="exportClassifications()">
                                <i class="fas fa-file-export"></i> Export Report
                            </button>
                        </div>
                    </div>
                    
                    <?php if (empty($classified_docs)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                        <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Classified Documents</h3>
                        <p>Start by classifying documents using the other tabs.</p>
                    </div>
                    <?php else: ?>
                    <div class="document-list">
                        <?php foreach ($classified_docs as $doc): ?>
                        <div class="document-item">
                            <div class="document-icon <?php echo $doc['document_type'] == 'ordinance' ? 'ordinance-icon' : 'resolution-icon'; ?>">
                                <i class="fas <?php echo $doc['document_type'] == 'ordinance' ? 'fa-gavel' : 'fa-file-signature'; ?>"></i>
                            </div>
                            <div class="document-content">
                                <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                <div class="document-meta">
                                    <span class="document-number"><?php echo htmlspecialchars($doc['doc_number']); ?></span>
                                    <span class="document-type type-<?php echo $doc['document_type']; ?>">
                                        <?php echo ucfirst($doc['document_type']); ?>
                                    </span>
                                    <span class="badge badge-<?php echo $doc['priority_level']; ?>">
                                        <?php echo ucfirst($doc['priority_level']); ?> Priority
                                    </span>
                                    <span>Classified by: <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></span>
                                    <span><?php echo date('M d, Y', strtotime($doc['classified_at'])); ?></span>
                                </div>
                                <div style="margin-top: 8px;">
                                    <span class="badge badge-classified" style="margin-right: 10px;">
                                        <?php echo ucfirst($doc['classification_type']); ?>
                                    </span>
                                    <?php if ($doc['category_name']): ?>
                                    <span style="color: var(--qc-blue); font-weight: 600;">
                                        <i class="fas fa-folder"></i> <?php echo htmlspecialchars($doc['category_name']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="document-actions">
                                <button type="button" class="btn btn-secondary" onclick="editClassification(<?php echo $doc['document_id']; ?>, '<?php echo $doc['document_type']; ?>')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB 4: KEYWORD MANAGEMENT -->
            <div class="tab-content" id="keywords">
                <div class="main-grid">
                    <!-- Keywords Cloud -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-cloud"></i>
                                Keyword Cloud
                            </h2>
                            <div class="card-actions">
                                <button class="btn btn-secondary" onclick="refreshKeywords()">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                            </div>
                        </div>
                        
                        <div class="keywords-cloud" id="keywordsCloud">
                            <?php foreach ($top_keywords as $keyword): 
                                $weight = min(5, ceil($keyword['usage_count'] / 2));
                            ?>
                            <span class="keyword-item keyword-weight-<?php echo $weight; ?>" 
                                  onclick="searchByKeyword('<?php echo htmlspecialchars($keyword['keyword']); ?>')">
                                <?php echo htmlspecialchars($keyword['keyword']); ?>
                                <span style="font-size: 0.7rem; margin-left: 5px;">(<?php echo $keyword['usage_count']; ?>)</span>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 20px; text-align: center;">
                            <p style="color: var(--gray); font-size: 0.9rem;">
                                Click on any keyword to search for documents containing that keyword
                            </p>
                        </div>
                    </div>
                    
                    <!-- Update Keywords -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-key"></i>
                                Update Document Keywords
                            </h2>
                        </div>
                        
                        <form id="updateKeywordsForm" method="POST">
                            <input type="hidden" name="update_keywords" value="1">
                            
                            <div class="form-group">
                                <label class="form-label required">Select Document</label>
                                <select name="doc_id" id="keywordDocSelect" class="form-control" required 
                                        onchange="loadDocumentKeywords(this.value, this.options[this.selectedIndex].getAttribute('data-type'))">
                                    <option value="">-- Select a document --</option>
                                    <?php foreach ($documents as $doc): ?>
                                    <option value="<?php echo $doc['id']; ?>" data-type="<?php echo $doc['doc_type']; ?>">
                                        <?php echo htmlspecialchars($doc['doc_number'] . ' - ' . $doc['title']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <input type="hidden" name="doc_type" id="keywordDocType">
                            
                            <div class="form-group">
                                <label class="form-label">Current Keywords</label>
                                <div id="currentKeywords" style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); border: 1px solid var(--gray-light); min-height: 60px;">
                                    <span style="color: var(--gray);">Select a document to view current keywords</span>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">New Keywords</label>
                                <textarea name="new_keywords" id="newKeywords" class="form-control" 
                                          placeholder="Enter new keywords (comma-separated)" 
                                          rows="3" required></textarea>
                                <small class="form-text" style="color: var(--gray); margin-top: 5px; display: block;">
                                    Separate keywords with commas. These will replace all existing keywords.
                                </small>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Keywords
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- QUICK CLASSIFY MODAL -->
    <div class="modal-overlay" id="quickClassifyModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-bolt"></i> Quick Classify Document</h2>
                <button class="modal-close" onclick="closeQuickClassifyModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="quickClassifyForm" method="POST">
                    <input type="hidden" name="classify_document" value="1">
                    <input type="hidden" name="document_id" id="modalDocId">
                    <input type="hidden" name="document_type" id="modalDocType">
                    
                    <div class="form-group">
                        <label class="form-label">Document</label>
                        <div id="modalDocTitle" style="padding: 10px 15px; background: var(--off-white); border-radius: var(--border-radius); border: 1px solid var(--gray-light); font-weight: 600;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Classification Type</label>
                        <select name="classification_type" class="form-control" required>
                            <option value="ordinance">Ordinance</option>
                            <option value="resolution">Resolution</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Priority Level</label>
                        <select name="priority_level" class="form-control" required>
                            <option value="medium">Medium Priority</option>
                            <option value="low">Low Priority</option>
                            <option value="high">High Priority</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeQuickClassifyModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Save Classification
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
                    <h3>Type Identification Module</h3>
                    <p>
                        Identify ordinance vs resolution types, auto-classify documents, 
                        assign categories and priority levels, and manage search keywords 
                        for Quezon City legislative tracking.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="classification.php"><i class="fas fa-sitemap"></i> Classification Dashboard</a></li>
                    <li><a href="categorization.php"><i class="fas fa-folder"></i> Subject Categorization</a></li>
                    <li><a href="priority.php"><i class="fas fa-flag"></i> Priority Setting</a></li>
                    <li><a href="numbering.php"><i class="fas fa-hashtag"></i> Reference Numbering</a></li>
                    <li><a href="tagging.php"><i class="fas fa-tag"></i> Keyword Tagging</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Classification Guide</a></li>
                    <li><a href="#"><i class="fas fa-question-circle"></i> Type Identification FAQ</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Technical Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback & Suggestions</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Type Identification Module - Classification & Organization System.</p>
            <p style="margin-top: 10px;">This interface is for authorized classification personnel only. All classification activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Classification Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
        
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                const tabId = this.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });
        
        // Auto-classify form handling
        document.getElementById('autoClassifyForm').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('input[name="document_ids[]"]:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one document to auto-classify.');
                return;
            }
            
            // Set document type based on first selected document
            if (checkboxes.length > 0) {
                const firstDocType = checkboxes[0].getAttribute('data-doc-type');
                document.getElementById('docTypeField').value = firstDocType;
            }
            
            if (!confirm(`Auto-classify ${checkboxes.length} document(s)? This will analyze content and assign classifications automatically.`)) {
                e.preventDefault();
            }
        });
        
        // Load document information when selected
        function loadDocumentInfo(docId, docType) {
            if (!docId || !docType) return;
            
            document.getElementById('docTypeInput').value = docType;
            document.getElementById('documentInfo').style.display = 'block';
            
            // In a real application, you would fetch document details via AJAX
            // For now, we'll just show the selected option text
            const select = document.getElementById('documentSelect');
            const selectedText = select.options[select.selectedIndex].text;
            
            document.getElementById('docPreview').innerHTML = `
                <div style="font-weight: 600; margin-bottom: 5px;">${selectedText}</div>
                <div style="color: var(--gray); font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> Ready for classification. Please select classification details below.
                </div>
            `;
        }
        
        // Load document keywords
        function loadDocumentKeywords(docId, docType) {
            if (!docId || !docType) return;
            
            document.getElementById('keywordDocType').value = docType;
            
            // In a real application, you would fetch keywords via AJAX
            // For now, we'll simulate with a loading message
            document.getElementById('currentKeywords').innerHTML = `
                <div style="color: var(--gray); font-size: 0.9rem;">
                    <i class="fas fa-spinner fa-spin"></i> Loading keywords...
                </div>
            `;
            
            // Simulate AJAX call
            setTimeout(() => {
                // This would be replaced with actual data
                const sampleKeywords = ['traffic', 'regulation', 'quezon city', 'ordinance', 'public safety'];
                document.getElementById('currentKeywords').innerHTML = `
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        ${sampleKeywords.map(keyword => 
                            `<span style="background: var(--qc-blue); color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.85rem;">
                                ${keyword}
                            </span>`
                        ).join('')}
                    </div>
                `;
                
                // Pre-fill new keywords field
                document.getElementById('newKeywords').value = sampleKeywords.join(', ');
            }, 500);
        }
        
        // Clear classification form
        function clearClassificationForm() {
            document.getElementById('classificationForm').reset();
            document.getElementById('documentInfo').style.display = 'none';
            document.getElementById('docPreview').innerHTML = '';
        }
        
        // Quick classify modal
        function quickClassifyModal(docId, docType, docTitle) {
            document.getElementById('modalDocId').value = docId;
            document.getElementById('modalDocType').value = docType;
            document.getElementById('modalDocTitle').textContent = docTitle;
            
            document.getElementById('quickClassifyModal').classList.add('active');
        }
        
        function closeQuickClassifyModal() {
            document.getElementById('quickClassifyModal').classList.remove('active');
            document.getElementById('quickClassifyForm').reset();
        }
        
        // Document selection for auto-classify
        function selectAllDocuments() {
            document.querySelectorAll('input[name="document_ids[]"]').forEach(checkbox => {
                checkbox.checked = true;
            });
        }
        
        function deselectAllDocuments() {
            document.querySelectorAll('input[name="document_ids[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
        }
        
        // Edit classification
        function editClassification(docId, docType) {
            // In a real application, this would load the classification for editing
            alert(`Editing classification for document ${docId} (${docType}). This feature would load the existing classification data for editing.`);
            
            // For now, just switch to the manual classification tab
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            document.querySelector('.tab[data-tab="classify"]').classList.add('active');
            document.getElementById('classify').classList.add('active');
            
            // Select the document in the dropdown
            document.getElementById('documentSelect').value = docId;
            loadDocumentInfo(docId, docType);
        }
        
        // Keyword search
        function searchByKeyword(keyword) {
            alert(`Searching for documents with keyword: "${keyword}". This would show search results in a real application.`);
            
            // In a real application, you would:
            // 1. Make an AJAX request to search for documents with this keyword
            // 2. Display the results in a modal or new section
        }
        
        // Export classifications
        function exportClassifications() {
            alert('Exporting classification report. This would generate a CSV/PDF report in a real application.');
            
            // In a real application, you would:
            // 1. Make an AJAX request to generate the report
            // 2. Provide download link or open in new window
        }
        
        // Refresh keywords
        function refreshKeywords() {
            alert('Refreshing keyword cloud. This would reload keyword statistics in a real application.');
            
            // In a real application, you would:
            // 1. Make an AJAX request to get updated keyword statistics
            // 2. Update the keyword cloud display
        }
        
        // Form validation
        document.getElementById('classificationForm').addEventListener('submit', function(e) {
            const docId = document.getElementById('documentSelect').value;
            if (!docId) {
                e.preventDefault();
                alert('Please select a document to classify.');
                return;
            }
            
            if (!confirm('Save this classification?')) {
                e.preventDefault();
            }
        });
        
        document.getElementById('updateKeywordsForm').addEventListener('submit', function(e) {
            const docId = document.getElementById('keywordDocSelect').value;
            if (!docId) {
                e.preventDefault();
                alert('Please select a document to update keywords.');
                return;
            }
            
            if (!confirm('Update keywords for this document? Existing keywords will be replaced.')) {
                e.preventDefault();
            }
        });
        
        // Close modal when clicking outside
        document.getElementById('quickClassifyModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeQuickClassifyModal();
            }
        });
        
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
            
            // Observe all cards and document items
            document.querySelectorAll('.card, .document-item, .stat-card').forEach(el => {
                observer.observe(el);
            });
        });
        
        // Auto-detect document type based on title
        document.getElementById('documentSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const docType = selectedOption.getAttribute('data-type');
            const text = selectedOption.text.toLowerCase();
            
            // Auto-set classification type based on document type
            const classificationSelect = document.querySelector('select[name="classification_type"]');
            if (classificationSelect) {
                if (docType === 'ordinance') {
                    classificationSelect.value = 'ordinance';
                } else if (docType === 'resolution') {
                    classificationSelect.value = 'resolution';
                }
                
                // Try to auto-detect category based on keywords in title
                const categories = document.querySelectorAll('select[name="category_id"] option');
                let bestMatch = null;
                let maxMatches = 0;
                
                const categoryKeywords = {
                    'Administrative': ['administrative', 'procedure', 'policy', 'guideline'],
                    'Revenue & Taxation': ['tax', 'fee', 'revenue', 'assessment', 'levy'],
                    'Public Safety': ['safety', 'police', 'fire', 'emergency', 'disaster'],
                    'Health & Sanitation': ['health', 'sanitation', 'hospital', 'medical'],
                    'Education': ['education', 'school', 'student', 'teacher'],
                    'Environment': ['environment', 'waste', 'garbage', 'pollution'],
                    'Infrastructure': ['infrastructure', 'road', 'bridge', 'building'],
                    'Transportation': ['transport', 'traffic', 'vehicle', 'parking']
                };
                
                categories.forEach(option => {
                    if (option.value) {
                        const categoryName = option.text.split(' (')[0];
                        if (categoryKeywords[categoryName]) {
                            let matches = 0;
                            categoryKeywords[categoryName].forEach(keyword => {
                                if (text.includes(keyword.toLowerCase())) matches++;
                            });
                            if (matches > maxMatches) {
                                maxMatches = matches;
                                bestMatch = option.value;
                            }
                        }
                    }
                });
                
                if (bestMatch) {
                    document.querySelector('select[name="category_id"]').value = bestMatch;
                }
                
                // Auto-detect priority
                if (text.includes('urgent') || text.includes('emergency')) {
                    document.querySelector('select[name="priority_level"]').value = 'urgent';
                } else if (text.includes('important') || text.includes('critical')) {
                    document.querySelector('select[name="priority_level"]').value = 'high';
                }
                
                // Auto-extract keywords from title
                const commonWords = ['the', 'and', 'for', 'that', 'this', 'with', 'quezon', 'city'];
                const words = text.split(/[\s,\-\.()]+/);
                const uniqueWords = [...new Set(words.filter(word => 
                    word.length > 3 && !commonWords.includes(word.toLowerCase())
                ))];
                
                if (uniqueWords.length > 0) {
                    document.querySelector('textarea[name="keywords"]').value = 
                        uniqueWords.slice(0, 5).map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(', ');
                }
            }
        });
    </script>
</body>
</html>