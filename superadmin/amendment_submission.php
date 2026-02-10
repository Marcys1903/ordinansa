<?php
// amendment_submission.php - Amendment Submission Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to submit amendments
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
        
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        $title = $_POST['title'];
        $description = $_POST['description'];
        $proposed_changes = $_POST['proposed_changes'];
        $justification = $_POST['justification'];
        $current_version_id = $_POST['current_version_id'];
        $priority = $_POST['priority'] ?? 'medium';
        $authors = $_POST['authors'] ?? [];
        $committee_review = isset($_POST['committee_review']) ? 1 : 0;
        $committee_id = $_POST['committee_id'] ?? null;
        $public_hearing = isset($_POST['public_hearing']) ? 1 : 0;
        $public_hearing_date = $_POST['public_hearing_date'] ?? null;
        
        // Generate amendment number
        $prefix = "QC-AMEND";
        $year = date('Y');
        $month = date('m');
        
        // Get next sequence number
        $sequence_query = "SELECT COUNT(*) + 1 as next_num FROM amendment_submissions WHERE YEAR(created_at) = :year";
        $stmt = $conn->prepare($sequence_query);
        $stmt->bindParam(':year', $year);
        $stmt->execute();
        $result = $stmt->fetch();
        $sequence = str_pad($result['next_num'], 4, '0', STR_PAD_LEFT);
        
        $amendment_number = "$prefix-$year-$month-$sequence";
        
        // Insert amendment submission
        $query = "INSERT INTO amendment_submissions 
                 (document_id, document_type, amendment_number, title, description, 
                  proposed_changes, justification, current_version_id, priority, 
                  submitted_by, submitted_at, requires_committee_review, committee_id,
                  public_hearing_required, public_hearing_date, status) 
                 VALUES 
                 (:doc_id, :doc_type, :amendment_no, :title, :description, 
                  :changes, :justification, :version_id, :priority, 
                  :submitted_by, NOW(), :committee_review, :committee_id,
                  :public_hearing, :public_hearing_date, 'pending')";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->bindParam(':doc_type', $document_type);
        $stmt->bindParam(':amendment_no', $amendment_number);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':changes', $proposed_changes);
        $stmt->bindParam(':justification', $justification);
        $stmt->bindParam(':version_id', $current_version_id);
        $stmt->bindParam(':priority', $priority);
        $stmt->bindParam(':submitted_by', $user_id);
        $stmt->bindParam(':committee_review', $committee_review);
        $stmt->bindParam(':committee_id', $committee_id);
        $stmt->bindParam(':public_hearing', $public_hearing);
        $stmt->bindParam(':public_hearing_date', $public_hearing_date);
        $stmt->execute();
        
        $amendment_id = $conn->lastInsertId();
        
        // Create proposed version
        $version_query = "INSERT INTO document_versions (document_id, document_type, version_number, title, content, created_by, is_current) 
                         SELECT :doc_id, :doc_type, 
                                (SELECT MAX(version_number) + 1 FROM document_versions WHERE document_id = :doc_id AND document_type = :doc_type),
                                :title, :content, :created_by, 0";
        $stmt = $conn->prepare($version_query);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->bindParam(':doc_type', $document_type);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':content', $proposed_changes);
        $stmt->bindParam(':created_by', $user_id);
        $stmt->execute();
        
        $proposed_version_id = $conn->lastInsertId();
        
        // Update amendment with proposed version ID
        $update_query = "UPDATE amendment_submissions SET proposed_version_id = :version_id WHERE id = :amendment_id";
        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':version_id', $proposed_version_id);
        $stmt->bindParam(':amendment_id', $amendment_id);
        $stmt->execute();
        
        // Assign authors
        foreach ($authors as $author_id) {
            $author_query = "INSERT INTO amendment_authors (amendment_id, user_id, role, assigned_by) 
                           VALUES (:amendment_id, :author_id, 'author', :assigned_by)";
            $stmt = $conn->prepare($author_query);
            $stmt->bindParam(':amendment_id', $amendment_id);
            $stmt->bindParam(':author_id', $author_id);
            $stmt->bindParam(':assigned_by', $user_id);
            $stmt->execute();
        }
        
        // Auto-add current user as author if not already selected
        if (!in_array($user_id, $authors)) {
            $author_query = "INSERT INTO amendment_authors (amendment_id, user_id, role, assigned_by) 
                           VALUES (:amendment_id, :author_id, 'author', :assigned_by)";
            $stmt = $conn->prepare($author_query);
            $stmt->bindParam(':amendment_id', $amendment_id);
            $stmt->bindParam(':author_id', $user_id);
            $stmt->bindParam(':assigned_by', $user_id);
            $stmt->execute();
        }
        
        // Handle file uploads
        if (!empty($_FILES['attachments']['name'][0])) {
            $upload_dir = '../uploads/amendment_docs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_name = basename($_FILES['attachments']['name'][$i]);
                    $file_tmp = $_FILES['attachments']['tmp_name'][$i];
                    $file_size = $_FILES['attachments']['size'][$i];
                    $file_type = $_FILES['attachments']['type'][$i];
                    
                    // Generate unique filename
                    $unique_name = time() . '_' . uniqid() . '_' . $file_name;
                    $file_path = $upload_dir . $unique_name;
                    
                    if (move_uploaded_file($file_tmp, $file_path)) {
                        $file_query = "INSERT INTO amendment_attachments (amendment_id, file_name, file_path, file_type, file_size, uploaded_by) 
                                     VALUES (:amendment_id, :file_name, :file_path, :file_type, :file_size, :uploaded_by)";
                        $stmt = $conn->prepare($file_query);
                        $stmt->bindParam(':amendment_id', $amendment_id);
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
        
        // Create initial comparison
        $current_version_query = "SELECT content FROM document_versions WHERE id = :version_id";
        $stmt = $conn->prepare($current_version_query);
        $stmt->bindParam(':version_id', $current_version_id);
        $stmt->execute();
        $current_version = $stmt->fetch();
        
        $comparison_query = "INSERT INTO amendment_comparisons 
                            (amendment_id, old_text, new_text, change_type, change_description) 
                            VALUES 
                            (:amendment_id, :old_text, :new_text, 'modification', 'Initial amendment submission')";
        $stmt = $conn->prepare($comparison_query);
        $stmt->bindParam(':amendment_id', $amendment_id);
        $stmt->bindParam(':old_text', $current_version['content']);
        $stmt->bindParam(':new_text', $proposed_changes);
        $stmt->execute();
        
        // Log to amendment history
        $history_query = "INSERT INTO amendment_history (amendment_id, action, description, performed_by) 
                         VALUES (:amendment_id, 'SUBMITTED', 'Amendment submitted for review', :performed_by)";
        $stmt = $conn->prepare($history_query);
        $stmt->bindParam(':amendment_id', $amendment_id);
        $stmt->bindParam(':performed_by', $user_id);
        $stmt->execute();
        
        // Generate AI analytics (mocked data)
        $generateMockAnalytics($amendment_id, $conn);
        
        $conn->commit();
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'AMENDMENT_SUBMIT', 'Submitted amendment: {$amendment_number}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Amendment submitted successfully! Amendment Number: {$amendment_number}";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error submitting amendment: " . $e->getMessage();
    }
}

// Function to generate mock AI analytics
function generateMockAnalytics($amendment_id, $conn) {
    $mock_analytics = [
        ['success_probability', '85%'],
        ['review_time_estimate', '7-10 days'],
        ['similar_amendments', '3 found'],
        ['legal_compliance', 'High'],
        ['fiscal_impact', 'Low to Moderate'],
        ['public_support_probability', '72%'],
        ['committee_approval_likelihood', 'High'],
        ['implementation_complexity', 'Medium'],
        ['risk_level', 'Low'],
        ['recommended_actions', 'Proceed with committee review']
    ];
    
    foreach ($mock_analytics as $analytic) {
        $query = "INSERT INTO ai_amendment_analytics (amendment_id, analytics_type, data_key, data_value, confidence_score, generated_by) 
                 VALUES (:amendment_id, 'amendment_analysis', :data_key, :data_value, :confidence, :generated_by)";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':amendment_id', $amendment_id);
        $stmt->bindParam(':data_key', $analytic[0]);
        $stmt->bindParam(':data_value', $analytic[1]);
        $confidence = mt_rand(70, 95) / 100;
        $stmt->bindParam(':confidence', $confidence);
        $stmt->bindParam(':generated_by', $GLOBALS['user_id']);
        $stmt->execute();
    }
}

// Get available documents for amendment
$documents_query = "(
    SELECT 'ordinance' as doc_type, id, ordinance_number as doc_number, title, status 
    FROM ordinances 
    WHERE status IN ('approved', 'implemented', 'pending')
    ORDER BY created_at DESC
) UNION ALL (
    SELECT 'resolution' as doc_type, id, resolution_number as doc_number, title, status 
    FROM resolutions 
    WHERE status IN ('approved', 'pending')
    ORDER BY created_at DESC
) ORDER BY doc_type, doc_number DESC LIMIT 50";

$documents_stmt = $conn->query($documents_query);
$available_documents = $documents_stmt->fetchAll();

// Get available users for author assignment
$users_query = "SELECT id, first_name, last_name, role, department FROM users 
                WHERE is_active = 1 AND role IN ('councilor', 'admin', 'super_admin')
                ORDER BY last_name, first_name";
$users_stmt = $conn->query($users_query);
$available_users = $users_stmt->fetchAll();

// Get committees
$committees_query = "SELECT id, committee_name, committee_code FROM committees WHERE is_active = 1 ORDER BY committee_name";
$committees_stmt = $conn->query($committees_query);
$committees = $committees_stmt->fetchAll();

// Get user's recent amendments
$recent_amendments_query = "SELECT a.*, 
                           (SELECT doc_type FROM (
                               SELECT 'ordinance' as doc_type, id, ordinance_number FROM ordinances 
                               UNION ALL 
                               SELECT 'resolution' as doc_type, id, resolution_number FROM resolutions
                           ) d WHERE d.id = a.document_id) as doc_type,
                           CASE a.document_type 
                               WHEN 'ordinance' THEN (SELECT ordinance_number FROM ordinances WHERE id = a.document_id)
                               WHEN 'resolution' THEN (SELECT resolution_number FROM resolutions WHERE id = a.document_id)
                           END as doc_number
                           FROM amendment_submissions a 
                           WHERE a.submitted_by = :user_id 
                           ORDER BY a.created_at DESC 
                           LIMIT 10";

$recent_stmt = $conn->prepare($recent_amendments_query);
$recent_stmt->bindParam(':user_id', $user_id);
$recent_stmt->execute();
$recent_amendments = $recent_stmt->fetchAll();

// Get system-wide amendment statistics
$stats_query = "SELECT 
                COUNT(*) as total_amendments,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN priority = 'urgent' OR priority = 'emergency' THEN 1 ELSE 0 END) as `high_priority`
                FROM amendment_submissions";
$stats_stmt = $conn->query($stats_query);
$system_stats = $stats_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amendment Submission | QC Ordinance Tracker</title>
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
            --qc-purple: #6B46C1;
            --qc-purple-dark: #553C9A;
            --white: #FFFFFF;
            --off-white: #F8F9FA;
            --gray-light: #E9ECEF;
            --gray: #6C757D;
            --gray-dark: #343A40;
            --red: #C53030;
            --orange: #DD6B20;
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
            gap: 25px;
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
        
        /* AI ANALYTICS DASHBOARD */
        .ai-analytics-dashboard {
            background: linear-gradient(135deg, #1a202c 0%, #2d3748 100%);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-xl);
            border: 2px solid var(--qc-gold);
        }
        
        .ai-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .ai-icon {
            background: rgba(74, 222, 128, 0.2);
            border: 2px solid #4ade80;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: #4ade80;
        }
        
        .ai-title {
            flex: 1;
        }
        
        .ai-title h2 {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 5px;
        }
        
        .ai-title p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
        }
        
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .analytic-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--border-radius);
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .analytic-card:hover {
            transform: translateY(-5px);
            border-color: var(--qc-gold);
            box-shadow: var(--shadow-lg);
        }
        
        .analytic-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .analytic-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .analytic-title {
            flex: 1;
        }
        
        .analytic-title h4 {
            color: var(--white);
            font-size: 1rem;
            margin-bottom: 3px;
        }
        
        .analytic-title span {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.85rem;
        }
        
        .analytic-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--white);
            margin-bottom: 10px;
        }
        
        .analytic-progress {
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .analytic-progress-bar {
            height: 100%;
            border-radius: 3px;
        }
        
        .analytic-footer {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
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
            background: var(--qc-purple);
            border-color: var(--qc-purple);
            color: var(--white);
            box-shadow: 0 6px 20px rgba(107, 70, 193, 0.3);
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
            color: var(--qc-purple);
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
        .amendment-form-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .amendment-form-container {
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
            color: var(--qc-purple);
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
            border-color: var(--qc-purple);
            box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.1);
        }
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236c757d' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 40px;
        }
        
        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }
        
        .document-preview {
            background: var(--off-white);
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 20px;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
        }
        
        .document-preview h4 {
            color: var(--qc-blue);
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        
        .document-preview-content {
            font-size: 0.9rem;
            color: var(--gray-dark);
            line-height: 1.6;
        }
        
        .document-preview-content .highlight {
            background: rgba(212, 175, 55, 0.2);
            padding: 2px 5px;
            border-radius: 3px;
            border: 1px solid var(--qc-gold);
        }
        
        /* Priority Selector */
        .priority-selector {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .priority-option {
            flex: 1;
            min-width: 120px;
        }
        
        .priority-radio {
            display: none;
        }
        
        .priority-label {
            display: block;
            padding: 15px;
            background: var(--off-white);
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: var(--gray-dark);
        }
        
        .priority-radio:checked + .priority-label {
            border-color: var(--qc-purple);
            background: rgba(107, 70, 193, 0.1);
            color: var(--qc-purple);
            font-weight: bold;
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
        }
        
        .priority-low .priority-label { border-left: 4px solid var(--green); }
        .priority-medium .priority-label { border-left: 4px solid var(--orange); }
        .priority-high .priority-label { border-left: 4px solid var(--red); }
        .priority-urgent .priority-label { border-left: 4px solid #9b2c2c; }
        .priority-emergency .priority-label { border-left: 4px solid #63171b; }
        
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
            accent-color: var(--qc-purple);
        }
        
        .author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--qc-purple) 0%, var(--qc-purple-dark) 100%);
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
            border-color: var(--qc-purple);
            background: var(--off-white);
        }
        
        .file-upload-area.dragover {
            border-color: var(--qc-gold);
            background: rgba(212, 175, 55, 0.05);
        }
        
        .upload-icon {
            font-size: 3rem;
            color: var(--qc-purple);
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
            color: var(--qc-purple);
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
        
        /* Recent Amendments */
        .recent-amendments {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .amendments-list {
            list-style: none;
        }
        
        .amendment-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .amendment-item:hover {
            background: var(--off-white);
            transform: translateX(5px);
        }
        
        .amendment-item:last-child {
            border-bottom: none;
        }
        
        .amendment-icon {
            width: 50px;
            height: 50px;
            background: var(--off-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-purple);
            font-size: 1.2rem;
        }
        
        .amendment-content {
            flex: 1;
        }
        
        .amendment-title {
            font-weight: 600;
            color: var(--qc-purple);
            margin-bottom: 5px;
            font-size: 1rem;
        }
        
        .amendment-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .amendment-number {
            font-weight: 600;
            color: var(--qc-gold);
        }
        
        .amendment-status {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-pending { background: #dbeafe; color: #1e40af; }
        .status-under_review { background: #e0e7ff; color: #3730a3; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        
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
            background: linear-gradient(135deg, var(--qc-purple) 0%, var(--qc-purple-dark) 100%);
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
            color: var(--qc-purple);
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
            background: linear-gradient(135deg, var(--qc-purple) 0%, var(--qc-purple-dark) 100%);
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
            background: linear-gradient(135deg, var(--qc-purple) 0%, var(--qc-purple-dark) 100%);
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
            
            .analytics-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .priority-selector {
                flex-direction: column;
            }
            
            .priority-option {
                min-width: 100%;
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
        
        /* Toggle switches */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--gray-light);
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: var(--qc-purple);
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(30px);
        }
        
        .toggle-label {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
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
                    <p>Amendment Submission Module | Modify Existing Ordinances & Resolutions</p>
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
                        <i class="fas fa-edit"></i> Amendment Module
                    </h3>
                    <p class="sidebar-subtitle">Amendment Submission Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="amendment-submission-container">
                <!-- FIXED MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">AMENDMENT SUBMISSION MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-file-medical-alt"></i>
                            </div>
                            <div class="module-title">
                                <h1>Submit Proposed Amendments</h1>
                                <p class="module-subtitle">
                                    Propose changes to existing ordinances and resolutions. 
                                    Submit amendments with detailed justifications, track review progress, 
                                    and manage version control for legislative documents.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-file-contract"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $system_stats['total_amendments'] ?? 0; ?></h3>
                                    <p>Total Amendments</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $system_stats['approved'] ?? 0; ?></h3>
                                    <p>Approved</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $system_stats['pending'] ?? 0; ?></h3>
                                    <p>Pending Review</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $system_stats['high_priority'] ?? 0; ?></h3>
                                    <p>High Priority</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- AI ANALYTICS DASHBOARD -->
                <div class="ai-analytics-dashboard fade-in">
                    <div class="ai-header">
                        <div class="ai-icon">
                            <i class="fas fa-brain"></i>
                        </div>
                        <div class="ai-title">
                            <h2>AI-Powered Amendment Analytics</h2>
                            <p>Smart predictions and recommendations for your amendment submission</p>
                        </div>
                    </div>
                    
                    <div class="analytics-grid">
                        <div class="analytic-card">
                            <div class="analytic-header">
                                <div class="analytic-icon" style="background: rgba(74, 222, 128, 0.2); color: #4ade80;">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="analytic-title">
                                    <h4>Success Probability</h4>
                                    <span>Based on similar amendments</span>
                                </div>
                            </div>
                            <div class="analytic-value" id="aiSuccessProbability">85%</div>
                            <div class="analytic-progress">
                                <div class="analytic-progress-bar" style="width: 85%; background: #4ade80;"></div>
                            </div>
                            <div class="analytic-footer">
                                <span>High chance of approval</span>
                                <span>85% confident</span>
                            </div>
                        </div>
                        
                        <div class="analytic-card">
                            <div class="analytic-header">
                                <div class="analytic-icon" style="background: rgba(96, 165, 250, 0.2); color: #60a5fa;">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="analytic-title">
                                    <h4>Review Time Estimate</h4>
                                    <span>Expected processing time</span>
                                </div>
                            </div>
                            <div class="analytic-value" id="aiReviewTime">7-10 days</div>
                            <div class="analytic-progress">
                                <div class="analytic-progress-bar" style="width: 70%; background: #60a5fa;"></div>
                            </div>
                            <div class="analytic-footer">
                                <span>Standard priority</span>
                                <span>Based on similar cases</span>
                            </div>
                        </div>
                        
                        <div class="analytic-card">
                            <div class="analytic-header">
                                <div class="analytic-icon" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b;">
                                    <i class="fas fa-balance-scale"></i>
                                </div>
                                <div class="analytic-title">
                                    <h4>Legal Compliance</h4>
                                    <span>Constitutional & regulatory check</span>
                                </div>
                            </div>
                            <div class="analytic-value" id="aiLegalCompliance">High</div>
                            <div class="analytic-progress">
                                <div class="analytic-progress-bar" style="width: 90%; background: #f59e0b;"></div>
                            </div>
                            <div class="analytic-footer">
                                <span>No conflicts detected</span>
                                <span>90% compliant</span>
                            </div>
                        </div>
                        
                        <div class="analytic-card">
                            <div class="analytic-header">
                                <div class="analytic-icon" style="background: rgba(168, 85, 247, 0.2); color: #a855f7;">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="analytic-title">
                                    <h4>Public Support</h4>
                                    <span>Estimated community acceptance</span>
                                </div>
                            </div>
                            <div class="analytic-value" id="aiPublicSupport">72%</div>
                            <div class="analytic-progress">
                                <div class="analytic-progress-bar" style="width: 72%; background: #a855f7;"></div>
                            </div>
                            <div class="analytic-footer">
                                <span>Based on sentiment analysis</span>
                                <span>Positive trend</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WORKING STEPS INDICATOR -->
                <div class="steps-indicator fade-in">
                    <div class="step active" data-step="1">
                        <div class="step-number">1</div>
                        <div class="step-label">Select Document</div>
                        <div class="step-description">Choose ordinance/resolution to amend</div>
                    </div>
                    <div class="step" data-step="2">
                        <div class="step-number">2</div>
                        <div class="step-label">Amendment Details</div>
                        <div class="step-description">Describe changes and justification</div>
                    </div>
                    <div class="step" data-step="3">
                        <div class="step-number">3</div>
                        <div class="step-label">Proposed Changes</div>
                        <div class="step-description">Write the amended content</div>
                    </div>
                    <div class="step" data-step="4">
                        <div class="step-number">4</div>
                        <div class="step-label">Authors & Review</div>
                        <div class="step-description">Assign authors and set review process</div>
                    </div>
                    <div class="step" data-step="5">
                        <div class="step-number">5</div>
                        <div class="step-label">Review & Submit</div>
                        <div class="step-description">Final review and submission</div>
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

                <!-- Amendment Submission Form -->
                <form id="amendmentSubmissionForm" method="POST" enctype="multipart/form-data" class="fade-in">
                    <div class="amendment-form-container">
                        <!-- Main Form -->
                        <div class="form-main">
                            <!-- Document Selection -->
                            <div class="form-section step-content" data-step="1">
                                <h3 class="section-title">
                                    <i class="fas fa-file-alt"></i>
                                    1. Select Document to Amend
                                </h3>
                                
                                <div class="form-group">
                                    <label for="document_select" class="form-label required">Choose Document</label>
                                    <select id="document_select" name="document_id" class="form-control" required onchange="loadDocumentDetails(this.value)">
                                        <option value="">-- Select an Ordinance or Resolution --</option>
                                        <?php foreach ($available_documents as $doc): 
                                            $type_text = $doc['doc_type'] === 'ordinance' ? 'Ordinance' : 'Resolution';
                                        ?>
                                        <option value="<?php echo $doc['id']; ?>" data-type="<?php echo $doc['doc_type']; ?>">
                                            <?php echo htmlspecialchars($doc['doc_number'] . ' - ' . $doc['title'] . ' (' . $type_text . ')'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" id="document_type" name="document_type" value="">
                                    <input type="hidden" id="current_version_id" name="current_version_id" value="">
                                </div>
                                
                                <div class="document-preview" id="documentPreview" style="display: none;">
                                    <!-- Document preview will be loaded here -->
                                </div>
                            </div>

                            <!-- Amendment Details -->
                            <div class="form-section step-content" data-step="2" style="display: none;">
                                <h3 class="section-title">
                                    <i class="fas fa-info-circle"></i>
                                    2. Amendment Details
                                </h3>
                                
                                <div class="form-group">
                                    <label for="title" class="form-label required">Amendment Title</label>
                                    <input type="text" id="title" name="title" class="form-control" 
                                           placeholder="Enter a clear title for this amendment" 
                                           required maxlength="255">
                                </div>
                                
                                <div class="form-group">
                                    <label for="description" class="form-label required">Amendment Description</label>
                                    <textarea id="description" name="description" class="form-control" 
                                              placeholder="Provide a brief summary of what this amendment aims to change" 
                                              rows="4" required></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="justification" class="form-label required">Justification</label>
                                    <textarea id="justification" name="justification" class="form-control" 
                                              placeholder="Explain why this amendment is necessary and what problem it solves" 
                                              rows="4" required></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label required">Priority Level</label>
                                    <div class="priority-selector">
                                        <div class="priority-option priority-low">
                                            <input type="radio" name="priority" value="low" id="priority_low" class="priority-radio" checked>
                                            <label for="priority_low" class="priority-label">
                                                <i class="fas fa-arrow-down"></i> Low Priority
                                            </label>
                                        </div>
                                        <div class="priority-option priority-medium">
                                            <input type="radio" name="priority" value="medium" id="priority_medium" class="priority-radio">
                                            <label for="priority_medium" class="priority-label">
                                                <i class="fas fa-equals"></i> Medium Priority
                                            </label>
                                        </div>
                                        <div class="priority-option priority-high">
                                            <input type="radio" name="priority" value="high" id="priority_high" class="priority-radio">
                                            <label for="priority_high" class="priority-label">
                                                <i class="fas fa-arrow-up"></i> High Priority
                                            </label>
                                        </div>
                                        <div class="priority-option priority-urgent">
                                            <input type="radio" name="priority" value="urgent" id="priority_urgent" class="priority-radio">
                                            <label for="priority_urgent" class="priority-label">
                                                <i class="fas fa-exclamation"></i> Urgent
                                            </label>
                                        </div>
                                        <div class="priority-option priority-emergency">
                                            <input type="radio" name="priority" value="emergency" id="priority_emergency" class="priority-radio">
                                            <label for="priority_emergency" class="priority-label">
                                                <i class="fas fa-skull-crossbones"></i> Emergency
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Proposed Changes -->
                            <div class="form-section step-content" data-step="3" style="display: none;">
                                <h3 class="section-title">
                                    <i class="fas fa-edit"></i>
                                    3. Proposed Changes
                                </h3>
                                
                                <div class="form-group">
                                    <label class="form-label required">Proposed Amended Content</label>
                                    <div id="editor-container"></div>
                                    <textarea id="proposed_changes" name="proposed_changes" style="display: none;" required></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Change Summary</label>
                                    <div style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); border: 1px solid var(--gray-light);">
                                        <div id="changeSummary">
                                            <p style="color: var(--gray); font-style: italic;">Proposed changes will be analyzed here...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Authors and Review Process -->
                            <div class="form-section step-content" data-step="4" style="display: none;">
                                <h3 class="section-title">
                                    <i class="fas fa-user-edit"></i>
                                    4. Authors and Review Process
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
                                    <label class="form-label">Review Process Settings</label>
                                    
                                    <div class="toggle-label">
                                        <span>Require Committee Review</span>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="committee_review" id="committee_review">
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div id="committeeSelection" style="display: none; margin-top: 15px;">
                                        <label for="committee_id" class="form-label">Select Committee</label>
                                        <select id="committee_id" name="committee_id" class="form-control">
                                            <option value="">-- Select a Committee --</option>
                                            <?php foreach ($committees as $committee): ?>
                                            <option value="<?php echo $committee['id']; ?>">
                                                <?php echo htmlspecialchars($committee['committee_name'] . ' (' . $committee['committee_code'] . ')'); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="toggle-label" style="margin-top: 15px;">
                                        <span>Require Public Hearing</span>
                                        <label class="toggle-switch">
                                            <input type="checkbox" name="public_hearing" id="public_hearing">
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                    
                                    <div id="hearingDateSelection" style="display: none; margin-top: 15px;">
                                        <label for="public_hearing_date" class="form-label">Proposed Hearing Date</label>
                                        <input type="date" id="public_hearing_date" name="public_hearing_date" class="form-control">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Upload Supporting Documents</label>
                                    <div class="file-upload-area" id="fileUploadArea">
                                        <i class="fas fa-cloud-upload-alt upload-icon"></i>
                                        <div class="upload-text">Drag & drop files here</div>
                                        <div class="upload-subtext">or click to browse</div>
                                        <div class="btn btn-secondary">
                                            <i class="fas fa-folder-open"></i> Browse Files
                                        </div>
                                        <input type="file" name="attachments[]" id="fileInput" 
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
                                              placeholder="Any additional notes or instructions for this amendment..." 
                                              rows="3"></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label class="toggle-label">
                                        <input type="checkbox" id="confirm_understanding" required>
                                        <span>I confirm that I have reviewed all amendment details and understand that this submission will undergo the official review process.</span>
                                    </label>
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
                                    <i class="fas fa-check"></i> Submit Amendment
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
                                    <button type="button" class="btn btn-secondary" onclick="compareVersions()">
                                        <i class="fas fa-code-branch"></i> Compare
                                    </button>
                                    <a href="amendments.php" class="btn btn-secondary">
                                        <i class="fas fa-list"></i> View All Amendments
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
                                            <span>Document Selected:</span>
                                            <span id="docSelectedDisplay">None</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                            <span>Priority Level:</span>
                                            <span id="priorityDisplay">Low</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between;">
                                            <span>Authors Selected:</span>
                                            <span id="authorsCount">1</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Amendment Guidelines -->
                            <div class="form-section">
                                <h3 class="section-title">
                                    <i class="fas fa-book"></i>
                                    Amendment Guidelines
                                </h3>
                                
                                <div style="font-size: 0.9rem; color: var(--gray);">
                                    <p style="margin-bottom: 10px;">Ensure your amendment:</p>
                                    <ul style="padding-left: 20px; margin-bottom: 15px;">
                                        <li>Clearly states what changes are proposed</li>
                                        <li>Includes proper justification</li>
                                        <li>Follows legal formatting standards</li>
                                        <li>Has all required supporting documents</li>
                                        <li>Assigns appropriate authors/sponsors</li>
                                    </ul>
                                    <p>For assistance, contact the <strong>Legislative Services Division</strong> at extension 5678.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Recent Amendments -->
                <div class="recent-amendments fade-in">
                    <div class="section-title">
                        <i class="fas fa-history"></i>
                        <h2>Your Recent Amendments</h2>
                    </div>
                    
                    <?php if (empty($recent_amendments)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                        <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Amendments Found</h3>
                        <p>You haven't submitted any amendments yet. Start by submitting your first amendment above.</p>
                    </div>
                    <?php else: ?>
                    <ul class="amendments-list">
                        <?php foreach ($recent_amendments as $amendment): 
                            $status_class = 'status-' . $amendment['status'];
                            $type_text = $amendment['doc_type'] === 'ordinance' ? 'Ordinance' : 'Resolution';
                        ?>
                        <li class="amendment-item">
                            <div class="amendment-icon">
                                <i class="fas fa-file-medical-alt"></i>
                            </div>
                            <div class="amendment-content">
                                <div class="amendment-title"><?php echo htmlspecialchars($amendment['title']); ?></div>
                                <div class="amendment-meta">
                                    <span class="amendment-number"><?php echo htmlspecialchars($amendment['amendment_number']); ?></span>
                                    <span>to <?php echo htmlspecialchars($amendment['doc_number'] ?? 'N/A'); ?></span>
                                    <span class="amendment-status <?php echo $status_class; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $amendment['status'])); ?>
                                    </span>
                                    <span><?php echo date('M d, Y', strtotime($amendment['created_at'])); ?></span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary edit-amendment-btn" 
                                    data-amendment-id="<?php echo $amendment['id']; ?>"
                                    data-amendment-number="<?php echo htmlspecialchars($amendment['amendment_number']); ?>"
                                    data-amendment-title="<?php echo htmlspecialchars($amendment['title']); ?>"
                                    data-amendment-status="<?php echo $amendment['status']; ?>">
                                <i class="fas fa-eye"></i> View
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- AMENDMENT DETAILS MODAL -->
    <div class="modal-overlay" id="amendmentDetailsModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-file-medical-alt"></i> Amendment Details</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-info-circle"></i>
                        Amendment Information
                    </h3>
                    <div id="modalAmendmentInfo">
                        <!-- Amendment info will be loaded here -->
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-tasks"></i>
                        Quick Actions
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <button type="button" class="btn btn-primary" id="modalViewBtn">
                            <i class="fas fa-eye"></i> View Full
                        </button>
                        <button type="button" class="btn btn-success" id="modalUpdateBtn">
                            <i class="fas fa-sync-alt"></i> Update
                        </button>
                        <button type="button" class="btn btn-secondary" id="modalTrackBtn">
                            <i class="fas fa-map-marker-alt"></i> Track
                        </button>
                        <button type="button" class="btn btn-danger" id="modalWithdrawBtn">
                            <i class="fas fa-times"></i> Withdraw
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
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button type="button" class="btn btn-primary" id="modalManageBtn">
                        <i class="fas fa-cog"></i> Manage Amendment
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
                    <h3>Amendment Submission Module</h3>
                    <p>
                        Submit proposed changes to existing ordinances and resolutions. 
                        Track amendment progress, manage versions, and ensure legislative 
                        document integrity with comprehensive amendment management.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="amendments.php"><i class="fas fa-file-medical-alt"></i> Amendments Dashboard</a></li>
                    <li><a href="comparison.php"><i class="fas fa-code-branch"></i> Change Comparison</a></li>
                    <li><a href="version_storage.php"><i class="fas fa-box-archive"></i> Version Storage</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-gavel"></i> Legal Guidelines</a></li>
                    <li><a href="#"><i class="fas fa-video"></i> Amendment Tutorials</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Legislative Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback & Suggestions</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Amendment Submission Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All amendment activities are logged and audited.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Amendment Submission Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
            placeholder: 'Enter the proposed amended content here...'
        });

        // Sync Quill content with form textarea
        quill.on('text-change', function() {
            document.getElementById('proposed_changes').value = quill.root.innerHTML;
            updateProgress();
            analyzeChanges();
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
        function loadDocumentDetails(docId) {
            if (!docId) {
                document.getElementById('documentPreview').style.display = 'none';
                document.getElementById('document_type').value = '';
                document.getElementById('current_version_id').value = '';
                updateProgress();
                return;
            }
            
            const select = document.getElementById('document_select');
            const selectedOption = select.options[select.selectedIndex];
            const docType = selectedOption.getAttribute('data-type');
            
            document.getElementById('document_type').value = docType;
            
            // In a real application, you would fetch document details via AJAX
            // For now, we'll simulate with mock data
            simulateDocumentLoad(docId, docType);
        }
        
        function simulateDocumentLoad(docId, docType) {
            const preview = document.getElementById('documentPreview');
            preview.style.display = 'block';
            
            // Mock document content
            const mockContent = `
                <h4>Document Preview</h4>
                <div class="document-preview-content">
                    <p><strong>Document Type:</strong> ${docType === 'ordinance' ? 'Ordinance' : 'Resolution'}</p>
                    <p><strong>Document ID:</strong> ${docId}</p>
                    <p><strong>Current Status:</strong> Approved</p>
                    <p><strong>Last Updated:</strong> February 5, 2026</p>
                    <p><strong>Content Preview:</strong></p>
                    <div style="background: white; padding: 10px; border-radius: 5px; border: 1px solid #ddd; margin-top: 10px;">
                        <p>This is a sample document content. In a real application, this would show the actual document content with highlighted sections that are commonly amended.</p>
                        <p class="highlight">Section 3.2 - This section is frequently amended</p>
                        <p>Section 4.1 - Standard procedural clause</p>
                        <p class="highlight">Section 5.3 - Another commonly modified section</p>
                    </div>
                </div>
            `;
            
            preview.innerHTML = mockContent;
            document.getElementById('current_version_id').value = 'mock_version_' + docId;
            updateProgress();
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
            alert('Amendment draft saved successfully!');
        }
        
        function clearForm() {
            if (confirm('Are you sure you want to clear the form? All unsaved changes will be lost.')) {
                document.getElementById('amendmentSubmissionForm').reset();
                quill.setText('');
                uploadedFiles = [];
                fileList.innerHTML = '';
                document.getElementById('documentPreview').style.display = 'none';
                document.getElementById('document_type').value = '';
                document.getElementById('current_version_id').value = '';
                currentStep = 1;
                showStep(currentStep);
                resetAnalytics();
            }
        }
        
        function compareVersions() {
            // In a real application, this would open a comparison view
            const docId = document.getElementById('document_select').value;
            if (docId) {
                alert('Opening comparison view for document ' + docId);
            } else {
                alert('Please select a document first.');
            }
        }
        
        function analyzeChanges() {
            const content = quill.getText().trim();
            if (content.length > 100) {
                const changeSummary = document.getElementById('changeSummary');
                const wordCount = content.split(/\s+/).length;
                const charCount = content.length;
                
                // Mock analysis
                const analysis = `
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <strong>Word Count:</strong> ${wordCount}
                        </div>
                        <div>
                            <strong>Character Count:</strong> ${charCount}
                        </div>
                        <div>
                            <strong>Change Type:</strong> Moderate revision
                        </div>
                        <div>
                            <strong>Complexity:</strong> Medium
                        </div>
                        <div colspan="2" style="grid-column: span 2;">
                            <strong>Detected Changes:</strong>
                            <ul style="margin: 5px 0 0 15px;">
                                <li>Text modifications in multiple sections</li>
                                <li>Potential legal implications detected</li>
                                <li>Formatting changes applied</li>
                            </ul>
                        </div>
                    </div>
                `;
                
                changeSummary.innerHTML = analysis;
            }
        }
        
        // Form validation
        document.getElementById('amendmentSubmissionForm').addEventListener('submit', function(e) {
            const title = document.getElementById('title').value.trim();
            const description = document.getElementById('description').value.trim();
            const justification = document.getElementById('justification').value.trim();
            const content = quill.getText().trim();
            const docId = document.getElementById('document_select').value;
            
            if (!docId) {
                e.preventDefault();
                alert('Please select a document to amend.');
                return;
            }
            
            if (!title) {
                e.preventDefault();
                alert('Please enter an amendment title.');
                return;
            }
            
            if (!description) {
                e.preventDefault();
                alert('Please enter an amendment description.');
                return;
            }
            
            if (!justification) {
                e.preventDefault();
                alert('Please provide a justification for the amendment.');
                return;
            }
            
            if (!content) {
                e.preventDefault();
                alert('Please enter proposed amendment content.');
                return;
            }
            
            // Update content textarea with Quill HTML
            document.getElementById('proposed_changes').value = quill.root.innerHTML;
            
            // Show confirmation
            if (!confirm('Are you sure you want to submit this amendment for review?')) {
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
                    const docId = document.getElementById('document_select').value;
                    if (!docId) {
                        alert('Please select a document to amend.');
                        return false;
                    }
                    return true;
                    
                case 2:
                    const title = document.getElementById('title').value.trim();
                    const description = document.getElementById('description').value.trim();
                    const justification = document.getElementById('justification').value.trim();
                    
                    if (!title) {
                        alert('Please enter an amendment title.');
                        return false;
                    }
                    
                    if (!description) {
                        alert('Please enter an amendment description.');
                        return false;
                    }
                    
                    if (!justification) {
                        alert('Please provide a justification for the amendment.');
                        return false;
                    }
                    
                    return true;
                    
                case 3:
                    const content = quill.getText().trim();
                    
                    if (!content) {
                        alert('Please enter proposed amendment content.');
                        return false;
                    }
                    
                    return true;
                    
                case 4:
                    // Authors and files are optional
                    return true;
                    
                case 5:
                    const confirmCheckbox = document.getElementById('confirm_understanding');
                    if (!confirmCheckbox.checked) {
                        alert('Please confirm that you have reviewed all amendment details.');
                        return false;
                    }
                    return true;
                    
                default:
                    return true;
            }
        }
        
        function updateProgress() {
            let progress = 0;
            
            // Calculate progress based on form completion
            if (document.getElementById('document_select').value) progress += 20;
            
            const title = document.getElementById('title').value.trim();
            const description = document.getElementById('description').value.trim();
            const justification = document.getElementById('justification').value.trim();
            if (title) progress += 10;
            if (description) progress += 10;
            if (justification) progress += 10;
            
            const content = quill.getText().trim();
            if (content) progress += 20;
            
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
            
            // Update document selection display
            const docSelect = document.getElementById('document_select');
            const selectedDoc = docSelect.options[docSelect.selectedIndex];
            document.getElementById('docSelectedDisplay').textContent = selectedDoc.value ? selectedDoc.text.substring(0, 30) + '...' : 'None';
            
            // Update priority display
            const selectedPriority = document.querySelector('input[name="priority"]:checked');
            document.getElementById('priorityDisplay').textContent = selectedPriority ? selectedPriority.value.charAt(0).toUpperCase() + selectedPriority.value.slice(1) : 'Low';
            
            // Update authors count
            document.getElementById('authorsCount').textContent = authorsChecked;
            
            // Update AI analytics based on progress
            updateAnalytics(progress);
        }
        
        function updateReviewSummary() {
            const title = document.getElementById('title').value.trim();
            const description = document.getElementById('description').value.trim();
            const justification = document.getElementById('justification').value.trim();
            const content = quill.getText().trim();
            const docSelect = document.getElementById('document_select');
            const selectedDoc = docSelect.options[docSelect.selectedIndex];
            const selectedPriority = document.querySelector('input[name="priority"]:checked');
            const authors = document.querySelectorAll('input[name="authors[]"]:checked').length;
            const files = uploadedFiles.length;
            const committeeReview = document.getElementById('committee_review').checked;
            const publicHearing = document.getElementById('public_hearing').checked;
            
            let summaryHtml = `
                <div style="background: var(--off-white); padding: 20px; border-radius: var(--border-radius); border: 1px solid var(--gray-light);">
                    <h4 style="color: var(--qc-purple); margin-bottom: 15px;">Amendment Summary</h4>
                    
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 10px; margin-bottom: 15px;">
                        <div style="font-weight: 600;">Document:</div>
                        <div>${selectedDoc.value ? selectedDoc.text : 'Not selected'}</div>
                        
                        <div style="font-weight: 600;">Amendment Title:</div>
                        <div>${title || 'Not entered'}</div>
                        
                        <div style="font-weight: 600;">Description:</div>
                        <div>${description || 'Not entered'}</div>
                        
                        <div style="font-weight: 600;">Justification Length:</div>
                        <div>${justification.length} characters</div>
                        
                        <div style="font-weight: 600;">Content Length:</div>
                        <div>${content.length} characters</div>
                        
                        <div style="font-weight: 600;">Priority:</div>
                        <div>${selectedPriority ? selectedPriority.value.charAt(0).toUpperCase() + selectedPriority.value.slice(1) : 'Low'}</div>
                        
                        <div style="font-weight: 600;">Authors:</div>
                        <div>${authors} selected</div>
                        
                        <div style="font-weight: 600;">Files:</div>
                        <div>${files} uploaded</div>
                        
                        <div style="font-weight: 600;">Committee Review:</div>
                        <div>${committeeReview ? 'Required' : 'Not required'}</div>
                        
                        <div style="font-weight: 600;">Public Hearing:</div>
                        <div>${publicHearing ? 'Required' : 'Not required'}</div>
                    </div>
                    
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--gray-light);">
                        <p style="color: var(--qc-green); font-weight: 600;">
                            <i class="fas fa-check-circle"></i> Ready to submit for review!
                        </p>
                        <p style="color: var(--gray); font-size: 0.9rem;">
                            Click "Submit Amendment" to send this proposal to the legislative review process.
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
        document.getElementById('document_select').addEventListener('change', updateProgress);
        document.getElementById('title').addEventListener('input', updateProgress);
        document.getElementById('description').addEventListener('input', updateProgress);
        document.getElementById('justification').addEventListener('input', updateProgress);
        document.querySelectorAll('input[name="priority"]').forEach(input => {
            input.addEventListener('change', updateProgress);
        });
        document.querySelectorAll('input[name="authors[]"]').forEach(input => {
            input.addEventListener('change', updateProgress);
        });
        document.getElementById('committee_review').addEventListener('change', function() {
            document.getElementById('committeeSelection').style.display = this.checked ? 'block' : 'none';
            updateProgress();
        });
        document.getElementById('public_hearing').addEventListener('change', function() {
            document.getElementById('hearingDateSelection').style.display = this.checked ? 'block' : 'none';
            updateProgress();
        });
        
        // Initialize first step
        showStep(currentStep);
        
        // AI ANALYTICS FUNCTIONS
        function updateAnalytics(progress) {
            // Update AI analytics based on form progress
            if (progress >= 50) {
                // Simulate AI analysis based on form content
                const title = document.getElementById('title').value.trim();
                const content = quill.getText().trim();
                
                if (title && content) {
                    // Mock AI analysis results
                    const successProb = 75 + Math.floor(Math.random() * 15); // 75-90%
                    const reviewDays = 7 + Math.floor(Math.random() * 8); // 7-14 days
                    const legalCompliance = Math.random() > 0.3 ? 'High' : 'Medium';
                    const publicSupport = 65 + Math.floor(Math.random() * 25); // 65-90%
                    
                    document.getElementById('aiSuccessProbability').textContent = successProb + '%';
                    document.getElementById('aiSuccessProbability').parentElement.querySelector('.analytic-progress-bar').style.width = successProb + '%';
                    
                    document.getElementById('aiReviewTime').textContent = reviewDays + '-14 days';
                    document.getElementById('aiReviewTime').parentElement.querySelector('.analytic-progress-bar').style.width = Math.min(reviewDays * 7, 100) + '%';
                    
                    document.getElementById('aiLegalCompliance').textContent = legalCompliance;
                    document.getElementById('aiLegalCompliance').parentElement.querySelector('.analytic-progress-bar').style.width = legalCompliance === 'High' ? '90%' : '70%';
                    
                    document.getElementById('aiPublicSupport').textContent = publicSupport + '%';
                    document.getElementById('aiPublicSupport').parentElement.querySelector('.analytic-progress-bar').style.width = publicSupport + '%';
                }
            }
        }
        
        function resetAnalytics() {
            // Reset AI analytics to default values
            document.getElementById('aiSuccessProbability').textContent = '85%';
            document.getElementById('aiSuccessProbability').parentElement.querySelector('.analytic-progress-bar').style.width = '85%';
            
            document.getElementById('aiReviewTime').textContent = '7-10 days';
            document.getElementById('aiReviewTime').parentElement.querySelector('.analytic-progress-bar').style.width = '70%';
            
            document.getElementById('aiLegalCompliance').textContent = 'High';
            document.getElementById('aiLegalCompliance').parentElement.querySelector('.analytic-progress-bar').style.width = '90%';
            
            document.getElementById('aiPublicSupport').textContent = '72%';
            document.getElementById('aiPublicSupport').parentElement.querySelector('.analytic-progress-bar').style.width = '72%';
        }
        
        // AMENDMENT DETAILS MODAL FUNCTIONALITY
        const amendmentDetailsModal = document.getElementById('amendmentDetailsModal');
        const modalClose = document.getElementById('modalClose');
        const modalCancelBtn = document.getElementById('modalCancelBtn');
        let currentAmendmentId = null;
        
        // Open modal when view button is clicked
        document.querySelectorAll('.edit-amendment-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                currentAmendmentId = this.getAttribute('data-amendment-id');
                const amendmentNumber = this.getAttribute('data-amendment-number');
                const amendmentTitle = this.getAttribute('data-amendment-title');
                const amendmentStatus = this.getAttribute('data-amendment-status');
                
                // Load amendment info into modal
                document.getElementById('modalAmendmentInfo').innerHTML = `
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 10px; margin-bottom: 15px;">
                        <div style="font-weight: 600;">Amendment Number:</div>
                        <div>${amendmentNumber}</div>
                        
                        <div style="font-weight: 600;">Title:</div>
                        <div>${amendmentTitle}</div>
                        
                        <div style="font-weight: 600;">Status:</div>
                        <div>
                            <span class="amendment-status status-${amendmentStatus}">
                                ${amendmentStatus.charAt(0).toUpperCase() + amendmentStatus.slice(1)}
                            </span>
                        </div>
                        
                        <div style="font-weight: 600;">Submitted:</div>
                        <div>Just now</div>
                        
                        <div style="font-weight: 600;">Current Stage:</div>
                        <div>Initial submission</div>
                        
                        <div style="font-weight: 600;">Review Progress:</div>
                        <div>
                            <div style="height: 6px; background: var(--gray-light); border-radius: 3px; overflow: hidden; margin-top: 5px;">
                                <div style="height: 100%; width: 25%; background: var(--qc-purple);"></div>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--gray); margin-top: 3px;">25% complete</div>
                        </div>
                    </div>
                `;
                
                // Load activity log (simulated)
                document.getElementById('modalActivityLog').innerHTML = `
                    <div style="font-size: 0.9rem;">
                        <div style="display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid var(--gray-light);">
                            <i class="fas fa-upload" style="color: var(--qc-purple);"></i>
                            <div>
                                <div>Amendment submitted for review</div>
                                <div style="color: var(--gray); font-size: 0.85rem;">Just now</div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid var(--gray-light);">
                            <i class="fas fa-user-check" style="color: var(--qc-gold);"></i>
                            <div>
                                <div>Assigned to initial review queue</div>
                                <div style="color: var(--gray); font-size: 0.85rem;">A few moments ago</div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; padding: 10px;">
                            <i class="fas fa-clock" style="color: var(--qc-blue);"></i>
                            <div>
                                <div>Awaiting committee assignment</div>
                                <div style="color: var(--gray); font-size: 0.85rem;">Pending</div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Open modal
                amendmentDetailsModal.classList.add('active');
            });
        });
        
        // Close modal
        modalClose.addEventListener('click', closeModal);
        modalCancelBtn.addEventListener('click', closeModal);
        amendmentDetailsModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        function closeModal() {
            amendmentDetailsModal.classList.remove('active');
        }
        
        // Modal action buttons
        document.getElementById('modalViewBtn').addEventListener('click', function() {
            if (currentAmendmentId) {
                window.location.href = `amendment_details.php?id=${currentAmendmentId}`;
            }
        });
        
        document.getElementById('modalUpdateBtn').addEventListener('click', function() {
            alert('Update feature coming soon!');
        });
        
        document.getElementById('modalTrackBtn').addEventListener('click', function() {
            alert('Tracking feature coming soon!');
        });
        
        document.getElementById('modalWithdrawBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to withdraw this amendment? This action may require approval.')) {
                alert('Amendment withdrawal request submitted!');
                closeModal();
                // In a real application, you would redirect or reload the page
                window.location.reload();
            }
        });
        
        document.getElementById('modalManageBtn').addEventListener('click', function() {
            if (currentAmendmentId) {
                window.location.href = `amendment_manage.php?id=${currentAmendmentId}`;
            }
        });
        
        // Auto-save draft every 30 seconds
        let autoSaveTimer;
        function startAutoSave() {
            autoSaveTimer = setInterval(() => {
                const title = document.getElementById('title').value.trim();
                const description = document.getElementById('description').value.trim();
                
                if (title || description || quill.getText().trim()) {
                    console.log('Auto-saving amendment draft...');
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
                e.returnValue = 'You have unsaved amendment changes. Are you sure you want to leave?';
            }
        });
        
        // Add animation to form elements
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
            
            // Observe form sections
            document.querySelectorAll('.form-section, .recent-amendments').forEach(section => {
                observer.observe(section);
            });
        });
    </script>
</body>
</html>