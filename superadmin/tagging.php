<?php
// tagging.php - Keyword Tagging Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission for keyword tagging
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

// Handle form submissions
$success_message = '';
$error_message = '';

// Handle keyword addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_keywords'])) {
    try {
        $conn->beginTransaction();
        
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        $keywords = explode(',', $_POST['keywords']);
        $weights = $_POST['weights'] ?? [];
        
        foreach ($keywords as $index => $keyword) {
            $keyword = trim($keyword);
            if (!empty($keyword)) {
                // Check if keyword already exists for this document
                $check_query = "SELECT id FROM document_keywords 
                               WHERE document_id = :doc_id 
                               AND document_type = :doc_type 
                               AND keyword = :keyword";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':doc_id', $document_id);
                $check_stmt->bindParam(':doc_type', $document_type);
                $check_stmt->bindParam(':keyword', $keyword);
                $check_stmt->execute();
                
                if ($check_stmt->rowCount() === 0) {
                    // Insert new keyword
                    $weight = isset($weights[$index]) ? intval($weights[$index]) : 1;
                    $insert_query = "INSERT INTO document_keywords 
                                    (document_id, document_type, keyword, weight, added_by) 
                                    VALUES (:doc_id, :doc_type, :keyword, :weight, :added_by)";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bindParam(':doc_id', $document_id);
                    $insert_stmt->bindParam(':doc_type', $document_type);
                    $insert_stmt->bindParam(':keyword', $keyword);
                    $insert_stmt->bindParam(':weight', $weight);
                    $insert_stmt->bindParam(':added_by', $user_id);
                    $insert_stmt->execute();
                    
                    $keyword_id = $conn->lastInsertId();
                    
                    // Log tagging action
                    $log_query = "INSERT INTO tagging_history 
                                 (document_id, document_type, action_type, keyword_id, keyword_text, performed_by) 
                                 VALUES (:doc_id, :doc_type, 'added', :keyword_id, :keyword, :performed_by)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bindParam(':doc_id', $document_id);
                    $log_stmt->bindParam(':doc_type', $document_type);
                    $log_stmt->bindParam(':keyword_id', $keyword_id);
                    $log_stmt->bindParam(':keyword', $keyword);
                    $log_stmt->bindParam(':performed_by', $user_id);
                    $log_stmt->execute();
                    
                    // Update keyword suggestion usage count
                    $update_suggestion_query = "UPDATE keyword_suggestions 
                                               SET usage_count = usage_count + 1 
                                               WHERE keyword = :keyword";
                    $update_stmt = $conn->prepare($update_suggestion_query);
                    $update_stmt->bindParam(':keyword', $keyword);
                    $update_stmt->execute();
                }
            }
        }
        
        $conn->commit();
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'KEYWORD_TAGGING', 'Added keywords to document ID: {$document_id}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Keywords added successfully!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error adding keywords: " . $e->getMessage();
    }
}

// Handle keyword removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_keyword'])) {
    try {
        $conn->beginTransaction();
        
        $keyword_id = $_POST['keyword_id'];
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        
        // Get keyword info before deletion
        $get_keyword_query = "SELECT keyword FROM document_keywords WHERE id = :id";
        $get_stmt = $conn->prepare($get_keyword_query);
        $get_stmt->bindParam(':id', $keyword_id);
        $get_stmt->execute();
        $keyword_info = $get_stmt->fetch();
        
        // Log removal action
        $log_query = "INSERT INTO tagging_history 
                     (document_id, document_type, action_type, keyword_id, keyword_text, performed_by) 
                     VALUES (:doc_id, :doc_type, 'removed', :keyword_id, :keyword, :performed_by)";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->bindParam(':doc_id', $document_id);
        $log_stmt->bindParam(':doc_type', $document_type);
        $log_stmt->bindParam(':keyword_id', $keyword_id);
        $log_stmt->bindParam(':keyword', $keyword_info['keyword']);
        $log_stmt->bindParam(':performed_by', $user_id);
        $log_stmt->execute();
        
        // Remove the keyword
        $delete_query = "DELETE FROM document_keywords WHERE id = :id";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bindParam(':id', $keyword_id);
        $delete_stmt->execute();
        
        $conn->commit();
        
        $success_message = "Keyword removed successfully!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error removing keyword: " . $e->getMessage();
    }
}

// Handle weight update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_weight'])) {
    try {
        $keyword_id = $_POST['keyword_id'];
        $new_weight = $_POST['weight'];
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        
        // Get old weight
        $get_old_query = "SELECT keyword, weight FROM document_keywords WHERE id = :id";
        $get_old_stmt = $conn->prepare($get_old_query);
        $get_old_stmt->bindParam(':id', $keyword_id);
        $get_old_stmt->execute();
        $old_data = $get_old_stmt->fetch();
        
        // Update weight
        $update_query = "UPDATE document_keywords SET weight = :weight WHERE id = :id";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':weight', $new_weight);
        $update_stmt->bindParam(':id', $keyword_id);
        $update_stmt->execute();
        
        // Log modification
        $log_query = "INSERT INTO tagging_history 
                     (document_id, document_type, action_type, keyword_id, keyword_text, performed_by, notes) 
                     VALUES (:doc_id, :doc_type, 'modified', :keyword_id, :keyword, :performed_by, :notes)";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->bindParam(':doc_id', $document_id);
        $log_stmt->bindParam(':doc_type', $document_type);
        $log_stmt->bindParam(':keyword_id', $keyword_id);
        $log_stmt->bindParam(':keyword', $old_data['keyword']);
        $log_stmt->bindParam(':performed_by', $user_id);
        $notes = "Weight changed from {$old_data['weight']} to {$new_weight}";
        $log_stmt->bindParam(':notes', $notes);
        $log_stmt->execute();
        
        $success_message = "Keyword weight updated successfully!";
        
    } catch (PDOException $e) {
        $error_message = "Error updating weight: " . $e->getMessage();
    }
}

// Handle keyword suggestion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suggest_keyword'])) {
    try {
        $keyword = trim($_POST['suggested_keyword']);
        $category_id = $_POST['category_id'] ?? null;
        $document_type = $_POST['suggest_document_type'] ?? 'all';
        
        // Check if suggestion already exists
        $check_query = "SELECT id FROM keyword_suggestions WHERE keyword = :keyword";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':keyword', $keyword);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() === 0) {
            $insert_query = "INSERT INTO keyword_suggestions 
                           (keyword, category_id, document_type, suggested_by) 
                           VALUES (:keyword, :category_id, :document_type, :suggested_by)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bindParam(':keyword', $keyword);
            $insert_stmt->bindParam(':category_id', $category_id);
            $insert_stmt->bindParam(':document_type', $document_type);
            $insert_stmt->bindParam(':suggested_by', $user_id);
            $insert_stmt->execute();
            
            $success_message = "Keyword suggestion submitted successfully!";
        } else {
            $success_message = "This keyword suggestion already exists.";
        }
        
    } catch (PDOException $e) {
        $error_message = "Error suggesting keyword: " . $e->getMessage();
    }
}

// Get document statistics
$documents_query = "SELECT 
    (SELECT COUNT(*) FROM ordinances) as total_ordinances,
    (SELECT COUNT(*) FROM resolutions) as total_resolutions,
    (SELECT COUNT(*) FROM document_keywords) as total_keywords,
    (SELECT COUNT(DISTINCT keyword) FROM document_keywords) as unique_keywords";
$documents_stmt = $conn->query($documents_query);
$documents_stats = $documents_stmt->fetch();

// Get recent tagging activities
$recent_activities_query = "SELECT th.*, u.first_name, u.last_name,
                           CASE 
                               WHEN th.document_type = 'ordinance' THEN o.title
                               WHEN th.document_type = 'resolution' THEN r.title
                           END as document_title
                           FROM tagging_history th
                           LEFT JOIN users u ON th.performed_by = u.id
                           LEFT JOIN ordinances o ON th.document_id = o.id AND th.document_type = 'ordinance'
                           LEFT JOIN resolutions r ON th.document_id = r.id AND th.document_type = 'resolution'
                           ORDER BY th.performed_at DESC LIMIT 10";
$recent_activities_stmt = $conn->query($recent_activities_query);
$recent_activities = $recent_activities_stmt->fetchAll();

// Get popular keywords
$popular_keywords_query = "SELECT keyword, COUNT(*) as usage_count, 
                          AVG(weight) as avg_weight
                          FROM document_keywords 
                          GROUP BY keyword 
                          ORDER BY usage_count DESC, avg_weight DESC 
                          LIMIT 20";
$popular_keywords_stmt = $conn->query($popular_keywords_query);
$popular_keywords = $popular_keywords_stmt->fetchAll();

// Get keyword suggestions
$keyword_suggestions_query = "SELECT ks.*, kc.category_name, u.first_name, u.last_name
                             FROM keyword_suggestions ks
                             LEFT JOIN keyword_categories kc ON ks.category_id = kc.id
                             LEFT JOIN users u ON ks.suggested_by = u.id
                             ORDER BY ks.usage_count DESC, ks.created_at DESC";
$keyword_suggestions_stmt = $conn->query($keyword_suggestions_query);
$keyword_suggestions = $keyword_suggestions_stmt->fetchAll();

// Get keyword categories
$keyword_categories_query = "SELECT * FROM keyword_categories ORDER BY category_name";
$keyword_categories_stmt = $conn->query($keyword_categories_query);
$keyword_categories = $keyword_categories_stmt->fetchAll();

// Get untagged documents
$untagged_query = "(
    SELECT 'ordinance' as doc_type, id, ordinance_number as doc_number, title, created_at
    FROM ordinances o
    WHERE NOT EXISTS (
        SELECT 1 FROM document_keywords dk 
        WHERE dk.document_id = o.id AND dk.document_type = 'ordinance'
    )
    AND o.status != 'draft'
    ORDER BY created_at DESC LIMIT 10
) UNION ALL (
    SELECT 'resolution' as doc_type, id, resolution_number as doc_number, title, created_at
    FROM resolutions r
    WHERE NOT EXISTS (
        SELECT 1 FROM document_keywords dk 
        WHERE dk.document_id = r.id AND dk.document_type = 'resolution'
    )
    AND r.status != 'draft'
    ORDER BY created_at DESC LIMIT 10
) ORDER BY created_at DESC LIMIT 15";
$untagged_stmt = $conn->query($untagged_query);
$untagged_documents = $untagged_stmt->fetchAll();

// Get recently tagged documents
$recently_tagged_query = "SELECT dk.document_id, dk.document_type,
                         CASE 
                             WHEN dk.document_type = 'ordinance' THEN o.title
                             WHEN dk.document_type = 'resolution' THEN r.title
                         END as document_title,
                         CASE 
                             WHEN dk.document_type = 'ordinance' THEN o.ordinance_number
                             WHEN dk.document_type = 'resolution' THEN r.resolution_number
                         END as document_number,
                         MAX(dk.added_at) as last_tagged,
                         COUNT(DISTINCT dk.keyword) as keyword_count
                         FROM document_keywords dk
                         LEFT JOIN ordinances o ON dk.document_id = o.id AND dk.document_type = 'ordinance'
                         LEFT JOIN resolutions r ON dk.document_id = r.id AND dk.document_type = 'resolution'
                         GROUP BY dk.document_id, dk.document_type
                         ORDER BY last_tagged DESC LIMIT 15";
$recently_tagged_stmt = $conn->query($recently_tagged_query);
$recently_tagged_documents = $recently_tagged_stmt->fetchAll();

// Get user's recent tagging activities
$user_recent_query = "SELECT COUNT(*) as user_tag_count 
                     FROM document_keywords 
                     WHERE added_by = :user_id";
$user_recent_stmt = $conn->prepare($user_recent_query);
$user_recent_stmt->bindParam(':user_id', $user_id);
$user_recent_stmt->execute();
$user_stats = $user_recent_stmt->fetch();

// Log page view
$view_query = "INSERT INTO document_views (document_type, viewed_by, search_terms) 
               VALUES ('system', :user_id, 'tagging_module')";
$view_stmt = $conn->prepare($view_query);
$view_stmt->bindParam(':user_id', $user_id);
$view_stmt->execute();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keyword Tagging | QC Ordinance Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
    <style>
        /* Use the same CSS variables and base styles from draft_creation.php */
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
        
        /* Tagging Interface Styles */
        .tagging-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .tagging-container {
                grid-template-columns: 1fr;
            }
        }
        
        .tagging-main {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .tagging-sidebar {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .tagging-section {
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .tagging-section:last-child {
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
        
        /* Form Styles */
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
        
        .select2-container--default .select2-selection--multiple {
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            min-height: 46px;
            padding: 5px;
        }
        
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        /* Keyword Tags */
        .keyword-tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }
        
        .keyword-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--qc-blue-light);
            color: var(--white);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        
        .keyword-tag:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .keyword-weight {
            background: rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .tag-actions {
            display: flex;
            gap: 5px;
        }
        
        .tag-action-btn {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.8);
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            padding: 2px;
        }
        
        .tag-action-btn:hover {
            color: var(--white);
            transform: scale(1.2);
        }
        
        /* Weight Slider */
        .weight-slider-container {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
        }
        
        .weight-slider {
            flex: 1;
            height: 8px;
            -webkit-appearance: none;
            appearance: none;
            background: var(--gray-light);
            border-radius: 4px;
            outline: none;
        }
        
        .weight-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--qc-blue);
            cursor: pointer;
            border: 2px solid var(--white);
            box-shadow: var(--shadow-sm);
        }
        
        .weight-value {
            font-weight: bold;
            color: var(--qc-blue);
            min-width: 30px;
            text-align: center;
        }
        
        /* Document List */
        .documents-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            margin-top: 15px;
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
        
        .document-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: var(--off-white);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-blue);
            font-size: 1.2rem;
        }
        
        .document-info {
            flex: 1;
        }
        
        .document-title {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 5px;
            font-size: 0.95rem;
        }
        
        .document-meta {
            display: flex;
            gap: 10px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .document-number {
            color: var(--qc-gold);
            font-weight: 600;
        }
        
        .keyword-count {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--off-white);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        /* Suggestions List */
        .suggestions-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            margin-top: 15px;
        }
        
        .suggestion-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .suggestion-item:hover {
            background: var(--off-white);
        }
        
        .suggestion-item:last-child {
            border-bottom: none;
        }
        
        .suggestion-keyword {
            flex: 1;
            font-weight: 500;
            color: var(--gray-dark);
        }
        
        .suggestion-category {
            font-size: 0.8rem;
            color: var(--gray);
            background: var(--off-white);
            padding: 2px 8px;
            border-radius: 10px;
        }
        
        .suggestion-usage {
            font-size: 0.8rem;
            color: var(--qc-blue);
            font-weight: 600;
        }
        
        /* Activity Log */
        .activity-log {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            margin-top: 15px;
        }
        
        .activity-item {
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            background: var(--off-white);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-content {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--off-white);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-blue);
            font-size: 1rem;
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-text {
            color: var(--gray-dark);
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .activity-meta {
            display: flex;
            gap: 10px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .activity-user {
            color: var(--qc-blue);
            font-weight: 600;
        }
        
        .activity-time {
            color: var(--gray);
        }
        
        /* Tag Clouds */
        .tag-cloud {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
            justify-content: center;
            padding: 20px;
            background: var(--off-white);
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
        }
        
        .cloud-tag {
            display: inline-block;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: var(--white);
        }
        
        .cloud-tag:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        /* Search Box */
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        .search-button {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--qc-blue);
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        /* Buttons */
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
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 0.9rem;
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
        
        /* Modal Styles */
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
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
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
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        .mobile-menu-toggle {
            display: none;
        }
        
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
                    <p>Keyword Tagging Module | Document Classification</p>
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
                        <i class="fas fa-tag"></i> Tagging Module
                    </h3>
                    <p class="sidebar-subtitle">Keyword Management Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="tagging-module-container">
                <!-- MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">KEYWORD TAGGING MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-tags"></i>
                            </div>
                            <div class="module-title">
                                <h1>Document Keyword Tagging</h1>
                                <p class="module-subtitle">
                                    Add, manage, and organize keywords for ordinances and resolutions. 
                                    Improve searchability and document classification with intelligent tagging.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $documents_stats['total_ordinances'] + $documents_stats['total_resolutions']; ?></h3>
                                    <p>Total Documents</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-tag"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $documents_stats['total_keywords']; ?></h3>
                                    <p>Total Keywords</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-hashtag"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $documents_stats['unique_keywords']; ?></h3>
                                    <p>Unique Keywords</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-user-edit"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $user_stats['user_tag_count']; ?></h3>
                                    <p>Your Tags</p>
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

                <!-- Tagging Interface -->
                <div class="tagging-container fade-in">
                    <!-- Main Tagging Area -->
                    <div class="tagging-main">
                        <!-- Document Selection -->
                        <div class="tagging-section">
                            <h3 class="section-title">
                                <i class="fas fa-search"></i>
                                1. Select Document
                            </h3>
                            
                            <div class="search-box">
                                <input type="text" id="documentSearch" class="search-input" 
                                       placeholder="Search ordinances and resolutions by title, number, or keyword...">
                                <button class="search-button">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                            
                            <div class="documents-list" id="documentsList">
                                <?php if (empty($untagged_documents) && empty($recently_tagged_documents)): ?>
                                <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                                    <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                                    <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Documents Found</h3>
                                    <p>All documents have been tagged or no documents available for tagging.</p>
                                </div>
                                <?php else: ?>
                                    <!-- Untagged Documents -->
                                    <?php if (!empty($untagged_documents)): ?>
                                    <div style="padding: 15px; background: var(--off-white); border-bottom: 2px solid var(--red);">
                                        <h4 style="color: var(--red); margin-bottom: 10px;">
                                            <i class="fas fa-exclamation-circle"></i> Needs Tagging
                                        </h4>
                                    </div>
                                    <?php foreach ($untagged_documents as $doc): 
                                        $icon = $doc['doc_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                                        $type_color = $doc['doc_type'] === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
                                    ?>
                                    <div class="document-item" data-document-id="<?php echo $doc['id']; ?>" 
                                         data-document-type="<?php echo $doc['doc_type']; ?>"
                                         data-document-title="<?php echo htmlspecialchars($doc['title']); ?>"
                                         data-document-number="<?php echo htmlspecialchars($doc['doc_number']); ?>">
                                        <div class="document-icon" style="color: <?php echo $type_color; ?>;">
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="document-info">
                                            <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                            <div class="document-meta">
                                                <span class="document-number"><?php echo htmlspecialchars($doc['doc_number']); ?></span>
                                                <span><?php echo date('M d, Y', strtotime($doc['created_at'])); ?></span>
                                                <span class="keyword-count">
                                                    <i class="fas fa-tag"></i> 0
                                                </span>
                                            </div>
                                        </div>
                                        <button class="btn btn-sm btn-primary tag-document-btn">
                                            <i class="fas fa-plus"></i> Tag
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                    
                                    <!-- Recently Tagged Documents -->
                                    <?php if (!empty($recently_tagged_documents)): ?>
                                    <div style="padding: 15px; background: var(--off-white); border-bottom: 2px solid var(--qc-green); margin-top: 20px;">
                                        <h4 style="color: var(--qc-green); margin-bottom: 10px;">
                                            <i class="fas fa-check-circle"></i> Recently Tagged
                                        </h4>
                                    </div>
                                    <?php foreach ($recently_tagged_documents as $doc): 
                                        $icon = $doc['document_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                                        $type_color = $doc['document_type'] === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
                                    ?>
                                    <div class="document-item" data-document-id="<?php echo $doc['document_id']; ?>" 
                                         data-document-type="<?php echo $doc['document_type']; ?>"
                                         data-document-title="<?php echo htmlspecialchars($doc['document_title']); ?>"
                                         data-document-number="<?php echo htmlspecialchars($doc['document_number']); ?>">
                                        <div class="document-icon" style="color: <?php echo $type_color; ?>;">
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="document-info">
                                            <div class="document-title"><?php echo htmlspecialchars($doc['document_title']); ?></div>
                                            <div class="document-meta">
                                                <span class="document-number"><?php echo htmlspecialchars($doc['document_number']); ?></span>
                                                <span><?php echo date('M d, Y', strtotime($doc['last_tagged'])); ?></span>
                                                <span class="keyword-count">
                                                    <i class="fas fa-tag"></i> <?php echo $doc['keyword_count']; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <button class="btn btn-sm btn-secondary tag-document-btn">
                                            <i class="fas fa-edit"></i> Edit Tags
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Keyword Tagging Form -->
                        <div class="tagging-section" id="taggingFormSection" style="display: none;">
                            <h3 class="section-title">
                                <i class="fas fa-tag"></i>
                                2. Add Keywords
                            </h3>
                            
                            <form id="taggingForm" method="POST">
                                <input type="hidden" id="documentId" name="document_id">
                                <input type="hidden" id="documentType" name="document_type">
                                
                                <div class="form-group">
                                    <label class="form-label">Document</label>
                                    <div id="selectedDocumentInfo" style="padding: 15px; background: var(--off-white); border-radius: var(--border-radius); margin-bottom: 15px;">
                                        <h4 id="documentTitleDisplay" style="color: var(--qc-blue); margin-bottom: 5px;"></h4>
                                        <div style="display: flex; gap: 15px; font-size: 0.9rem; color: var(--gray);">
                                            <span id="documentNumberDisplay"></span>
                                            <span id="documentTypeDisplay"></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Enter Keywords</label>
                                    <select id="keywordsInput" class="form-control" multiple="multiple" style="width: 100%;">
                                        <!-- Keywords will be populated by Select2 -->
                                    </select>
                                    <input type="hidden" name="keywords" id="keywordsHidden">
                                    <small style="color: var(--gray); margin-top: 8px; display: block;">
                                        Separate keywords with commas or select from suggestions. Press Enter to add new keywords.
                                    </small>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Keyword Weights</label>
                                    <div id="weightControls">
                                        <!-- Weight controls will be dynamically added -->
                                    </div>
                                    <small style="color: var(--gray); margin-top: 8px; display: block;">
                                        Weight indicates importance (1-10). Higher weight means more relevance.
                                    </small>
                                </div>
                                
                                <div class="form-group">
                                    <button type="submit" name="add_keywords" class="btn btn-success">
                                        <i class="fas fa-save"></i> Save Keywords
                                    </button>
                                    <button type="button" id="cancelTagging" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Current Keywords -->
                        <div class="tagging-section" id="currentKeywordsSection" style="display: none;">
                            <h3 class="section-title">
                                <i class="fas fa-list"></i>
                                Current Keywords
                            </h3>
                            
                            <div id="currentKeywordsContainer">
                                <!-- Keywords will be loaded here -->
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="tagging-sidebar">
                        <!-- Keyword Suggestions -->
                        <div class="tagging-section">
                            <h3 class="section-title">
                                <i class="fas fa-lightbulb"></i>
                                Popular Keywords
                            </h3>
                            
                            <?php if (empty($popular_keywords)): ?>
                            <div style="text-align: center; padding: 20px; color: var(--gray);">
                                <i class="fas fa-tags" style="font-size: 2rem; margin-bottom: 10px; color: var(--gray-light);"></i>
                                <p>No keyword usage data available yet.</p>
                            </div>
                            <?php else: ?>
                            <div class="tag-cloud">
                                <?php 
                                $max_usage = max(array_column($popular_keywords, 'usage_count'));
                                foreach ($popular_keywords as $keyword): 
                                    $size = 0.8 + ($keyword['usage_count'] / $max_usage) * 1.2;
                                    $hue = rand(0, 360);
                                    $color = "hsl($hue, 70%, 50%)";
                                ?>
                                <a href="javascript:void(0)" class="cloud-tag" 
                                   data-keyword="<?php echo htmlspecialchars($keyword['keyword']); ?>"
                                   style="font-size: <?php echo $size; ?>rem; background: <?php echo $color; ?>;">
                                    <?php echo htmlspecialchars($keyword['keyword']); ?>
                                    <span style="font-size: 0.7rem; opacity: 0.9;">(<?php echo $keyword['usage_count']; ?>)</span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Keyword Suggestions -->
                        <div class="tagging-section">
                            <h3 class="section-title">
                                <i class="fas fa-bullhorn"></i>
                                Suggested Keywords
                            </h3>
                            
                            <?php if (empty($keyword_suggestions)): ?>
                            <div style="text-align: center; padding: 20px; color: var(--gray);">
                                <i class="fas fa-comment-alt" style="font-size: 2rem; margin-bottom: 10px; color: var(--gray-light);"></i>
                                <p>No keyword suggestions available.</p>
                            </div>
                            <?php else: ?>
                            <div class="suggestions-list">
                                <?php foreach ($keyword_suggestions as $suggestion): ?>
                                <div class="suggestion-item">
                                    <div class="suggestion-keyword"><?php echo htmlspecialchars($suggestion['keyword']); ?></div>
                                    <?php if ($suggestion['category_name']): ?>
                                    <span class="suggestion-category"><?php echo htmlspecialchars($suggestion['category_name']); ?></span>
                                    <?php endif; ?>
                                    <span class="suggestion-usage"><?php echo $suggestion['usage_count']; ?> uses</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Suggest New Keyword -->
                        <div class="tagging-section">
                            <h3 class="section-title">
                                <i class="fas fa-plus-circle"></i>
                                Suggest New Keyword
                            </h3>
                            
                            <form id="suggestionForm" method="POST">
                                <div class="form-group">
                                    <input type="text" name="suggested_keyword" class="form-control" 
                                           placeholder="Enter new keyword..." required>
                                </div>
                                
                                <div class="form-group">
                                    <select name="category_id" class="form-control">
                                        <option value="">Select Category (Optional)</option>
                                        <?php foreach ($keyword_categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <select name="suggest_document_type" class="form-control">
                                        <option value="all">All Document Types</option>
                                        <option value="ordinance">Ordinance Only</option>
                                        <option value="resolution">Resolution Only</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <button type="submit" name="suggest_keyword" class="btn btn-primary btn-sm">
                                        <i class="fas fa-paper-plane"></i> Submit Suggestion
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Recent Activities -->
                        <div class="tagging-section">
                            <h3 class="section-title">
                                <i class="fas fa-history"></i>
                                Recent Activities
                            </h3>
                            
                            <?php if (empty($recent_activities)): ?>
                            <div style="text-align: center; padding: 20px; color: var(--gray);">
                                <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 10px; color: var(--gray-light);"></i>
                                <p>No tagging activities recorded yet.</p>
                            </div>
                            <?php else: ?>
                            <div class="activity-log">
                                <?php foreach ($recent_activities as $activity): 
                                    $action_icon = '';
                                    $action_color = '';
                                    switch ($activity['action_type']) {
                                        case 'added':
                                            $action_icon = 'fa-plus-circle';
                                            $action_color = 'var(--qc-green)';
                                            break;
                                        case 'removed':
                                            $action_icon = 'fa-minus-circle';
                                            $action_color = 'var(--red)';
                                            break;
                                        case 'modified':
                                            $action_icon = 'fa-edit';
                                            $action_color = 'var(--qc-blue)';
                                            break;
                                    }
                                ?>
                                <div class="activity-item">
                                    <div class="activity-content">
                                        <div class="activity-icon" style="color: <?php echo $action_color; ?>;">
                                            <i class="fas <?php echo $action_icon; ?>"></i>
                                        </div>
                                        <div class="activity-details">
                                            <div class="activity-text">
                                                <strong><?php echo htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']); ?></strong>
                                                <?php echo $activity['action_type']; ?> keyword 
                                                "<strong><?php echo htmlspecialchars($activity['keyword_text']); ?></strong>"
                                                <?php if ($activity['document_title']): ?>
                                                to <em><?php echo htmlspecialchars($activity['document_title']); ?></em>
                                                <?php endif; ?>
                                            </div>
                                            <div class="activity-meta">
                                                <span class="activity-time">
                                                    <?php echo date('M d, Y H:i', strtotime($activity['performed_at'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- MODAL FOR KEYWORD MANAGEMENT -->
    <div class="modal-overlay" id="keywordModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Keyword</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <form id="keywordEditForm" method="POST">
                    <input type="hidden" id="modalKeywordId" name="keyword_id">
                    <input type="hidden" id="modalDocumentId" name="document_id">
                    <input type="hidden" id="modalDocumentType" name="document_type">
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-tag"></i>
                            Keyword Details
                        </h3>
                        
                        <div class="form-group">
                            <label class="form-label">Keyword</label>
                            <input type="text" id="modalKeywordText" class="form-control" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Weight</label>
                            <div class="weight-slider-container">
                                <input type="range" id="modalWeightSlider" class="weight-slider" 
                                       min="1" max="10" value="5" name="weight">
                                <span class="weight-value" id="modalWeightValue">5</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-history"></i>
                            Keyword Usage
                        </h3>
                        
                        <div id="keywordUsageInfo" style="padding: 15px; background: var(--off-white); border-radius: var(--border-radius);">
                            <p>Loading usage information...</p>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="modalCancelBtn">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="update_weight" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Weight
                        </button>
                        <button type="button" class="btn btn-danger" id="modalRemoveBtn">
                            <i class="fas fa-trash"></i> Remove Keyword
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
                    <h3>Keyword Tagging Module</h3>
                    <p>
                        Manage and organize keywords for ordinances and resolutions. 
                        Improve document searchability and classification with intelligent tagging system.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="classification.php"><i class="fas fa-sitemap"></i> Classification Dashboard</a></li>
                    <li><a href="type_identification.php"><i class="fas fa-fingerprint"></i> Type Identification</a></li>
                    <li><a href="categorization.php"><i class="fas fa-folder"></i> Subject Categorization</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Tagging Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Tagging Guidelines</a></li>
                    <li><a href="#"><i class="fas fa-list-alt"></i> Keyword Categories</a></li>
                    <li><a href="#"><i class="fas fa-chart-bar"></i> Tagging Analytics</a></li>
                    <li><a href="#"><i class="fas fa-download"></i> Export Keywords</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Keyword Tagging Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All tagging activities are logged for audit purposes.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Keyword Tagging Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
            </div>
        </div>
    </footer>

    <!-- Include Select2 for keyword input -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        // Initialize Select2 for keyword input
        $(document).ready(function() {
            $('#keywordsInput').select2({
                tags: true,
                tokenSeparators: [',', ' '],
                placeholder: 'Type keywords and press Enter or select from suggestions',
                allowClear: true,
                createTag: function (params) {
                    var term = $.trim(params.term);
                    if (term === '') {
                        return null;
                    }
                    return {
                        id: term,
                        text: term,
                        newTag: true
                    };
                }
            });
            
            // Update hidden input when keywords change
            $('#keywordsInput').on('change', function() {
                var keywords = $(this).val();
                $('#keywordsHidden').val(keywords.join(','));
                updateWeightControls(keywords);
            });
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
        
        // Document selection functionality
        document.querySelectorAll('.tag-document-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const documentItem = this.closest('.document-item');
                const documentId = documentItem.dataset.documentId;
                const documentType = documentItem.dataset.documentType;
                const documentTitle = documentItem.dataset.documentTitle;
                const documentNumber = documentItem.dataset.documentNumber;
                
                // Show tagging form
                document.getElementById('taggingFormSection').style.display = 'block';
                document.getElementById('currentKeywordsSection').style.display = 'block';
                
                // Set document info
                document.getElementById('documentId').value = documentId;
                document.getElementById('documentType').value = documentType;
                document.getElementById('documentTitleDisplay').textContent = documentTitle;
                document.getElementById('documentNumberDisplay').textContent = documentNumber;
                document.getElementById('documentTypeDisplay').textContent = 
                    documentType === 'ordinance' ? 'Ordinance' : 'Resolution';
                
                // Load current keywords
                loadCurrentKeywords(documentId, documentType);
                
                // Scroll to form
                document.getElementById('taggingFormSection').scrollIntoView({ behavior: 'smooth' });
            });
        });
        
        // Cancel tagging
        document.getElementById('cancelTagging').addEventListener('click', function() {
            document.getElementById('taggingFormSection').style.display = 'none';
            document.getElementById('currentKeywordsSection').style.display = 'none';
            document.getElementById('keywordsInput').value = '';
            document.getElementById('keywordsHidden').value = '';
            document.getElementById('weightControls').innerHTML = '';
        });
        
        // Update weight controls based on selected keywords
        function updateWeightControls(keywords) {
            const weightControls = document.getElementById('weightControls');
            weightControls.innerHTML = '';
            
            if (keywords && keywords.length > 0) {
                keywords.forEach((keyword, index) => {
                    const weightControl = document.createElement('div');
                    weightControl.className = 'weight-slider-container';
                    weightControl.innerHTML = `
                        <label style="flex: 1; font-size: 0.9rem; color: var(--gray-dark);">${keyword}</label>
                        <input type="range" class="weight-slider" data-index="${index}" 
                               min="1" max="10" value="5">
                        <span class="weight-value" id="weightValue${index}">5</span>
                        <input type="hidden" name="weights[${index}]" value="5">
                    `;
                    weightControls.appendChild(weightControl);
                    
                    // Add event listener for weight slider
                    const slider = weightControl.querySelector('.weight-slider');
                    const valueSpan = weightControl.querySelector('.weight-value');
                    const hiddenInput = weightControl.querySelector('input[type="hidden"]');
                    
                    slider.addEventListener('input', function() {
                        valueSpan.textContent = this.value;
                        hiddenInput.value = this.value;
                    });
                });
            }
        }
        
        // Load current keywords for a document
        function loadCurrentKeywords(documentId, documentType) {
            const container = document.getElementById('currentKeywordsContainer');
            container.innerHTML = '<p style="color: var(--gray); text-align: center;">Loading keywords...</p>';
            
            // In a real application, you would fetch via AJAX
            // For now, we'll simulate with existing data
            setTimeout(() => {
                // This would be replaced with actual AJAX call
                const keywords = [
                    { id: 1, keyword: 'environment', weight: 8 },
                    { id: 2, keyword: 'sanitation', weight: 7 },
                    { id: 3, keyword: 'public health', weight: 9 }
                ];
                
                if (keywords.length === 0) {
                    container.innerHTML = '<p style="color: var(--gray); text-align: center;">No keywords added yet.</p>';
                    return;
                }
                
                let html = '<div class="keyword-tags-container">';
                keywords.forEach(kw => {
                    const weightColor = getWeightColor(kw.weight);
                    html += `
                        <div class="keyword-tag" style="background: ${weightColor};">
                            ${kw.keyword}
                            <span class="keyword-weight">${kw.weight}</span>
                            <div class="tag-actions">
                                <button class="tag-action-btn edit-keyword-btn" 
                                        data-keyword-id="${kw.id}"
                                        data-keyword="${kw.keyword}"
                                        data-weight="${kw.weight}">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="tag-action-btn remove-keyword-btn" 
                                        data-keyword-id="${kw.id}">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;
                
                // Add event listeners to edit/remove buttons
                addKeywordEventListeners(documentId, documentType);
            }, 500);
        }
        
        // Get color based on weight
        function getWeightColor(weight) {
            if (weight >= 8) return '#C53030'; // Red for high importance
            if (weight >= 6) return '#D69E2E'; // Orange for medium-high
            if (weight >= 4) return '#2D8C47'; // Green for medium
            if (weight >= 2) return '#3182CE'; // Blue for low-medium
            return '#718096'; // Gray for low
        }
        
        // Add event listeners to keyword action buttons
        function addKeywordEventListeners(documentId, documentType) {
            // Edit keyword buttons
            document.querySelectorAll('.edit-keyword-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const keywordId = this.dataset.keywordId;
                    const keyword = this.dataset.keyword;
                    const weight = this.dataset.weight;
                    
                    openKeywordModal(keywordId, keyword, weight, documentId, documentType);
                });
            });
            
            // Remove keyword buttons
            document.querySelectorAll('.remove-keyword-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const keywordId = this.dataset.keywordId;
                    if (confirm('Are you sure you want to remove this keyword?')) {
                        // In a real application, submit via AJAX or form
                        console.log('Remove keyword:', keywordId);
                        // You would submit a form here
                    }
                });
            });
        }
        
        // Keyword modal functionality
        const keywordModal = document.getElementById('keywordModal');
        const modalClose = document.getElementById('modalClose');
        const modalCancelBtn = document.getElementById('modalCancelBtn');
        
        function openKeywordModal(keywordId, keyword, weight, documentId, documentType) {
            // Set modal values
            document.getElementById('modalKeywordId').value = keywordId;
            document.getElementById('modalKeywordText').value = keyword;
            document.getElementById('modalWeightSlider').value = weight;
            document.getElementById('modalWeightValue').textContent = weight;
            document.getElementById('modalDocumentId').value = documentId;
            document.getElementById('modalDocumentType').value = documentType;
            
            // Load keyword usage info (simulated)
            const usageInfo = document.getElementById('keywordUsageInfo');
            usageInfo.innerHTML = `
                <div style="font-size: 0.9rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span>Used in documents:</span>
                        <strong>12</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span>Average weight:</span>
                        <strong>${weight}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Last used:</span>
                        <strong>Today</strong>
                    </div>
                </div>
            `;
            
            // Open modal
            keywordModal.classList.add('active');
        }
        
        function closeKeywordModal() {
            keywordModal.classList.remove('active');
        }
        
        // Close modal events
        modalClose.addEventListener('click', closeKeywordModal);
        modalCancelBtn.addEventListener('click', closeKeywordModal);
        keywordModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeKeywordModal();
            }
        });
        
        // Weight slider update in modal
        const modalWeightSlider = document.getElementById('modalWeightSlider');
        const modalWeightValue = document.getElementById('modalWeightValue');
        
        modalWeightSlider.addEventListener('input', function() {
            modalWeightValue.textContent = this.value;
        });
        
        // Remove keyword button in modal
        document.getElementById('modalRemoveBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to remove this keyword from the document?')) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const keywordIdInput = document.createElement('input');
                keywordIdInput.type = 'hidden';
                keywordIdInput.name = 'keyword_id';
                keywordIdInput.value = document.getElementById('modalKeywordId').value;
                
                const documentIdInput = document.createElement('input');
                documentIdInput.type = 'hidden';
                documentIdInput.name = 'document_id';
                documentIdInput.value = document.getElementById('modalDocumentId').value;
                
                const documentTypeInput = document.createElement('input');
                documentTypeInput.type = 'hidden';
                documentTypeInput.name = 'document_type';
                documentTypeInput.value = document.getElementById('modalDocumentType').value;
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'remove_keyword';
                actionInput.value = '1';
                
                form.appendChild(keywordIdInput);
                form.appendChild(documentIdInput);
                form.appendChild(documentTypeInput);
                form.appendChild(actionInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
        
        // Cloud tag click event
        document.querySelectorAll('.cloud-tag').forEach(tag => {
            tag.addEventListener('click', function() {
                const keyword = this.dataset.keyword;
                const keywordsInput = $('#keywordsInput');
                const currentValues = keywordsInput.val() || [];
                
                if (!currentValues.includes(keyword)) {
                    currentValues.push(keyword);
                    keywordsInput.val(currentValues).trigger('change');
                    
                    // Scroll to form if not visible
                    if (document.getElementById('taggingFormSection').style.display === 'none') {
                        alert('Please select a document first to add keywords.');
                    }
                }
            });
        });
        
        // Document search functionality
        const documentSearch = document.getElementById('documentSearch');
        documentSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const documentItems = document.querySelectorAll('.document-item');
            
            documentItems.forEach(item => {
                const title = item.dataset.documentTitle.toLowerCase();
                const number = item.dataset.documentNumber.toLowerCase();
                
                if (title.includes(searchTerm) || number.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        });
        
        // Form validation
        document.getElementById('taggingForm').addEventListener('submit', function(e) {
            const keywords = $('#keywordsInput').val();
            
            if (!keywords || keywords.length === 0) {
                e.preventDefault();
                alert('Please enter at least one keyword.');
                return;
            }
            
            if (!confirm('Are you sure you want to add these keywords to the document?')) {
                e.preventDefault();
            }
        });
        
        // Initialize animation for sections
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
        
        // Observe sections
        document.querySelectorAll('.tagging-section, .tagging-main, .tagging-sidebar').forEach(section => {
            observer.observe(section);
        });
    </script>
</body>
</html>