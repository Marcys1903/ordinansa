<?php
// comparison.php - Change Comparison Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to access comparison
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

// Handle comparison requests
$success_message = '';
$error_message = '';
$comparison_data = null;
$analytics_data = [];
$highlights_data = [];
$comments_data = [];

// Get document versions for comparison
$versions_query = "SELECT dv.*, 
                  CASE 
                    WHEN o.id IS NOT NULL THEN o.ordinance_number 
                    WHEN r.id IS NOT NULL THEN r.resolution_number 
                  END as doc_number,
                  CASE 
                    WHEN o.id IS NOT NULL THEN o.title 
                    WHEN r.id IS NOT NULL THEN r.title 
                  END as doc_title,
                  CASE 
                    WHEN o.id IS NOT NULL THEN 'ordinance' 
                    WHEN r.id IS NOT NULL THEN 'resolution' 
                  END as doc_type
                  FROM document_versions dv
                  LEFT JOIN ordinances o ON dv.document_id = o.id AND dv.document_type = 'ordinance'
                  LEFT JOIN resolutions r ON dv.document_id = r.id AND dv.document_type = 'resolution'
                  WHERE dv.is_current = 1 OR dv.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  ORDER BY dv.created_at DESC
                  LIMIT 50";
$versions_stmt = $conn->query($versions_query);
$available_versions = $versions_stmt->fetchAll();

// Get user's recent comparisons
$recent_comparisons_query = "SELECT cs.*, 
                            CASE 
                              WHEN o.id IS NOT NULL THEN o.ordinance_number 
                              WHEN r.id IS NOT NULL THEN r.resolution_number 
                            END as doc_number,
                            CASE 
                              WHEN o.id IS NOT NULL THEN o.title 
                              WHEN r.id IS NOT NULL THEN r.title 
                            END as doc_title,
                            u.first_name, u.last_name
                            FROM comparison_sessions cs
                            LEFT JOIN ordinances o ON cs.document_id = o.id AND cs.document_type = 'ordinance'
                            LEFT JOIN resolutions r ON cs.document_id = r.id AND cs.document_type = 'resolution'
                            LEFT JOIN users u ON cs.created_by = u.id
                            WHERE cs.created_by = :user_id OR cs.id IN (
                              SELECT session_id FROM comparison_shares WHERE shared_with = :user_id2
                            )
                            ORDER BY cs.created_at DESC
                            LIMIT 10";
$recent_stmt = $conn->prepare($recent_comparisons_query);
$recent_stmt->bindParam(':user_id', $user_id);
$recent_stmt->bindParam(':user_id2', $user_id);
$recent_stmt->execute();
$recent_comparisons = $recent_stmt->fetchAll();

/// Fix the SQL query in the comparison creation section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'compare') {
    try {
        $old_version_id = $_POST['old_version_id'];
        $new_version_id = $_POST['new_version_id'];
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        
        // Get version details - FIXED QUERY
        $version_query = "SELECT id, content FROM document_versions WHERE id IN (:old_id, :new_id)";
        $stmt = $conn->prepare($version_query);
        $stmt->bindParam(':old_id', $old_version_id);
        $stmt->bindParam(':new_id', $new_version_id);
        $stmt->execute();
        $versions = $stmt->fetchAll();
        
        if (count($versions) < 2) {
            throw new Exception("One or both versions not found");
        }
        
        // Convert to associative array with id as key
        $versions_by_id = [];
        foreach ($versions as $version) {
            $versions_by_id[$version['id']] = $version;
        }
        
        // Generate session code
        $session_code = 'COMP-' . strtoupper(substr($document_type, 0, 3)) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        
        // Create comparison session
        $session_query = "INSERT INTO comparison_sessions (session_code, document_id, document_type, old_version_id, new_version_id, created_by) 
                         VALUES (:code, :doc_id, :doc_type, :old_id, :new_id, :user_id)";
        $stmt = $conn->prepare($session_query);
        $stmt->bindParam(':code', $session_code);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->bindParam(':doc_type', $document_type);
        $stmt->bindParam(':old_id', $old_version_id);
        $stmt->bindParam(':new_id', $new_version_id);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        $session_id = $conn->lastInsertId();
        
        // Perform automated comparison (simplified - in production would use diff algorithm)
        $old_content = isset($versions_by_id[$old_version_id]['content']) ? 
                      strip_tags($versions_by_id[$old_version_id]['content']) : '';
        $new_content = isset($versions_by_id[$new_version_id]['content']) ? 
                      strip_tags($versions_by_id[$new_version_id]['content']) : '';
        
        // Simple word-based comparison (for demo)
        $old_words = str_word_count($old_content, 1);
        $new_words = str_word_count($new_content, 1);
        $common_words = array_intersect($old_words, $new_words);
        $similarity = count($old_words) > 0 ? 
                     (count($common_words) / max(count($old_words), count($new_words))) * 100 : 0;
        
        // Create AI analytics (mocked)
        $analytics_types = ['complexity', 'impact', 'similarity', 'legal_risk', 'fiscal_impact'];
        foreach ($analytics_types as $type) {
            $score = rand(30, 90) + rand(0, 99) / 100;
            $confidence = rand(60, 95) + rand(0, 99) / 100;
            
            $details = [
                'complexity' => "Analysis complete. " . rand(5, 20) . " significant changes detected across " . rand(3, 8) . " sections.",
                'impact' => "Medium impact assessment. Affects " . rand(2, 5) . " departments and " . rand(1, 3) . " existing ordinances.",
                'similarity' => number_format($similarity, 1) . "% similarity score. " . (rand(0, 1) ? "High similarity to existing documents." : "Unique changes detected."),
                'legal_risk' => "Legal review recommended for " . rand(1, 3) . " sections. " . (rand(0, 1) ? "Low risk overall." : "Moderate risk identified."),
                'fiscal_impact' => (rand(0, 1) ? "Minimal fiscal impact." : "Estimated PHP " . rand(500, 5000) . "K annual impact.")
            ][$type];
            
            $recs = [
                'complexity' => "Consider breaking into multiple amendments if too complex.",
                'impact' => "Coordinate with affected departments before committee review.",
                'similarity' => "Check for conflicts with existing legislation.",
                'legal_risk' => "Consult with City Legal Office before final approval.",
                'fiscal_impact' => "Requires detailed fiscal note and budget allocation."
            ][$type];
            
            $analytics_query = "INSERT INTO comparison_analytics (session_id, analytics_type, score, confidence, details, recommendations) 
                               VALUES (:session_id, :type, :score, :confidence, :details, :recs)";
            $stmt = $conn->prepare($analytics_query);
            $stmt->bindParam(':session_id', $session_id);
            $stmt->bindParam(':type', $type);
            $stmt->bindParam(':score', $score);
            $stmt->bindParam(':confidence', $confidence);
            $stmt->bindParam(':details', $details);
            $stmt->bindParam(':recs', $recs);
            $stmt->execute();
        }
        
        // Create change highlights (mocked)
        $sections = ['Preamble', 'Section 1', 'Section 2', 'Section 3', 'Section 4', 'Section 5', 'Definitions', 'Penalties'];
        $change_types = ['addition', 'deletion', 'modification', 'reorganization'];
        
        for ($i = 0; $i < rand(5, 12); $i++) {
            $section = $sections[array_rand($sections)];
            $change_type = $change_types[array_rand($change_types)];
            $importance = ['low', 'medium', 'high', 'critical'][rand(0, 3)];
            
            $notes = [
                "Important change in " . $section,
                "Review this modification carefully",
                "Significant impact on implementation",
                "Clarifies ambiguous language",
                "Adds enforcement mechanism",
                "Updates outdated provisions"
            ][rand(0, 5)];
            
            $highlight_query = "INSERT INTO change_highlights (session_id, old_text, new_text, change_type, section, importance, notes, created_by) 
                               VALUES (:session_id, :old_text, :new_text, :type, :section, :importance, :notes, :user_id)";
            $stmt = $conn->prepare($highlight_query);
            
            $old_text = $change_type === 'addition' ? NULL : "Original text for " . $section . " line " . rand(1, 50);
            $new_text = $change_type === 'deletion' ? NULL : "Modified text for " . $section . " with updated provisions";
            
            $stmt->bindParam(':session_id', $session_id);
            $stmt->bindParam(':old_text', $old_text);
            $stmt->bindParam(':new_text', $new_text);
            $stmt->bindParam(':type', $change_type);
            $stmt->bindParam(':section', $section);
            $stmt->bindParam(':importance', $importance);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
        }
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'COMPARISON_CREATE', 'Created comparison session: {$session_code}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Comparison created successfully! Session Code: {$session_code}";
        
        // Redirect to view the comparison
        header("Location: comparison.php?session={$session_code}");
        exit();
        
    } catch (PDOException $e) {
        $error_message = "Error creating comparison: " . $e->getMessage();
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Load existing comparison session
if (isset($_GET['session'])) {
    $session_code = $_GET['session'];
    
    // Get session details
    $session_query = "SELECT cs.*, 
                     CASE 
                       WHEN o.id IS NOT NULL THEN o.ordinance_number 
                       WHEN r.id IS NOT NULL THEN r.resolution_number 
                     END as doc_number,
                     CASE 
                       WHEN o.id IS NOT NULL THEN o.title 
                       WHEN r.id IS NOT NULL THEN r.title 
                     END as doc_title,
                     CASE 
                       WHEN o.id IS NOT NULL THEN 'ordinance' 
                       WHEN r.id IS NOT NULL THEN 'resolution' 
                     END as doc_type,
                     u.first_name, u.last_name
                     FROM comparison_sessions cs
                     LEFT JOIN ordinances o ON cs.document_id = o.id AND cs.document_type = 'ordinance'
                     LEFT JOIN resolutions r ON cs.document_id = r.id AND cs.document_type = 'resolution'
                     LEFT JOIN users u ON cs.created_by = u.id
                     WHERE cs.session_code = :code 
                     AND (cs.created_by = :user_id OR cs.id IN (
                       SELECT session_id FROM comparison_shares WHERE shared_with = :user_id2
                     ))";
    $stmt = $conn->prepare($session_query);
    $stmt->bindParam(':code', $session_code);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':user_id2', $user_id);
    $stmt->execute();
    $comparison_data = $stmt->fetch();
    
    if ($comparison_data) {
        // Get analytics data
        $analytics_query = "SELECT * FROM comparison_analytics WHERE session_id = :session_id ORDER BY analytics_type";
        $stmt = $conn->prepare($analytics_query);
        $stmt->bindParam(':session_id', $comparison_data['id']);
        $stmt->execute();
        $analytics_data = $stmt->fetchAll();
        
        // Get highlights data
        $highlights_query = "SELECT ch.*, u.first_name, u.last_name 
                            FROM change_highlights ch
                            LEFT JOIN users u ON ch.created_by = u.id
                            WHERE ch.session_id = :session_id 
                            ORDER BY 
                              CASE importance 
                                WHEN 'critical' THEN 1
                                WHEN 'high' THEN 2
                                WHEN 'medium' THEN 3
                                WHEN 'low' THEN 4
                              END, 
                              section, line_start";
        $stmt = $conn->prepare($highlights_query);
        $stmt->bindParam(':session_id', $comparison_data['id']);
        $stmt->execute();
        $highlights_data = $stmt->fetchAll();
        
        // Get comments
        $comments_query = "SELECT cc.*, u.first_name, u.last_name, u.role
                          FROM change_comments cc
                          JOIN users u ON cc.user_id = u.id
                          WHERE cc.highlight_id IN (SELECT id FROM change_highlights WHERE session_id = :session_id)
                          ORDER BY cc.created_at DESC";
        $stmt = $conn->prepare($comments_query);
        $stmt->bindParam(':session_id', $comparison_data['id']);
        $stmt->execute();
        $comments_data = $stmt->fetchAll();
        
        // Update view count
        $update_query = "UPDATE comparison_sessions SET view_count = view_count + 1, last_viewed = NOW() WHERE id = :session_id";
        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':session_id', $comparison_data['id']);
        $stmt->execute();
    }
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    try {
        $highlight_id = $_POST['highlight_id'];
        $comment = $_POST['comment'];
        
        $comment_query = "INSERT INTO change_comments (highlight_id, user_id, comment) 
                         VALUES (:highlight_id, :user_id, :comment)";
        $stmt = $conn->prepare($comment_query);
        $stmt->bindParam(':highlight_id', $highlight_id);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':comment', $comment);
        $stmt->execute();
        
        $success_message = "Comment added successfully!";
        
        // Refresh page
        header("Location: comparison.php?session=" . $_GET['session']);
        exit();
        
    } catch (PDOException $e) {
        $error_message = "Error adding comment: " . $e->getMessage();
    }
}

// Handle share comparison
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'share_comparison') {
    try {
        $session_id = $_POST['session_id'];
        $shared_with = $_POST['shared_with'];
        $permissions = $_POST['permissions'];
        
        $share_query = "INSERT INTO comparison_shares (session_id, shared_with, shared_by, permissions) 
                       VALUES (:session_id, :shared_with, :shared_by, :permissions) 
                       ON DUPLICATE KEY UPDATE permissions = :permissions2";
        $stmt = $conn->prepare($share_query);
        $stmt->bindParam(':session_id', $session_id);
        $stmt->bindParam(':shared_with', $shared_with);
        $stmt->bindParam(':shared_by', $user_id);
        $stmt->bindParam(':permissions', $permissions);
        $stmt->bindParam(':permissions2', $permissions);
        $stmt->execute();
        
        $success_message = "Comparison shared successfully!";
        
    } catch (PDOException $e) {
        $error_message = "Error sharing comparison: " . $e->getMessage();
    }
}

// Get users for sharing
$users_query = "SELECT id, first_name, last_name, role, department FROM users 
                WHERE is_active = 1 AND id != :user_id
                ORDER BY last_name, first_name";
$users_stmt = $conn->prepare($users_query);
$users_stmt->bindParam(':user_id', $user_id);
$users_stmt->execute();
$available_users = $users_stmt->fetchAll();

// Get amendment submissions for linking
$amendments_query = "SELECT * FROM amendment_submissions WHERE status IN ('pending', 'under_review') ORDER BY created_at DESC LIMIT 10";
$amendments_stmt = $conn->query($amendments_query);
$amendments = $amendments_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Comparison | QC Ordinance Tracker</title>
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
            --qc-red: #C53030;
            --qc-yellow: #F6E05E;
            --white: #FFFFFF;
            --off-white: #F8F9FA;
            --gray-light: #E9ECEF;
            --gray: #6C757D;
            --gray-dark: #343A40;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.12);
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
        
        /* FIXED MODULE HEADER */
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
        
        /* COMPARISON LAYOUT */
        .comparison-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .comparison-container {
                grid-template-columns: 1fr;
            }
        }
        
        .comparison-panel {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-light);
        }
        
        .panel-title {
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--qc-blue);
            font-size: 1.3rem;
            font-weight: bold;
        }
        
        .panel-title i {
            color: var(--qc-gold);
        }
        
        .version-badge {
            padding: 5px 15px;
            background: var(--qc-blue);
            color: var(--white);
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: bold;
        }
        
        .content-display {
            background: var(--off-white);
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 25px;
            min-height: 400px;
            max-height: 600px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            line-height: 1.6;
            white-space: pre-wrap;
        }
        
        /* CHANGE HIGHLIGHTS */
        .change-highlight {
            margin-bottom: 20px;
            padding: 20px;
            border-radius: var(--border-radius);
            border-left: 5px solid var(--qc-blue);
            background: var(--off-white);
            transition: all 0.3s ease;
        }
        
        .change-highlight:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .change-highlight.critical {
            border-left-color: var(--qc-red);
            background: linear-gradient(135deg, rgba(197, 48, 48, 0.05) 0%, rgba(197, 48, 48, 0.1) 100%);
        }
        
        .change-highlight.high {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(245, 158, 11, 0.1) 100%);
        }
        
        .change-highlight.medium {
            border-left-color: var(--qc-yellow);
            background: linear-gradient(135deg, rgba(246, 224, 94, 0.05) 0%, rgba(246, 224, 94, 0.1) 100%);
        }
        
        .change-highlight.low {
            border-left-color: var(--qc-green);
            background: linear-gradient(135deg, rgba(45, 140, 71, 0.05) 0%, rgba(45, 140, 71, 0.1) 100%);
        }
        
        .change-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .change-type {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .change-type.addition {
            background: rgba(45, 140, 71, 0.2);
            color: var(--qc-green-dark);
        }
        
        .change-type.deletion {
            background: rgba(197, 48, 48, 0.2);
            color: var(--qc-red);
        }
        
        .change-type.modification {
            background: rgba(0, 51, 102, 0.2);
            color: var(--qc-blue);
        }
        
        .change-type.reorganization {
            background: rgba(212, 175, 55, 0.2);
            color: var(--qc-gold-dark);
        }
        
        .change-section {
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 10px;
        }
        
        .change-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .change-content {
                grid-template-columns: 1fr;
            }
        }
        
        .old-text, .new-text {
            padding: 15px;
            border-radius: var(--border-radius);
            background: var(--white);
            border: 1px solid var(--gray-light);
            position: relative;
        }
        
        .old-text::before, .new-text::before {
            position: absolute;
            top: -10px;
            left: 15px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        
        .old-text::before {
            content: 'OLD';
            background: var(--qc-red);
            color: var(--white);
        }
        
        .new-text::before {
            content: 'NEW';
            background: var(--qc-green);
            color: var(--white);
        }
        
        .change-notes {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-light);
            color: var(--gray-dark);
            font-style: italic;
        }
        
        /* AI ANALYTICS PANEL */
        .analytics-panel {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .analytic-card {
            padding: 25px;
            border-radius: var(--border-radius);
            background: var(--off-white);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .analytic-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }
        
        .analytic-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .analytic-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: bold;
            color: var(--qc-blue);
        }
        
        .analytic-title i {
            color: var(--qc-gold);
        }
        
        .analytic-score {
            font-size: 1.8rem;
            font-weight: bold;
            text-align: center;
            margin: 15px 0;
        }
        
        .score-bar {
            height: 10px;
            background: var(--gray-light);
            border-radius: 5px;
            margin: 10px 0;
            overflow: hidden;
        }
        
        .score-fill {
            height: 100%;
            border-radius: 5px;
            transition: width 1s ease;
        }
        
        .score-low { background: var(--qc-green); }
        .score-medium { background: var(--qc-yellow); }
        .score-high { background: var(--qc-red); }
        
        .analytic-details {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-light);
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .analytic-recommendations {
            margin-top: 10px;
            padding: 12px;
            background: rgba(212, 175, 55, 0.1);
            border-radius: var(--border-radius);
            border-left: 3px solid var(--qc-gold);
            font-size: 0.9rem;
        }
        
        /* COMMENTS SECTION */
        .comments-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .comment-form {
            margin-bottom: 30px;
            padding: 20px;
            background: var(--off-white);
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
        }
        
        .comment-input {
            width: 100%;
            padding: 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-family: 'Times New Roman', Times, serif;
            font-size: 1rem;
            margin-bottom: 15px;
            min-height: 100px;
            resize: vertical;
        }
        
        .comments-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .comment-item {
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .comment-item:hover {
            background: var(--off-white);
        }
        
        .comment-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .comment-author {
            display: flex;
            align-items: center;
            gap: 12px;
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
        
        .author-info h4 {
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 2px;
        }
        
        .author-role {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .comment-time {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .comment-text {
            color: var(--gray-dark);
            line-height: 1.6;
            margin-bottom: 10px;
        }
        
        /* NEW COMPARISON FORM */
        .new-comparison-form {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .version-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin: 20px 0;
        }
        
        @media (max-width: 768px) {
            .version-selector {
                grid-template-columns: 1fr;
            }
        }
        
        .version-box {
            padding: 20px;
            border: 2px dashed var(--gray-light);
            border-radius: var(--border-radius);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .version-box:hover {
            border-color: var(--qc-blue);
            background: var(--off-white);
        }
        
        .version-box.selected {
            border-color: var(--qc-gold);
            background: rgba(212, 175, 55, 0.05);
        }
        
        .version-info {
            margin-top: 15px;
        }
        
        .version-title {
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 5px;
        }
        
        .version-meta {
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        /* RECENT COMPARISONS */
        .recent-comparisons {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .comparison-list {
            list-style: none;
        }
        
        .comparison-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .comparison-item:hover {
            background: var(--off-white);
            transform: translateX(5px);
        }
        
        .comparison-item:last-child {
            border-bottom: none;
        }
        
        .comparison-icon {
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
        
        .comparison-content {
            flex: 1;
        }
        
        .comparison-title {
            font-weight: 600;
            color: var(--qc-blue);
            margin-bottom: 5px;
            font-size: 1rem;
        }
        
        .comparison-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .comparison-session {
            font-weight: 600;
            color: var(--qc-gold);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
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
            background: linear-gradient(135deg, var(--qc-red) 0%, #9b2c2c 100%);
            color: var(--white);
        }
        
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--qc-yellow) 0%, #d69e2e 100%);
            color: var(--qc-blue-dark);
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
            border: 1px solid var(--qc-red);
            color: var(--qc-red);
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
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
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
        }
        
        .footer-links a:hover {
            color: var(--qc-gold);
            transform: translateX(5px);
        }
        
        .copyright {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            max-width: 1400px;
            margin: 0 auto;
            padding-left: 20px;
            padding-right: 20px;
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
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
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
                    <p>Amendment Management | Change Comparison Module</p>
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
                        <i class="fas fa-edit"></i> Amendment Management
                    </h3>
                    <p class="sidebar-subtitle">Change Comparison Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="comparison-module-container">
                <!-- FIXED MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">CHANGE COMPARISON MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-code-branch"></i>
                            </div>
                            <div class="module-title">
                                <h1>Document Change Comparison</h1>
                                <p class="module-subtitle">
                                    Compare different versions of ordinances and resolutions. Visualize changes, 
                                    analyze impact, and collaborate with team members on amendment reviews.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-exchange-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo count($recent_comparisons); ?></h3>
                                    <p>Your Comparisons</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo count($available_versions); ?></h3>
                                    <p>Available Versions</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-robot"></i>
                                </div>
                                <div class="stat-info">
                                    <h3>AI</h3>
                                    <p>Analytics Enabled</p>
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

                <?php if ($comparison_data): ?>
                <!-- EXISTING COMPARISON VIEW -->
                <div class="comparison-overview fade-in">
                    <div class="panel-header" style="margin-bottom: 30px;">
                        <div class="panel-title">
                            <i class="fas fa-balance-scale"></i>
                            <h2>Comparison Session: <?php echo htmlspecialchars($comparison_data['session_code']); ?></h2>
                        </div>
                        <div class="action-buttons">
                            <button class="btn btn-warning" onclick="shareComparison(<?php echo $comparison_data['id']; ?>)">
                                <i class="fas fa-share"></i> Share
                            </button>
                            <button class="btn btn-secondary" onclick="printComparison()">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button class="btn btn-danger" onclick="deleteComparison(<?php echo $comparison_data['id']; ?>)">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                    
                    <div class="comparison-info" style="background: var(--off-white); padding: 20px; border-radius: var(--border-radius); margin-bottom: 30px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                            <div>
                                <strong>Document:</strong> <?php echo htmlspecialchars($comparison_data['doc_number'] . ' - ' . $comparison_data['doc_title']); ?>
                            </div>
                            <div>
                                <strong>Created by:</strong> <?php echo htmlspecialchars($comparison_data['first_name'] . ' ' . $comparison_data['last_name']); ?>
                            </div>
                            <div>
                                <strong>Created:</strong> <?php echo date('F d, Y H:i', strtotime($comparison_data['created_at'])); ?>
                            </div>
                            <div>
                                <strong>Views:</strong> <?php echo $comparison_data['view_count']; ?>
                            </div>
                        </div>
                    </div>

                    <!-- SIDE-BY-SIDE COMPARISON -->
                    <div class="comparison-container fade-in">
                        <!-- OLD VERSION -->
                        <div class="comparison-panel">
                            <div class="panel-header">
                                <div class="panel-title">
                                    <i class="fas fa-history"></i>
                                    <h3>Original Version</h3>
                                </div>
                                <div class="version-badge">V<?php 
                                    // Get version number for old version
                                    $old_version_query = "SELECT version_number FROM document_versions WHERE id = :id";
                                    $stmt = $conn->prepare($old_version_query);
                                    $stmt->bindParam(':id', $comparison_data['old_version_id']);
                                    $stmt->execute();
                                    $old_version = $stmt->fetch();
                                    echo $old_version ? $old_version['version_number'] : '?';
                                ?></div>
                            </div>
                            <div class="content-display" id="oldContent">
                                <?php 
                                    // Get old version content
                                    $old_content_query = "SELECT content FROM document_versions WHERE id = :id";
                                    $stmt = $conn->prepare($old_content_query);
                                    $stmt->bindParam(':id', $comparison_data['old_version_id']);
                                    $stmt->execute();
                                    $old_content = $stmt->fetch();
                                    echo htmlspecialchars(strip_tags($old_content['content']));
                                ?>
                            </div>
                        </div>

                        <!-- NEW VERSION -->
                        <div class="comparison-panel">
                            <div class="panel-header">
                                <div class="panel-title">
                                    <i class="fas fa-file-medical-alt"></i>
                                    <h3>Amended Version</h3>
                                </div>
                                <div class="version-badge">V<?php 
                                    // Get version number for new version
                                    $new_version_query = "SELECT version_number FROM document_versions WHERE id = :id";
                                    $stmt = $conn->prepare($new_version_query);
                                    $stmt->bindParam(':id', $comparison_data['new_version_id']);
                                    $stmt->execute();
                                    $new_version = $stmt->fetch();
                                    echo $new_version ? $new_version['version_number'] : '?';
                                ?></div>
                            </div>
                            <div class="content-display" id="newContent">
                                <?php 
                                    // Get new version content
                                    $new_content_query = "SELECT content FROM document_versions WHERE id = :id";
                                    $stmt = $conn->prepare($new_content_query);
                                    $stmt->bindParam(':id', $comparison_data['new_version_id']);
                                    $stmt->execute();
                                    $new_content = $stmt->fetch();
                                    echo htmlspecialchars(strip_tags($new_content['content']));
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- AI ANALYTICS PANEL -->
                    <div class="analytics-panel fade-in">
                        <div class="panel-header">
                            <div class="panel-title">
                                <i class="fas fa-robot"></i>
                                <h3>AI-Powered Change Analysis</h3>
                            </div>
                            <span style="color: var(--qc-gold); font-weight: bold;">
                                <i class="fas fa-bolt"></i> Powered by QC AI Analytics
                            </span>
                        </div>
                        
                        <div class="analytics-grid">
                            <?php foreach ($analytics_data as $analytic): 
                                $score_class = $analytic['score'] < 40 ? 'score-low' : ($analytic['score'] < 70 ? 'score-medium' : 'score-high');
                                $icon = [
                                    'complexity' => 'fa-project-diagram',
                                    'impact' => 'fa-bullseye',
                                    'similarity' => 'fa-clone',
                                    'legal_risk' => 'fa-balance-scale',
                                    'fiscal_impact' => 'fa-money-bill-wave'
                                ][$analytic['analytics_type']];
                            ?>
                            <div class="analytic-card">
                                <div class="analytic-header">
                                    <div class="analytic-title">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                        <span><?php echo ucfirst(str_replace('_', ' ', $analytic['analytics_type'])); ?></span>
                                    </div>
                                    <span style="font-size: 0.85rem; color: var(--gray);">
                                        <?php echo number_format($analytic['confidence'], 1); ?>% confidence
                                    </span>
                                </div>
                                
                                <div class="analytic-score" style="color: <?php echo $analytic['score'] < 40 ? 'var(--qc-green)' : ($analytic['score'] < 70 ? 'var(--qc-yellow)' : 'var(--qc-red)'); ?>;">
                                    <?php echo number_format($analytic['score'], 1); ?>%
                                </div>
                                
                                <div class="score-bar">
                                    <div class="score-fill <?php echo $score_class; ?>" 
                                         style="width: <?php echo $analytic['score']; ?>%;"
                                         data-score="<?php echo $analytic['score']; ?>"></div>
                                </div>
                                
                                <div class="analytic-details">
                                    <?php echo htmlspecialchars($analytic['details']); ?>
                                </div>
                                
                                <?php if ($analytic['recommendations']): ?>
                                <div class="analytic-recommendations">
                                    <strong><i class="fas fa-lightbulb"></i> Recommendation:</strong>
                                    <?php echo htmlspecialchars($analytic['recommendations']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- CHANGE HIGHLIGHTS -->
                    <div class="analytics-panel fade-in">
                        <div class="panel-header">
                            <div class="panel-title">
                                <i class="fas fa-highlighter"></i>
                                <h3>Change Highlights & Analysis</h3>
                            </div>
                            <span style="color: var(--gray);">
                                <?php echo count($highlights_data); ?> changes detected
                            </span>
                        </div>
                        
                        <div class="highlights-list">
                            <?php foreach ($highlights_data as $highlight): 
                                $importance_class = $highlight['importance'];
                            ?>
                            <div class="change-highlight <?php echo $importance_class; ?>" id="highlight-<?php echo $highlight['id']; ?>">
                                <div class="change-header">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <span class="change-type <?php echo $highlight['change_type']; ?>">
                                            <?php echo ucfirst($highlight['change_type']); ?>
                                        </span>
                                        <span class="change-section">
                                            <?php echo htmlspecialchars($highlight['section']); ?>
                                        </span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <?php if ($highlight['line_start']): ?>
                                        <span style="font-size: 0.85rem; color: var(--gray);">
                                            Lines <?php echo $highlight['line_start']; ?>-<?php echo $highlight['line_end']; ?>
                                        </span>
                                        <?php endif; ?>
                                        <span style="padding: 3px 10px; border-radius: 20px; background: rgba(0,0,0,0.1); font-size: 0.85rem; font-weight: bold;">
                                            <?php echo ucfirst($highlight['importance']); ?> Priority
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="change-content">
                                    <?php if ($highlight['old_text']): ?>
                                    <div class="old-text">
                                        <?php echo htmlspecialchars($highlight['old_text']); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($highlight['new_text']): ?>
                                    <div class="new-text">
                                        <?php echo htmlspecialchars($highlight['new_text']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($highlight['notes']): ?>
                                <div class="change-notes">
                                    <strong>Analysis:</strong> <?php echo htmlspecialchars($highlight['notes']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Comment form for this highlight -->
                                <div class="comment-form" style="margin-top: 15px;">
                                    <form method="POST" action="comparison.php?session=<?php echo $_GET['session']; ?>">
                                        <input type="hidden" name="action" value="add_comment">
                                        <input type="hidden" name="highlight_id" value="<?php echo $highlight['id']; ?>">
                                        <textarea name="comment" class="comment-input" 
                                                  placeholder="Add a comment about this change..." required></textarea>
                                        <div style="display: flex; justify-content: flex-end;">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-comment"></i> Add Comment
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                
                                <!-- Comments for this highlight -->
                                <?php 
                                $highlight_comments = array_filter($comments_data, function($comment) use ($highlight) {
                                    return $comment['highlight_id'] == $highlight['id'];
                                });
                                ?>
                                <?php if (!empty($highlight_comments)): ?>
                                <div class="comments-list" style="margin-top: 15px;">
                                    <?php foreach ($highlight_comments as $comment): ?>
                                    <div class="comment-item">
                                        <div class="comment-header">
                                            <div class="comment-author">
                                                <div class="author-avatar">
                                                    <?php echo strtoupper(substr($comment['first_name'], 0, 1) . substr($comment['last_name'], 0, 1)); ?>
                                                </div>
                                                <div class="author-info">
                                                    <h4><?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?></h4>
                                                    <div class="author-role"><?php echo ucfirst(str_replace('_', ' ', $comment['role'])); ?></div>
                                                </div>
                                            </div>
                                            <div class="comment-time">
                                                <?php echo date('M d, Y H:i', strtotime($comment['created_at'])); ?>
                                            </div>
                                        </div>
                                        <div class="comment-text">
                                            <?php echo htmlspecialchars($comment['comment']); ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- GENERAL COMMENTS SECTION -->
                    <div class="comments-section fade-in">
                        <div class="panel-header">
                            <div class="panel-title">
                                <i class="fas fa-comments"></i>
                                <h3>General Comments & Discussion</h3>
                            </div>
                            <span style="color: var(--gray);">
                                <?php echo count($comments_data); ?> total comments
                            </span>
                        </div>
                        
                        <div class="comment-form">
                            <form method="POST" action="comparison.php?session=<?php echo $_GET['session']; ?>">
                                <input type="hidden" name="action" value="add_comment">
                                <input type="hidden" name="highlight_id" value="0">
                                <textarea name="comment" class="comment-input" 
                                          placeholder="Add a general comment about this comparison..." required></textarea>
                                <div style="display: flex; justify-content: flex-end; gap: 15px;">
                                    <button type="reset" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Clear
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Post Comment
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <div class="comments-list">
                            <?php 
                            $general_comments = array_filter($comments_data, function($comment) {
                                return $comment['highlight_id'] == 0;
                            });
                            ?>
                            <?php if (empty($general_comments)): ?>
                            <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                                <i class="fas fa-comments" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                                <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Comments Yet</h3>
                                <p>Be the first to add a comment about this comparison.</p>
                            </div>
                            <?php else: ?>
                                <?php foreach ($general_comments as $comment): ?>
                                <div class="comment-item">
                                    <div class="comment-header">
                                        <div class="comment-author">
                                            <div class="author-avatar">
                                                <?php echo strtoupper(substr($comment['first_name'], 0, 1) . substr($comment['last_name'], 0, 1)); ?>
                                            </div>
                                            <div class="author-info">
                                                <h4><?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?></h4>
                                                <div class="author-role"><?php echo ucfirst(str_replace('_', ' ', $comment['role'])); ?></div>
                                            </div>
                                        </div>
                                        <div class="comment-time">
                                            <?php echo date('M d, Y H:i', strtotime($comment['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="comment-text">
                                        <?php echo htmlspecialchars($comment['comment']); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                <!-- NEW COMPARISON FORM -->
                <div class="new-comparison-form fade-in">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-plus-circle"></i>
                            <h2>Create New Comparison</h2>
                        </div>
                        <span style="color: var(--gray);">
                            Compare different versions of documents
                        </span>
                    </div>
                    
                    <form method="POST" action="comparison.php">
                        <input type="hidden" name="action" value="compare">
                        
                        <div style="margin-bottom: 30px;">
                            <h3 style="color: var(--qc-blue); margin-bottom: 15px;">
                                <i class="fas fa-file-alt"></i> Select Document Versions to Compare
                            </h3>
                            <p style="color: var(--gray); margin-bottom: 20px;">
                                Choose an original version and an amended version to compare. The system will 
                                automatically highlight changes and provide AI-powered analysis.
                            </p>
                        </div>
                        
                        <div class="version-selector">
                            <!-- OLD VERSION SELECTION -->
                            <div class="version-box" id="oldVersionBox" onclick="selectVersion('old')">
                                <i class="fas fa-history" style="font-size: 3rem; color: var(--qc-blue); margin-bottom: 15px;"></i>
                                <div class="version-title">Original Version</div>
                                <div class="version-meta">Select the baseline document version</div>
                                <div class="version-info" id="oldVersionInfo">
                                    No version selected
                                </div>
                                <input type="hidden" name="old_version_id" id="oldVersionId">
                                <input type="hidden" name="document_id" id="documentId">
                                <input type="hidden" name="document_type" id="documentType">
                            </div>
                            
                            <!-- NEW VERSION SELECTION -->
                            <div class="version-box" id="newVersionBox" onclick="selectVersion('new')">
                                <i class="fas fa-file-medical-alt" style="font-size: 3rem; color: var(--qc-green); margin-bottom: 15px;"></i>
                                <div class="version-title">Amended Version</div>
                                <div class="version-meta">Select the modified document version</div>
                                <div class="version-info" id="newVersionInfo">
                                    No version selected
                                </div>
                                <input type="hidden" name="new_version_id" id="newVersionId">
                            </div>
                        </div>
                        
                        <div style="text-align: center; margin: 30px 0;">
                            <i class="fas fa-exchange-alt" style="font-size: 2rem; color: var(--qc-gold);"></i>
                            <div style="margin-top: 10px; color: var(--gray);">
                                Compare changes between selected versions
                            </div>
                        </div>
                        
                        <div class="action-buttons" style="justify-content: center;">
                            <button type="submit" class="btn btn-primary" id="compareBtn" disabled>
                                <i class="fas fa-code-branch"></i> Start Comparison
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="clearSelection()">
                                <i class="fas fa-eraser"></i> Clear Selection
                            </button>
                        </div>
                    </form>
                </div>

                <!-- RECENT COMPARISONS -->
                <div class="recent-comparisons fade-in">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-history"></i>
                            <h2>Your Recent Comparisons</h2>
                        </div>
                        <a href="amendments.php" class="btn btn-secondary">
                            <i class="fas fa-external-link-alt"></i> View All
                        </a>
                    </div>
                    
                    <?php if (empty($recent_comparisons)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                        <i class="fas fa-exchange-alt" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Comparisons Found</h3>
                        <p>You haven't created any comparisons yet. Start by creating a new comparison above.</p>
                    </div>
                    <?php else: ?>
                    <ul class="comparison-list">
                        <?php foreach ($recent_comparisons as $comparison): 
                            $document_type_icon = $comparison['document_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                            $document_type_color = $comparison['document_type'] === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
                        ?>
                        <li class="comparison-item">
                            <div class="comparison-icon" style="color: <?php echo $document_type_color; ?>;">
                                <i class="fas <?php echo $document_type_icon; ?>"></i>
                            </div>
                            <div class="comparison-content">
                                <div class="comparison-title"><?php echo htmlspecialchars($comparison['doc_title']); ?></div>
                                <div class="comparison-meta">
                                    <span class="comparison-session"><?php echo htmlspecialchars($comparison['session_code']); ?></span>
                                    <span><?php echo htmlspecialchars($comparison['first_name'] . ' ' . $comparison['last_name']); ?></span>
                                    <span><?php echo date('M d, Y', strtotime($comparison['created_at'])); ?></span>
                                    <span><?php echo $comparison['view_count']; ?> views</span>
                                </div>
                            </div>
                            <a href="comparison.php?session=<?php echo htmlspecialchars($comparison['session_code']); ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>

                <?php endif; ?>

                <!-- AMENDMENT LINKING -->
                <?php if (!empty($amendments) && !$comparison_data): ?>
                <div class="analytics-panel fade-in">
                    <div class="panel-header">
                        <div class="panel-title">
                            <i class="fas fa-link"></i>
                            <h3>Link to Amendment Submissions</h3>
                        </div>
                        <span style="color: var(--gray);">
                            Connect comparisons with amendment proposals
                        </span>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                            <?php foreach ($amendments as $amendment): ?>
                            <div style="background: var(--off-white); padding: 20px; border-radius: var(--border-radius); border: 1px solid var(--gray-light);">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                                    <div>
                                        <h4 style="color: var(--qc-blue); margin-bottom: 5px;"><?php echo htmlspecialchars($amendment['title']); ?></h4>
                                        <div style="font-size: 0.9rem; color: var(--gray);">
                                            <?php echo htmlspecialchars($amendment['amendment_number']); ?>
                                        </div>
                                    </div>
                                    <span style="padding: 3px 10px; border-radius: 20px; background: <?php 
                                        echo $amendment['status'] == 'pending' ? 'var(--qc-yellow)' : 
                                               ($amendment['status'] == 'under_review' ? 'var(--qc-blue)' : 'var(--gray-light)');
                                    ?>; color: var(--white); font-size: 0.85rem; font-weight: bold;">
                                        <?php echo ucfirst(str_replace('_', ' ', $amendment['status'])); ?>
                                    </span>
                                </div>
                                <div style="margin-bottom: 15px; color: var(--gray-dark); font-size: 0.95rem;">
                                    <?php echo substr(strip_tags($amendment['description']), 0, 150); ?>...
                                </div>
                                <div class="action-buttons" style="justify-content: flex-end;">
                                    <a href="amendment_submission.php?id=<?php echo $amendment['id']; ?>" class="btn btn-secondary">
                                        <i class="fas fa-external-link-alt"></i> View Amendment
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- SHARE COMPARISON MODAL -->
    <div class="modal-overlay" id="shareModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-share"></i> Share Comparison</h2>
                <button class="modal-close" onclick="closeModal('shareModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="comparison.php?session=<?php echo $_GET['session'] ?? ''; ?>">
                    <input type="hidden" name="action" value="share_comparison">
                    <input type="hidden" name="session_id" id="shareSessionId">
                    
                    <div style="margin-bottom: 25px;">
                        <label style="display: block; margin-bottom: 10px; font-weight: bold; color: var(--qc-blue);">
                            <i class="fas fa-user-friends"></i> Share With User
                        </label>
                        <select name="shared_with" class="form-control" style="width: 100%; padding: 12px; border: 1px solid var(--gray-light); border-radius: var(--border-radius);" required>
                            <option value="">Select a user...</option>
                            <?php foreach ($available_users as $user_option): ?>
                            <option value="<?php echo $user_option['id']; ?>">
                                <?php echo htmlspecialchars($user_option['first_name'] . ' ' . $user_option['last_name']); ?>
                                (<?php echo ucfirst(str_replace('_', ' ', $user_option['role'])); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 25px;">
                        <label style="display: block; margin-bottom: 10px; font-weight: bold; color: var(--qc-blue);">
                            <i class="fas fa-key"></i> Permissions
                        </label>
                        <div style="display: flex; gap: 20px;">
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="radio" name="permissions" value="view" checked>
                                <span>View Only</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="radio" name="permissions" value="comment">
                                <span>View & Comment</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="radio" name="permissions" value="edit">
                                <span>Full Access</span>
                            </label>
                        </div>
                    </div>
                    
                    <div style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); border: 1px solid var(--gray-light); margin-bottom: 25px;">
                        <h4 style="color: var(--qc-blue); margin-bottom: 10px;">Sharing Information</h4>
                        <p style="color: var(--gray); font-size: 0.9rem;">
                            Shared comparisons can be accessed by the selected user through their dashboard. 
                            You can revoke access at any time by deleting the share.
                        </p>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('shareModal')">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-share"></i> Share Now
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- VERSION SELECTION MODAL -->
    <div class="modal-overlay" id="versionModal">
        <div class="modal" style="max-width: 800px;">
            <div class="modal-header">
                <h2><i class="fas fa-file-alt"></i> Select Document Version</h2>
                <button class="modal-close" onclick="closeModal('versionModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 25px;">
                    <label style="display: block; margin-bottom: 10px; font-weight: bold; color: var(--qc-blue);">
                        Available Document Versions
                    </label>
                    <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--gray-light); border-radius: var(--border-radius);">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--off-white);">
                                    <th style="padding: 15px; text-align: left; border-bottom: 2px solid var(--gray-light);">Document</th>
                                    <th style="padding: 15px; text-align: left; border-bottom: 2px solid var(--gray-light);">Version</th>
                                    <th style="padding: 15px; text-align: left; border-bottom: 2px solid var(--gray-light);">Date</th>
                                    <th style="padding: 15px; text-align: left; border-bottom: 2px solid var(--gray-light);">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($available_versions as $version): 
                                    $type_icon = $version['doc_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                                    $type_color = $version['doc_type'] === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
                                ?>
                                <tr style="border-bottom: 1px solid var(--gray-light);">
                                    <td style="padding: 15px;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <i class="fas <?php echo $type_icon; ?>" style="color: <?php echo $type_color; ?>;"></i>
                                            <div>
                                                <div style="font-weight: bold; color: var(--qc-blue);">
                                                    <?php echo htmlspecialchars($version['doc_number'] ?? 'N/A'); ?>
                                                </div>
                                                <div style="font-size: 0.9rem; color: var(--gray);">
                                                    <?php echo htmlspecialchars(substr($version['doc_title'] ?? 'No title', 0, 50)); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding: 15px;">
                                        <span style="padding: 5px 15px; background: var(--off-white); border-radius: 20px; font-weight: bold;">
                                            V<?php echo $version['version_number']; ?>
                                        </span>
                                    </td>
                                    <td style="padding: 15px; color: var(--gray);">
                                        <?php echo date('M d, Y', strtotime($version['created_at'])); ?>
                                    </td>
                                    <!-- In the version selection modal, fix the button parameters -->
<td style="padding: 15px;">
    <button type="button" class="btn btn-primary" 
            onclick="selectVersionFromModal(<?php echo $version['id']; ?>, '<?php echo $version['document_id']; ?>', '<?php echo $version['document_type']; ?>', '<?php echo addslashes(htmlspecialchars($version['doc_number'] ?? 'N/A', ENT_QUOTES)); ?>', '<?php echo addslashes(htmlspecialchars($version['doc_title'] ?? 'No title', ENT_QUOTES)); ?>', '<?php echo $version['version_number']; ?>', '<?php echo date('M d, Y', strtotime($version['created_at'])); ?>')">
        <i class="fas fa-check"></i> Select
    </button>
</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('versionModal')">
                        <i class="fas fa-times"></i> Cancel
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
                    <h3>Change Comparison Module</h3>
                    <p>
                        Compare different versions of ordinances and resolutions. Visualize changes, 
                        analyze impact, and collaborate with team members on amendment reviews.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="amendments.php"><i class="fas fa-file-medical-alt"></i> Amendments</a></li>
                    <li><a href="comparison.php"><i class="fas fa-code-branch"></i> Change Comparison</a></li>
                    <li><a href="approval_control.php"><i class="fas fa-check-double"></i> Approval Control</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Comparison Guide</a></li>
                    <li><a href="#"><i class="fas fa-video"></i> Video Tutorials</a></li>
                    <li><a href="#"><i class="fas fa-robot"></i> AI Analytics Help</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback & Suggestions</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Change Comparison Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All comparison activities are logged.</p>
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
        
        // Version selection
        let selectedVersionType = null;
        let selectedOldVersion = null;
        let selectedNewVersion = null;
        
        function selectVersion(type) {
            selectedVersionType = type;
            openModal('versionModal');
        }
        
      // Fix the selectVersionFromModal function
function selectVersionFromModal(versionId, documentId, documentType, docNumber, docTitle, versionNumber, versionDate) {
    // Decode HTML entities for proper display
    docNumber = decodeHTMLEntities(docNumber);
    docTitle = decodeHTMLEntities(docTitle);
    
    if (selectedVersionType === 'old') {
        selectedOldVersion = versionId;
        document.getElementById('oldVersionId').value = versionId;
        document.getElementById('documentId').value = documentId;
        document.getElementById('documentType').value = documentType;
        document.getElementById('oldVersionInfo').innerHTML = `
            <strong>${docNumber}</strong><br>
            ${docTitle}<br>
            <small>Version ${versionNumber} • ${versionDate}</small>
        `;
        document.getElementById('oldVersionBox').classList.add('selected');
    } else {
        selectedNewVersion = versionId;
        document.getElementById('newVersionId').value = versionId;
        document.getElementById('newVersionInfo').innerHTML = `
            <strong>${docNumber}</strong><br>
            ${docTitle}<br>
            <small>Version ${versionNumber} • ${versionDate}</small>
        `;
        document.getElementById('newVersionBox').classList.add('selected');
    }
    
    // Check if both versions are selected
    if (selectedOldVersion && selectedNewVersion) {
        // Make sure they're not the same version
        if (selectedOldVersion === selectedNewVersion) {
            alert('Please select two different versions to compare.');
            document.getElementById('compareBtn').disabled = true;
        } else {
            document.getElementById('compareBtn').disabled = false;
        }
    }
    
    closeModal('versionModal');
}

// Helper function to decode HTML entities
function decodeHTMLEntities(text) {
    const textArea = document.createElement('textarea');
    textArea.innerHTML = text;
    return textArea.value;
}
        function clearSelection() {
            selectedOldVersion = null;
            selectedNewVersion = null;
            document.getElementById('oldVersionId').value = '';
            document.getElementById('newVersionId').value = '';
            document.getElementById('documentId').value = '';
            document.getElementById('documentType').value = '';
            document.getElementById('oldVersionInfo').innerHTML = 'No version selected';
            document.getElementById('newVersionInfo').innerHTML = 'No version selected';
            document.getElementById('oldVersionBox').classList.remove('selected');
            document.getElementById('newVersionBox').classList.remove('selected');
            document.getElementById('compareBtn').disabled = true;
        }
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        // Share comparison
        function shareComparison(sessionId) {
            document.getElementById('shareSessionId').value = sessionId;
            openModal('shareModal');
        }
        
        // Print comparison
        function printComparison() {
            window.print();
        }
        
        // Delete comparison
        function deleteComparison(sessionId) {
            if (confirm('Are you sure you want to delete this comparison? This action cannot be undone.')) {
                // In a real application, this would be an AJAX call or form submission
                console.log('Deleting comparison:', sessionId);
                // For now, just redirect to the main comparison page
                window.location.href = 'comparison.php?deleted=true';
            }
        }
        
        // Animate score bars
        document.addEventListener('DOMContentLoaded', function() {
            const scoreBars = document.querySelectorAll('.score-fill');
            scoreBars.forEach(bar => {
                const score = bar.getAttribute('data-score');
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = score + '%';
                }, 100);
            });
            
            // Add smooth scrolling to highlights
            document.querySelectorAll('.change-highlight').forEach(highlight => {
                highlight.addEventListener('click', function(e) {
                    if (e.target.tagName !== 'A' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'TEXTAREA') {
                        this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
            });
            
            // Auto-expand comment sections when clicking on highlight
            document.querySelectorAll('.change-highlight').forEach(highlight => {
                const commentForm = highlight.querySelector('.comment-form');
                if (commentForm) {
                    commentForm.style.display = 'none';
                    
                    const toggleBtn = document.createElement('button');
                    toggleBtn.className = 'btn btn-secondary';
                    toggleBtn.innerHTML = '<i class="fas fa-comment"></i> Add Comment';
                    toggleBtn.style.marginTop = '10px';
                    
                    toggleBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        commentForm.style.display = commentForm.style.display === 'none' ? 'block' : 'none';
                    });
                    
                    const header = highlight.querySelector('.change-header');
                    if (header) {
                        header.appendChild(toggleBtn);
                    }
                }
            });
            
            // Add search functionality
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.placeholder = 'Search changes...';
            searchInput.style.cssText = `
                padding: 12px 20px;
                border: 2px solid var(--gray-light);
                border-radius: var(--border-radius);
                font-size: 1rem;
                width: 100%;
                margin-bottom: 20px;
                transition: all 0.3s ease;
            `;
            
            const moduleHeader = document.querySelector('.module-header-content');
            if (moduleHeader && <?php echo $comparison_data ? 'true' : 'false'; ?>) {
                searchInput.style.marginTop = '20px';
                moduleHeader.appendChild(searchInput);
                
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    document.querySelectorAll('.change-highlight').forEach(highlight => {
                        const text = highlight.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            highlight.style.display = 'block';
                            highlight.style.animation = 'fadeIn 0.3s ease';
                        } else {
                            highlight.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>