<?php
// timeline.php - Timeline Tracking Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to view timeline
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

// Get filter parameters
$document_type = $_GET['document_type'] ?? 'all';
$status = $_GET['status'] ?? 'all';
$priority = $_GET['priority'] ?? 'all';
$assigned_to = $_GET['assigned_to'] ?? 'all';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search = $_GET['search'] ?? '';

// Build query for fetching timelines
$query = "
    SELECT DISTINCT 
        d.id as document_id,
        d.document_type,
        CASE 
            WHEN d.document_type = 'ordinance' THEN o.ordinance_number
            WHEN d.document_type = 'resolution' THEN r.resolution_number
        END as document_number,
        CASE 
            WHEN d.document_type = 'ordinance' THEN o.title
            WHEN d.document_type = 'resolution' THEN r.title
        END as document_title,
        d.status as overall_status,
        d.priority_level as overall_priority,
        COUNT(t.id) as total_milestones,
        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_milestones,
        SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_milestones,
        SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) as pending_milestones,
        SUM(CASE WHEN t.status = 'delayed' THEN 1 ELSE 0 END) as delayed_milestones,
        MAX(t.due_date) as last_due_date,
        MIN(CASE WHEN t.status != 'completed' THEN t.due_date END) as next_due_date
    FROM document_classification d
    LEFT JOIN ordinances o ON d.document_id = o.id AND d.document_type = 'ordinance'
    LEFT JOIN resolutions r ON d.document_id = r.id AND d.document_type = 'resolution'
    LEFT JOIN timeline_tracking t ON d.document_id = t.document_id AND d.document_type = t.document_type
    WHERE 1=1
";

$params = [];

if ($document_type !== 'all') {
    $query .= " AND d.document_type = :document_type";
    $params[':document_type'] = $document_type;
}

if ($status !== 'all') {
    $query .= " AND d.status = :status";
    $params[':status'] = $status;
}

if ($priority !== 'all') {
    $query .= " AND d.priority_level = :priority";
    $params[':priority'] = $priority;
}

if ($search) {
    $query .= " AND (o.title LIKE :search OR r.title LIKE :search OR o.ordinance_number LIKE :search OR r.resolution_number LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " GROUP BY d.id, d.document_type, d.status, d.priority_level";

// Get assigned documents if user is councilor
if ($user_role === 'councilor') {
    $assigned_query = "
        SELECT document_id, document_type 
        FROM document_authors 
        WHERE user_id = :user_id
        UNION
        SELECT document_id, document_type 
        FROM document_committees dc
        JOIN committee_members cm ON dc.committee_id = cm.committee_id
        WHERE cm.user_id = :user_id
    ";
    $assigned_stmt = $conn->prepare($assigned_query);
    $assigned_stmt->bindParam(':user_id', $user_id);
    $assigned_stmt->execute();
    $assigned_docs = $assigned_stmt->fetchAll();
}

// Get timeline data for a specific document if requested
$document_id = $_GET['document_id'] ?? 0;
$doc_type = $_GET['doc_type'] ?? '';
$timeline_data = [];
$document_info = null;

if ($document_id && $doc_type) {
    // Get document info
    if ($doc_type === 'ordinance') {
        $doc_query = "SELECT * FROM ordinances WHERE id = :id";
    } else {
        $doc_query = "SELECT * FROM resolutions WHERE id = :id";
    }
    
    $doc_stmt = $conn->prepare($doc_query);
    $doc_stmt->bindParam(':id', $document_id);
    $doc_stmt->execute();
    $document_info = $doc_stmt->fetch();
    
    // Get timeline milestones
    $timeline_query = "
        SELECT t.*, 
               u.first_name, 
               u.last_name, 
               u.role,
               COUNT(c.id) as comment_count,
               (SELECT COUNT(*) FROM timeline_tracking WHERE dependency_id = t.id) as dependents_count
        FROM timeline_tracking t
        LEFT JOIN users u ON t.assigned_to = u.id
        LEFT JOIN timeline_comments c ON t.id = c.timeline_id
        WHERE t.document_id = :document_id AND t.document_type = :doc_type
        GROUP BY t.id
        ORDER BY t.due_date ASC, t.priority DESC
    ";
    
    $timeline_stmt = $conn->prepare($timeline_query);
    $timeline_stmt->bindParam(':document_id', $document_id);
    $timeline_stmt->bindParam(':doc_type', $doc_type);
    $timeline_stmt->execute();
    $timeline_data = $timeline_stmt->fetchAll();
    
    // Get comments for each milestone
    foreach ($timeline_data as &$milestone) {
        $comments_query = "
            SELECT c.*, u.first_name, u.last_name, u.role
            FROM timeline_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.timeline_id = :timeline_id
            ORDER BY c.created_at DESC
        ";
        $comments_stmt = $conn->prepare($comments_query);
        $comments_stmt->bindParam(':timeline_id', $milestone['id']);
        $comments_stmt->execute();
        $milestone['comments'] = $comments_stmt->fetchAll();
    }
}

// Get available users for assignment
$users_query = "SELECT id, first_name, last_name, role, department FROM users WHERE is_active = 1 ORDER BY last_name, first_name";
$users_stmt = $conn->query($users_query);
$available_users = $users_stmt->fetchAll();

// Get user's notifications
$notifications_query = "
    SELECT n.*, t.milestone, t.document_id, t.document_type
    FROM timeline_notifications n
    LEFT JOIN timeline_tracking t ON n.timeline_id = t.id
    WHERE n.user_id = :user_id AND n.is_read = 0
    ORDER BY n.created_at DESC
    LIMIT 10
";
$notifications_stmt = $conn->prepare($notifications_query);
$notifications_stmt->bindParam(':user_id', $user_id);
$notifications_stmt->execute();
$notifications = $notifications_stmt->fetchAll();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        if ($action === 'add_milestone') {
            $milestone = $_POST['milestone'];
            $description = $_POST['description'];
            $assigned_to = $_POST['assigned_to'];
            $start_date = $_POST['start_date'];
            $due_date = $_POST['due_date'];
            $priority = $_POST['priority'];
            $dependency_id = $_POST['dependency_id'] ?: null;
            $notes = $_POST['notes'];
            
            $insert_query = "
                INSERT INTO timeline_tracking 
                (document_id, document_type, milestone, description, assigned_to, start_date, due_date, priority, dependency_id, notes, created_by)
                VALUES (:doc_id, :doc_type, :milestone, :description, :assigned_to, :start_date, :due_date, :priority, :dependency_id, :notes, :created_by)
            ";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->bindParam(':doc_id', $document_id);
            $stmt->bindParam(':doc_type', $doc_type);
            $stmt->bindParam(':milestone', $milestone);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':assigned_to', $assigned_to);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':due_date', $due_date);
            $stmt->bindParam(':priority', $priority);
            $stmt->bindParam(':dependency_id', $dependency_id);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':created_by', $user_id);
            $stmt->execute();
            
            $timeline_id = $conn->lastInsertId();
            
            // Create notification for assigned user
            $notification_query = "
                INSERT INTO timeline_notifications (timeline_id, user_id, notification_type, message)
                VALUES (:timeline_id, :user_id, 'assignment', :message)
            ";
            $stmt = $conn->prepare($notification_query);
            $stmt->bindParam(':timeline_id', $timeline_id);
            $stmt->bindParam(':user_id', $assigned_to);
            $message = "You have been assigned to milestone: $milestone";
            $stmt->bindParam(':message', $message);
            $stmt->execute();
            
            $success_message = "Milestone added successfully!";
            
        } elseif ($action === 'update_status') {
            $timeline_id = $_POST['timeline_id'];
            $status = $_POST['status'];
            $notes = $_POST['notes'] ?? '';
            
            $update_query = "
                UPDATE timeline_tracking 
                SET status = :status, 
                    completed_date = CASE WHEN :status = 'completed' THEN CURDATE() ELSE completed_date END,
                    actual_duration = CASE WHEN :status = 'completed' AND start_date IS NOT NULL 
                                          THEN DATEDIFF(CURDATE(), start_date) 
                                          ELSE actual_duration END,
                    notes = CONCAT(COALESCE(notes, ''), '\n', :notes),
                    updated_at = NOW()
                WHERE id = :timeline_id
            ";
            
            $stmt = $conn->prepare($update_query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':timeline_id', $timeline_id);
            $stmt->execute();
            
            // Get milestone info for notification
            $milestone_query = "SELECT milestone, assigned_to, document_id, document_type FROM timeline_tracking WHERE id = :timeline_id";
            $milestone_stmt = $conn->prepare($milestone_query);
            $milestone_stmt->bindParam(':timeline_id', $timeline_id);
            $milestone_stmt->execute();
            $milestone = $milestone_stmt->fetch();
            
            // Create notification
            $notification_query = "
                INSERT INTO timeline_notifications (timeline_id, user_id, notification_type, message)
                VALUES (:timeline_id, :user_id, 'status_update', :message)
            ";
            $stmt = $conn->prepare($notification_query);
            $stmt->bindParam(':timeline_id', $timeline_id);
            $stmt->bindParam(':user_id', $milestone['assigned_to']);
            $message = "Milestone '{$milestone['milestone']}' status updated to: $status";
            $stmt->bindParam(':message', $message);
            $stmt->execute();
            
            $success_message = "Status updated successfully!";
            
        } elseif ($action === 'add_comment') {
            $timeline_id = $_POST['timeline_id'];
            $comment = $_POST['comment'];
            
            $insert_query = "
                INSERT INTO timeline_comments (timeline_id, user_id, comment)
                VALUES (:timeline_id, :user_id, :comment)
            ";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->bindParam(':timeline_id', $timeline_id);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':comment', $comment);
            $stmt->execute();
            
            $success_message = "Comment added successfully!";
        }
        
        $conn->commit();
        
        // Refresh the page
        if ($document_id && $doc_type) {
            header("Location: timeline.php?document_id=$document_id&doc_type=$doc_type&success=" . urlencode($success_message));
        } else {
            header("Location: timeline.php?success=" . urlencode($success_message));
        }
        exit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

// Mark notifications as read
if (isset($_GET['mark_read']) && $_GET['mark_read'] === 'all') {
    $update_query = "UPDATE timeline_notifications SET is_read = 1, read_at = NOW() WHERE user_id = :user_id";
    $stmt = $conn->prepare($update_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
}

// Get statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT CONCAT(document_id, document_type)) as total_documents,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_milestones,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_milestones,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_milestones,
        COUNT(CASE WHEN status = 'delayed' THEN 1 END) as delayed_milestones,
        COUNT(CASE WHEN due_date < CURDATE() AND status NOT IN ('completed', 'cancelled') THEN 1 END) as overdue_milestones
    FROM timeline_tracking
";
$stats_stmt = $conn->query($stats_query);
$stats = $stats_stmt->fetch();

// Get recent activities
$activities_query = "
    SELECT 
        t.*,
        u.first_name,
        u.last_name,
        CASE 
            WHEN t.document_type = 'ordinance' THEN o.title
            WHEN t.document_type = 'resolution' THEN r.title
        END as document_title,
        CASE 
            WHEN t.document_type = 'ordinance' THEN o.ordinance_number
            WHEN t.document_type = 'resolution' THEN r.resolution_number
        END as document_number
    FROM timeline_tracking t
    LEFT JOIN users u ON t.assigned_to = u.id
    LEFT JOIN ordinances o ON t.document_id = o.id AND t.document_type = 'ordinance'
    LEFT JOIN resolutions r ON t.document_id = r.id AND t.document_type = 'resolution'
    WHERE t.due_date >= CURDATE() AND t.status NOT IN ('completed', 'cancelled')
    ORDER BY t.due_date ASC
    LIMIT 10
";
$activities_stmt = $conn->query($activities_query);
$recent_activities = $activities_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timeline Tracking | QC Ordinance Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.css">
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
            --yellow: #D97706;
            --orange: #F59E0B;
            --purple: #8B5CF6;
            --cyan: #06B6D4;
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
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid var(--orange);
            color: #92400e;
        }
        
        .alert-icon {
            font-size: 1.5rem;
        }
        
        /* FILTER SECTION */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 30px;
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--qc-blue);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .filter-group {
            margin-bottom: 0;
        }
        
        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.95rem;
        }
        
        .filter-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-family: 'Times New Roman', Times, serif;
            transition: all 0.3s ease;
            background: var(--white);
        }
        
        .filter-control:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        /* ACTION BUTTONS */
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
        
        .btn-warning {
            background: linear-gradient(135deg, var(--orange) 0%, #D97706 100%);
            color: var(--white);
        }
        
        .btn-warning:hover {
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
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 0.9rem;
        }
        
        /* DASHBOARD CARDS */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .dashboard-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--white);
        }
        
        .icon-blue { background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%); }
        .icon-green { background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%); }
        .icon-orange { background: linear-gradient(135deg, var(--orange) 0%, #D97706 100%); }
        .icon-red { background: linear-gradient(135deg, var(--red) 0%, #9b2c2c 100%); }
        .icon-purple { background: linear-gradient(135deg, var(--purple) 0%, #7C3AED 100%); }
        .icon-cyan { background: linear-gradient(135deg, var(--cyan) 0%, #0891B2 100%); }
        
        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--qc-blue);
            margin-bottom: 5px;
        }
        
        .card-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--gray-dark);
            line-height: 1;
        }
        
        .card-subtext {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 5px;
        }
        
        .card-trend {
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .trend-up { color: var(--qc-green); }
        .trend-down { color: var(--red); }
        
        /* TIMELINE CONTAINER */
        .timeline-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 30px;
        }
        
        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .timeline-title {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--qc-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .document-info {
            background: var(--off-white);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            border: 1px solid var(--gray-light);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            margin-bottom: 10px;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.9rem;
            margin-bottom: 3px;
        }
        
        .info-value {
            color: var(--qc-blue);
            font-size: 1rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-pending { background: #FEF3C7; color: #92400E; }
        .status-in_progress { background: #DBEAFE; color: #1E40AF; }
        .status-completed { background: #D1FAE5; color: #065F46; }
        .status-delayed { background: #FEE2E2; color: #991B1B; }
        .status-cancelled { background: #F3F4F6; color: #374151; }
        
        .priority-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .priority-low { background: #D1FAE5; color: #065F46; }
        .priority-medium { background: #FEF3C7; color: #92400E; }
        .priority-high { background: #FDE68A; color: #92400E; }
        .priority-urgent { background: #FED7D7; color: #991B1B; }
        .priority-emergency { background: #FECACA; color: #7F1D1D; }
        
        /* MILESTONE TIMELINE */
        .milestone-timeline {
            position: relative;
            padding: 20px 0;
        }
        
        .milestone-timeline::before {
            content: '';
            position: absolute;
            left: 30px;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--gray-light);
        }
        
        .milestone-item {
            position: relative;
            padding-left: 80px;
            margin-bottom: 30px;
        }
        
        .milestone-item:last-child {
            margin-bottom: 0;
        }
        
        .milestone-icon {
            position: absolute;
            left: 20px;
            top: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--white);
            z-index: 2;
            border: 3px solid var(--white);
        }
        
        .milestone-content {
            background: var(--off-white);
            border-radius: var(--border-radius);
            padding: 20px;
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .milestone-content:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--qc-blue);
        }
        
        .milestone-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .milestone-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 5px;
        }
        
        .milestone-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 10px;
        }
        
        .milestone-dates {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .date-item {
            display: flex;
            flex-direction: column;
        }
        
        .date-label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 3px;
        }
        
        .date-value {
            font-weight: 600;
            color: var(--gray-dark);
        }
        
        .milestone-progress {
            margin-bottom: 15px;
        }
        
        .progress-bar {
            height: 8px;
            background: var(--gray-light);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .progress-completed { background: var(--qc-green); }
        .progress-in_progress { background: var(--qc-blue); }
        .progress-pending { background: var(--orange); }
        .progress-delayed { background: var(--red); }
        
        .milestone-assigned {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .assigned-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
        }
        
        .assigned-info {
            flex: 1;
        }
        
        .assigned-name {
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.9rem;
        }
        
        .assigned-role {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .milestone-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* COMMENTS SECTION */
        .comments-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }
        
        .comments-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        
        .comment-item {
            padding: 15px;
            background: var(--white);
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            margin-bottom: 10px;
        }
        
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .comment-author {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .comment-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.8rem;
        }
        
        .comment-info h4 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 2px;
        }
        
        .comment-info span {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .comment-time {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .comment-text {
            color: var(--gray-dark);
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .comment-form {
            display: flex;
            gap: 10px;
        }
        
        .comment-input {
            flex: 1;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-family: 'Times New Roman', Times, serif;
            resize: vertical;
            min-height: 60px;
        }
        
        /* NOTIFICATIONS PANEL */
        .notifications-panel {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 30px;
        }
        
        .notifications-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .notifications-list {
            list-style: none;
        }
        
        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .notification-item:hover {
            background: var(--off-white);
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--white);
        }
        
        .icon-assignment { background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%); }
        .icon-status_update { background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%); }
        .icon-comment { background: linear-gradient(135deg, var(--purple) 0%, #7C3AED 100%); }
        .icon-overdue { background: linear-gradient(135deg, var(--red) 0%, #9b2c2c 100%); }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-message {
            font-size: 0.95rem;
            color: var(--gray-dark);
            margin-bottom: 5px;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .notification-unread {
            position: relative;
        }
        
        .notification-unread::before {
            content: '';
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            width: 10px;
            height: 10px;
            background: var(--qc-gold);
            border-radius: 50%;
        }
        
        /* RECENT ACTIVITIES */
        .activities-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .activities-list {
            list-style: none;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--white);
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 5px;
            font-size: 0.95rem;
        }
        
        .activity-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .activity-due {
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
        }
        
        .due-soon { color: var(--orange); }
        .due-today { color: var(--red); }
        .due-future { color: var(--qc-green); }
        
        /* FORM MODAL */
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
        
        /* FORM GROUPS */
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
            min-height: 100px;
            resize: vertical;
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
        
        /* MOBILE RESPONSIVENESS */
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
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .milestone-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .milestone-actions {
                width: 100%;
            }
            
            .comment-form {
                flex-direction: column;
            }
            
            .modal-actions {
                flex-direction: column;
            }
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
        
        /* LOADING SPINNER */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 40px;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--gray-light);
            border-top: 4px solid var(--qc-blue);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                    <p>Timeline Tracking Module | Status Tracking & Monitoring</p>
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
                        <i class="fas fa-chart-line"></i> Status Tracking Module
                    </h3>
                    <p class="sidebar-subtitle">Timeline Tracking Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="timeline-tracking-container">
                <!-- FIXED MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">TIMELINE TRACKING MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-stream"></i>
                            </div>
                            <div class="module-title">
                                <h1>Document Timeline Tracking</h1>
                                <p class="module-subtitle">
                                    Track the progress of ordinances and resolutions through their legislative journey. 
                                    Monitor milestones, deadlines, and dependencies in real-time.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats['total_documents'] ?? 0; ?></h3>
                                    <p>Active Documents</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-flag"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats['pending_milestones'] ?? 0; ?></h3>
                                    <p>Pending Milestones</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats['overdue_milestones'] ?? 0; ?></h3>
                                    <p>Overdue Items</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success fade-in">
                    <i class="fas fa-check-circle alert-icon"></i>
                    <div><?php echo htmlspecialchars($_GET['success']); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                <div class="alert alert-error fade-in">
                    <i class="fas fa-exclamation-circle alert-icon"></i>
                    <div><?php echo htmlspecialchars($error_message); ?></div>
                </div>
                <?php endif; ?>

                <!-- NOTIFICATIONS PANEL -->
                <?php if (!empty($notifications)): ?>
                <div class="notifications-panel fade-in">
                    <div class="notifications-header">
                        <h3 class="filter-title">
                            <i class="fas fa-bell"></i> Recent Notifications
                        </h3>
                        <a href="?mark_read=all" class="btn btn-sm btn-secondary">Mark All as Read</a>
                    </div>
                    
                    <ul class="notifications-list">
                        <?php foreach ($notifications as $notification): 
                            $icon_class = 'icon-' . $notification['notification_type'];
                            $icon = match($notification['notification_type']) {
                                'assignment' => 'fa-user-check',
                                'status_update' => 'fa-sync-alt',
                                'comment' => 'fa-comment',
                                'overdue' => 'fa-exclamation-triangle',
                                default => 'fa-bell'
                            };
                        ?>
                        <li class="notification-item notification-unread">
                            <div class="notification-icon <?php echo $icon_class; ?>">
                                <i class="fas <?php echo $icon; ?>"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-message">
                                    <?php echo htmlspecialchars($notification['message']); ?>
                                </div>
                                <div class="notification-time">
                                    <?php echo date('M d, Y H:i', strtotime($notification['created_at'])); ?>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- DASHBOARD CARDS -->
                <div class="dashboard-cards fade-in">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-icon icon-blue">
                                <i class="fas fa-tasks"></i>
                            </div>
                            <div>
                                <div class="card-title">Total Milestones</div>
                                <div class="card-value">
                                    <?php echo ($stats['pending_milestones'] + $stats['in_progress_milestones'] + $stats['completed_milestones'] + $stats['delayed_milestones']) ?? 0; ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-subtext">Across all documents</div>
                        <div class="card-trend trend-up">
                            <i class="fas fa-chart-line"></i>
                            Active tracking
                        </div>
                    </div>
                    
                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-icon icon-green">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div>
                                <div class="card-title">Completed</div>
                                <div class="card-value"><?php echo $stats['completed_milestones'] ?? 0; ?></div>
                            </div>
                        </div>
                        <div class="card-subtext">Milestones completed</div>
                        <div class="card-trend trend-up">
                            <i class="fas fa-arrow-up"></i>
                            On track
                        </div>
                    </div>
                    
                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-icon icon-orange">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <div class="card-title">In Progress</div>
                                <div class="card-value"><?php echo $stats['in_progress_milestones'] ?? 0; ?></div>
                            </div>
                        </div>
                        <div class="card-subtext">Currently active</div>
                        <div class="card-trend trend-up">
                            <i class="fas fa-spinner"></i>
                            Being worked on
                        </div>
                    </div>
                    
                    <div class="dashboard-card">
                        <div class="card-header">
                            <div class="card-icon icon-red">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div>
                                <div class="card-title">Overdue</div>
                                <div class="card-value"><?php echo $stats['overdue_milestones'] ?? 0; ?></div>
                            </div>
                        </div>
                        <div class="card-subtext">Past due date</div>
                        <div class="card-trend trend-down">
                            <i class="fas fa-arrow-down"></i>
                            Needs attention
                        </div>
                    </div>
                </div>

                <!-- FILTER SECTION -->
                <div class="filter-section fade-in">
                    <form method="GET" action="" id="timelineFilter">
                        <div class="filter-header">
                            <h3 class="filter-title">
                                <i class="fas fa-filter"></i> Filter Documents
                            </h3>
                            <?php if ($document_id): ?>
                            <a href="timeline.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear Document
                            </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="filter-form">
                            <div class="filter-group">
                                <label class="filter-label">Document Type</label>
                                <select name="document_type" class="filter-control" onchange="this.form.submit()">
                                    <option value="all" <?php echo $document_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="ordinance" <?php echo $document_type === 'ordinance' ? 'selected' : ''; ?>>Ordinance</option>
                                    <option value="resolution" <?php echo $document_type === 'resolution' ? 'selected' : ''; ?>>Resolution</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select name="status" class="filter-control" onchange="this.form.submit()">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="classified" <?php echo $status === 'classified' ? 'selected' : ''; ?>>Classified</option>
                                    <option value="reviewed" <?php echo $status === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Priority</label>
                                <select name="priority" class="filter-control" onchange="this.form.submit()">
                                    <option value="all" <?php echo $priority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                    <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                                    <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                    <option value="emergency" <?php echo $priority === 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Search</label>
                                <input type="text" name="search" class="filter-control" placeholder="Document title or number..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                                <a href="timeline.php" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if ($document_id && $document_info): ?>
                <!-- DOCUMENT TIMELINE VIEW -->
                <div class="timeline-container fade-in">
                    <div class="timeline-header">
                        <h2 class="timeline-title">
                            <i class="fas fa-stream"></i>
                            Timeline for: <?php echo htmlspecialchars($document_info['title'] ?? $document_info['ordinance_number'] ?? $document_info['resolution_number']); ?>
                        </h2>
                        <div>
                            <button class="btn btn-success" onclick="openAddMilestoneModal()">
                                <i class="fas fa-plus"></i> Add Milestone
                            </button>
                            <button class="btn btn-primary" onclick="generateTimelineReport()">
                                <i class="fas fa-file-export"></i> Export Report
                            </button>
                        </div>
                    </div>
                    
                    <!-- Document Information -->
                    <div class="document-info">
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Document Number</div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars($document_info['ordinance_number'] ?? $document_info['resolution_number']); ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Title</div>
                                <div class="info-value"><?php echo htmlspecialchars($document_info['title']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Type</div>
                                <div class="info-value"><?php echo ucfirst($doc_type); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Overall Status</div>
                                <div class="info-value">
                                    <span class="status-badge status-<?php echo $document_info['status']; ?>">
                                        <?php echo ucfirst($document_info['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Created</div>
                                <div class="info-value"><?php echo date('M d, Y', strtotime($document_info['created_at'])); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Created By</div>
                                <div class="info-value">
                                    <?php 
                                    $creator_query = "SELECT first_name, last_name FROM users WHERE id = :id";
                                    $creator_stmt = $conn->prepare($creator_query);
                                    $creator_stmt->bindParam(':id', $document_info['created_by']);
                                    $creator_stmt->execute();
                                    $creator = $creator_stmt->fetch();
                                    echo $creator ? htmlspecialchars($creator['first_name'] . ' ' . $creator['last_name']) : 'Unknown';
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Milestone Timeline -->
                    <div class="milestone-timeline">
                        <?php if (empty($timeline_data)): ?>
                        <div class="no-timeline" style="text-align: center; padding: 40px; color: var(--gray);">
                            <i class="fas fa-stream" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                            <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Timeline Created</h3>
                            <p>This document doesn't have a timeline yet. Click "Add Milestone" to create the first milestone.</p>
                            <button class="btn btn-primary" onclick="openAddMilestoneModal()" style="margin-top: 15px;">
                                <i class="fas fa-plus"></i> Create First Milestone
                            </button>
                        </div>
                        <?php else: ?>
                        <?php foreach ($timeline_data as $milestone): 
                            $icon_class = match($milestone['status']) {
                                'completed' => 'icon-green',
                                'in_progress' => 'icon-blue',
                                'pending' => 'icon-orange',
                                'delayed' => 'icon-red',
                                'cancelled' => 'icon-gray',
                                default => 'icon-gray'
                            };
                            
                            $icon = match($milestone['status']) {
                                'completed' => 'fa-check-circle',
                                'in_progress' => 'fa-spinner',
                                'pending' => 'fa-clock',
                                'delayed' => 'fa-exclamation-triangle',
                                'cancelled' => 'fa-times-circle',
                                default => 'fa-circle'
                            };
                            
                            $progress_class = 'progress-' . $milestone['status'];
                        ?>
                        <div class="milestone-item" id="milestone-<?php echo $milestone['id']; ?>">
                            <div class="milestone-icon <?php echo $icon_class; ?>">
                                <i class="fas <?php echo $icon; ?>"></i>
                            </div>
                            
                            <div class="milestone-content">
                                <div class="milestone-header">
                                    <div>
                                        <div class="milestone-title"><?php echo htmlspecialchars($milestone['milestone']); ?></div>
                                        <div class="milestone-meta">
                                            <span class="status-badge status-<?php echo $milestone['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $milestone['status'])); ?>
                                            </span>
                                            <span class="priority-badge priority-<?php echo $milestone['priority']; ?>">
                                                <?php echo ucfirst($milestone['priority']); ?> Priority
                                            </span>
                                            <?php if ($milestone['dependency_id']): ?>
                                            <span class="badge" style="background: #E0E7FF; color: #3730A3;">
                                                <i class="fas fa-link"></i> Dependent
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($milestone['dependents_count'] > 0): ?>
                                            <span class="badge" style="background: #FCE7F3; color: #831843;">
                                                <i class="fas fa-sitemap"></i> <?php echo $milestone['dependents_count']; ?> Dependents
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="milestone-actions">
                                        <button class="btn btn-sm btn-secondary" onclick="openStatusModal(<?php echo $milestone['id']; ?>)">
                                            <i class="fas fa-sync-alt"></i> Update Status
                                        </button>
                                        <button class="btn btn-sm btn-primary" onclick="openCommentModal(<?php echo $milestone['id']; ?>)">
                                            <i class="fas fa-comment"></i> Comment
                                        </button>
                                    </div>
                                </div>
                                
                                <?php if ($milestone['description']): ?>
                                <div style="margin-bottom: 15px; color: var(--gray-dark); font-size: 0.95rem;">
                                    <?php echo nl2br(htmlspecialchars($milestone['description'])); ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="milestone-dates">
                                    <div class="date-item">
                                        <div class="date-label">Start Date</div>
                                        <div class="date-value">
                                            <?php echo $milestone['start_date'] ? date('M d, Y', strtotime($milestone['start_date'])) : 'Not set'; ?>
                                        </div>
                                    </div>
                                    <div class="date-item">
                                        <div class="date-label">Due Date</div>
                                        <div class="date-value <?php echo (strtotime($milestone['due_date']) < time() && !in_array($milestone['status'], ['completed', 'cancelled'])) ? 'due-today' : ''; ?>">
                                            <?php echo $milestone['due_date'] ? date('M d, Y', strtotime($milestone['due_date'])) : 'Not set'; ?>
                                        </div>
                                    </div>
                                    <div class="date-item">
                                        <div class="date-label">Completed Date</div>
                                        <div class="date-value">
                                            <?php echo $milestone['completed_date'] ? date('M d, Y', strtotime($milestone['completed_date'])) : 'Not completed'; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="milestone-progress">
                                    <div class="progress-bar">
                                        <div class="progress-fill <?php echo $progress_class; ?>" style="width: <?php 
                                            echo match($milestone['status']) {
                                                'completed' => '100%',
                                                'in_progress' => '50%',
                                                'delayed' => '75%',
                                                default => '25%'
                                            };
                                        ?>;"></div>
                                    </div>
                                </div>
                                
                                <?php if ($milestone['assigned_to']): ?>
                                <div class="milestone-assigned">
                                    <div class="assigned-avatar">
                                        <?php echo strtoupper(substr($milestone['first_name'], 0, 1) . substr($milestone['last_name'], 0, 1)); ?>
                                    </div>
                                    <div class="assigned-info">
                                        <div class="assigned-name">
                                            <?php echo htmlspecialchars($milestone['first_name'] . ' ' . $milestone['last_name']); ?>
                                        </div>
                                        <div class="assigned-role"><?php echo ucfirst($milestone['role']); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($milestone['comments'])): ?>
                                <div class="comments-section">
                                    <h4 style="font-size: 0.9rem; color: var(--gray-dark); margin-bottom: 10px;">
                                        <i class="fas fa-comments"></i> Comments (<?php echo count($milestone['comments']); ?>)
                                    </h4>
                                    <div class="comments-list">
                                        <?php foreach (array_slice($milestone['comments'], 0, 3) as $comment): ?>
                                        <div class="comment-item">
                                            <div class="comment-header">
                                                <div class="comment-author">
                                                    <div class="comment-avatar">
                                                        <?php echo strtoupper(substr($comment['first_name'], 0, 1) . substr($comment['last_name'], 0, 1)); ?>
                                                    </div>
                                                    <div class="comment-info">
                                                        <h4><?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?></h4>
                                                        <span><?php echo ucfirst($comment['role']); ?></span>
                                                    </div>
                                                </div>
                                                <div class="comment-time">
                                                    <?php echo date('M d, Y H:i', strtotime($comment['created_at'])); ?>
                                                </div>
                                            </div>
                                            <div class="comment-text">
                                                <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($milestone['comments']) > 3): ?>
                                    <div style="text-align: center; margin-top: 10px;">
                                        <button class="btn btn-sm btn-secondary" onclick="openCommentModal(<?php echo $milestone['id']; ?>)">
                                            <i class="fas fa-eye"></i> View All <?php echo count($milestone['comments']); ?> Comments
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($milestone['notes']): ?>
                                <div style="margin-top: 15px; padding: 10px; background: #FEF3C7; border-radius: var(--border-radius); border-left: 3px solid #F59E0B;">
                                    <div style="font-size: 0.8rem; color: #92400E; font-weight: 600; margin-bottom: 5px;">
                                        <i class="fas fa-sticky-note"></i> Notes
                                    </div>
                                    <div style="font-size: 0.85rem; color: #92400E;">
                                        <?php echo nl2br(htmlspecialchars($milestone['notes'])); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php else: ?>
                <!-- DOCUMENT LIST VIEW -->
                <div class="timeline-container fade-in">
                    <div class="timeline-header">
                        <h2 class="timeline-title">
                            <i class="fas fa-list"></i>
                            Documents with Timelines
                        </h2>
                        <div class="btn-group">
                            <button class="btn btn-primary" onclick="viewAsTimeline()">
                                <i class="fas fa-stream"></i> Timeline View
                            </button>
                            <button class="btn btn-secondary" onclick="viewAsGrid()">
                                <i class="fas fa-th-large"></i> Grid View
                            </button>
                        </div>
                    </div>
                    
                    <div style="padding: 20px;">
                        <p style="color: var(--gray); margin-bottom: 20px;">
                            Select a document to view its timeline and track progress through legislative stages.
                        </p>
                        
                        <div class="documents-grid" id="documentsGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                            <?php 
                            // Fetch documents with timelines
                            $docs_query = "
                                SELECT DISTINCT 
                                    d.document_id,
                                    d.document_type,
                                    CASE 
                                        WHEN d.document_type = 'ordinance' THEN o.ordinance_number
                                        WHEN d.document_type = 'resolution' THEN r.resolution_number
                                    END as document_number,
                                    CASE 
                                        WHEN d.document_type = 'ordinance' THEN o.title
                                        WHEN d.document_type = 'resolution' THEN r.title
                                    END as document_title,
                                    d.status,
                                    d.priority_level,
                                    COUNT(t.id) as milestone_count,
                                    SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                                    MAX(t.due_date) as latest_due_date
                                FROM document_classification d
                                LEFT JOIN ordinances o ON d.document_id = o.id AND d.document_type = 'ordinance'
                                LEFT JOIN resolutions r ON d.document_id = r.id AND d.document_type = 'resolution'
                                LEFT JOIN timeline_tracking t ON d.document_id = t.document_id AND d.document_type = t.document_type
                                GROUP BY d.document_id, d.document_type
                                ORDER BY d.updated_at DESC
                                LIMIT 12
                            ";
                            
                            $docs_stmt = $conn->query($docs_query);
                            $documents = $docs_stmt->fetchAll();
                            
                            if (empty($documents)): 
                            ?>
                            <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--gray);">
                                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                                <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Documents Found</h3>
                                <p>No documents with timelines found. Create a timeline for a document to track its progress.</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($documents as $doc): 
                                $completion = $doc['milestone_count'] > 0 ? round(($doc['completed_count'] / $doc['milestone_count']) * 100) : 0;
                            ?>
                            <div class="document-card" style="background: var(--white); border-radius: var(--border-radius); padding: 20px; border: 1px solid var(--gray-light); box-shadow: var(--shadow-sm); transition: all 0.3s ease; cursor: pointer;" 
                                 onclick="window.location.href='?document_id=<?php echo $doc['document_id']; ?>&doc_type=<?php echo $doc['document_type']; ?>'">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
                                    <div>
                                        <div style="font-weight: 600; color: var(--qc-blue); margin-bottom: 5px; font-size: 1.1rem;">
                                            <?php echo htmlspecialchars($doc['document_title']); ?>
                                        </div>
                                        <div style="font-size: 0.9rem; color: var(--qc-gold);">
                                            <?php echo htmlspecialchars($doc['document_number']); ?>
                                        </div>
                                    </div>
                                    <span class="status-badge status-<?php echo $doc['status']; ?>">
                                        <?php echo ucfirst($doc['status']); ?>
                                    </span>
                                </div>
                                
                                <div style="margin-bottom: 15px;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                        <span style="font-size: 0.85rem; color: var(--gray);">Progress</span>
                                        <span style="font-size: 0.85rem; font-weight: 600; color: var(--qc-blue);"><?php echo $completion; ?>%</span>
                                    </div>
                                    <div style="height: 8px; background: var(--gray-light); border-radius: 4px; overflow: hidden;">
                                        <div style="height: 100%; width: <?php echo $completion; ?>%; background: linear-gradient(90deg, var(--qc-blue) 0%, var(--qc-green) 100%); border-radius: 4px;"></div>
                                    </div>
                                </div>
                                
                                <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: var(--gray);">
                                    <div>
                                        <i class="fas fa-flag"></i>
                                        <?php echo $doc['milestone_count']; ?> milestones
                                    </div>
                                    <div>
                                        <?php echo $doc['completed_count']; ?> completed
                                    </div>
                                </div>
                                
                                <?php if ($doc['latest_due_date']): ?>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--gray-light); font-size: 0.85rem; color: var(--gray);">
                                    <i class="fas fa-calendar-alt"></i>
                                    Next due: <?php echo date('M d, Y', strtotime($doc['latest_due_date'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- RECENT ACTIVITIES -->
                <?php if (!empty($recent_activities)): ?>
                <div class="activities-section fade-in">
                    <h3 class="filter-title" style="margin-bottom: 20px;">
                        <i class="fas fa-bolt"></i> Upcoming Deadlines
                    </h3>
                    
                    <ul class="activities-list">
                        <?php foreach ($recent_activities as $activity): 
                            $due_class = '';
                            $due_text = '';
                            $due_date = strtotime($activity['due_date']);
                            $today = strtotime('today');
                            $diff = ($due_date - $today) / (60 * 60 * 24);
                            
                            if ($diff < 0) {
                                $due_class = 'due-today';
                                $due_text = 'Overdue';
                            } elseif ($diff == 0) {
                                $due_class = 'due-today';
                                $due_text = 'Due today';
                            } elseif ($diff <= 3) {
                                $due_class = 'due-soon';
                                $due_text = 'Due in ' . $diff . ' days';
                            } else {
                                $due_class = 'due-future';
                                $due_text = 'Due in ' . $diff . ' days';
                            }
                        ?>
                        <li class="activity-item" onclick="window.location.href='?document_id=<?php echo $activity['document_id']; ?>&doc_type=<?php echo $activity['document_type']; ?>#milestone-<?php echo $activity['id']; ?>'">
                            <div class="activity-icon">
                                <i class="fas fa-flag"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?php echo htmlspecialchars($activity['milestone']); ?></div>
                                <div class="activity-meta">
                                    <span><?php echo htmlspecialchars($activity['document_title']); ?></span>
                                    <span class="activity-due <?php echo $due_class; ?>">
                                        <i class="fas fa-calendar-day"></i> <?php echo $due_text; ?>
                                    </span>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right" style="color: var(--gray-light);"></i>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- ADD MILESTONE MODAL -->
    <div class="modal-overlay" id="addMilestoneModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-plus"></i> Add New Milestone</h2>
                <button class="modal-close" onclick="closeAddMilestoneModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addMilestoneForm" method="POST">
                    <input type="hidden" name="action" value="add_milestone">
                    <input type="hidden" name="document_id" value="<?php echo $document_id; ?>">
                    <input type="hidden" name="doc_type" value="<?php echo $doc_type; ?>">
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-info-circle"></i>
                            Milestone Details
                        </h3>
                        
                        <div class="form-group">
                            <label class="form-label required">Milestone Name</label>
                            <input type="text" name="milestone" class="form-control" placeholder="e.g., First Reading, Committee Review, Final Approval" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" placeholder="Describe what this milestone entails..." rows="3"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-calendar-alt"></i>
                            Timeline & Assignment
                        </h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                            <div class="form-group">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Due Date</label>
                                <input type="date" name="due_date" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Assigned To</label>
                            <select name="assigned_to" class="form-control">
                                <option value="">Select assignee...</option>
                                <?php foreach ($available_users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' - ' . $user['role']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label class="form-label">Priority</label>
                                <select name="priority" class="form-control" required>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                    <option value="emergency">Emergency</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Dependent On</label>
                                <select name="dependency_id" class="form-control">
                                    <option value="">None (Starts immediately)</option>
                                    <?php foreach ($timeline_data as $milestone): ?>
                                    <option value="<?php echo $milestone['id']; ?>">
                                        <?php echo htmlspecialchars($milestone['milestone']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-sticky-note"></i>
                            Additional Notes
                        </h3>
                        
                        <div class="form-group">
                            <textarea name="notes" class="form-control" placeholder="Any additional notes or instructions..." rows="4"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeAddMilestoneModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Add Milestone
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- UPDATE STATUS MODAL -->
    <div class="modal-overlay" id="updateStatusModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-sync-alt"></i> Update Milestone Status</h2>
                <button class="modal-close" onclick="closeUpdateStatusModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="updateStatusForm" method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="timeline_id" id="statusTimelineId">
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-flag"></i>
                            Status Update
                        </h3>
                        
                        <div class="form-group">
                            <label class="form-label required">New Status</label>
                            <select name="status" class="form-control" required>
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="delayed">Delayed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status Notes</label>
                            <textarea name="notes" class="form-control" placeholder="Explain the status change or provide updates..." rows="4"></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeUpdateStatusModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ADD COMMENT MODAL -->
    <div class="modal-overlay" id="addCommentModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-comment"></i> Add Comment</h2>
                <button class="modal-close" onclick="closeCommentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addCommentForm" method="POST">
                    <input type="hidden" name="action" value="add_comment">
                    <input type="hidden" name="timeline_id" id="commentTimelineId">
                    
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-comment-dots"></i>
                            Your Comment
                        </h3>
                        
                        <div class="form-group">
                            <label class="form-label required">Comment</label>
                            <textarea name="comment" class="form-control" placeholder="Add your comment here..." rows="6" required></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeCommentModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Post Comment
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
                    <h3>Timeline Tracking Module</h3>
                    <p>
                        Track the complete legislative journey of ordinances and resolutions. 
                        Monitor milestones, deadlines, dependencies, and progress in real-time.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="tracking.php"><i class="fas fa-binoculars"></i> Tracking Dashboard</a></li>
                    <li><a href="status_updates.php"><i class="fas fa-sync-alt"></i> Status Updates</a></li>
                    <li><a href="timeline.php"><i class="fas fa-stream"></i> Timeline Tracking</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Timeline Guide</a></li>
                    <li><a href="#"><i class="fas fa-video"></i> Video Tutorials</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Technical Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback & Suggestions</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Timeline Tracking Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All timeline activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Timeline Tracking Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
            </div>
        </div>
    </footer>

    <!-- Include JavaScript libraries -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.js"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            minDate: "today"
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
        
        // Modal functions
        function openAddMilestoneModal() {
            document.getElementById('addMilestoneModal').classList.add('active');
        }
        
        function closeAddMilestoneModal() {
            document.getElementById('addMilestoneModal').classList.remove('active');
        }
        
        function openStatusModal(timelineId) {
            document.getElementById('statusTimelineId').value = timelineId;
            document.getElementById('updateStatusModal').classList.add('active');
        }
        
        function closeUpdateStatusModal() {
            document.getElementById('updateStatusModal').classList.remove('active');
        }
        
        function openCommentModal(timelineId) {
            document.getElementById('commentTimelineId').value = timelineId;
            document.getElementById('addCommentModal').classList.add('active');
        }
        
        function closeCommentModal() {
            document.getElementById('addCommentModal').classList.remove('active');
        }
        
        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // Generate timeline report
        function generateTimelineReport() {
            const loading = document.createElement('div');
            loading.className = 'loading-spinner';
            loading.innerHTML = `
                <div class="spinner"></div>
                <p>Generating timeline report...</p>
            `;
            document.body.appendChild(loading);
            loading.style.display = 'block';
            
            // Simulate report generation
            setTimeout(() => {
                loading.remove();
                alert('Timeline report generated successfully! The report has been sent to your email.');
            }, 2000);
        }
        
        // View toggle functions
        function viewAsTimeline() {
            alert('Timeline view would show a Gantt chart visualization (requires additional implementation).');
        }
        
        function viewAsGrid() {
            // Already in grid view
        }
        
        // Auto-refresh notifications every 30 seconds
        function refreshNotifications() {
            if (!document.hidden) {
                const notificationsPanel = document.querySelector('.notifications-panel');
                if (notificationsPanel) {
                    // In a real application, fetch new notifications via AJAX
                    console.log('Refreshing notifications...');
                }
            }
        }
        
        // Start auto-refresh
        setInterval(refreshNotifications, 30000);
        
        // Check for overdue milestones
        function checkOverdueMilestones() {
            const dueElements = document.querySelectorAll('.date-value.due-today');
            if (dueElements.length > 0) {
                // Show alert for overdue items
                console.log(`Found ${dueElements.length} overdue milestone(s)`);
            }
        }
        
        // Initialize check
        checkOverdueMilestones();
        
        // Add smooth scrolling to milestone anchors
        document.querySelectorAll('a[href^="#milestone-"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    targetElement.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + M to add milestone
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                openAddMilestoneModal();
            }
            
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
        });
        
        // Initialize tooltips
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function() {
                const tooltip = document.createElement('div');
                tooltip.textContent = this.getAttribute('title');
                tooltip.style.cssText = `
                    position: absolute;
                    background: var(--gray-dark);
                    color: white;
                    padding: 5px 10px;
                    border-radius: 4px;
                    font-size: 0.85rem;
                    z-index: 10000;
                    pointer-events: none;
                `;
                document.body.appendChild(tooltip);
                
                const rect = this.getBoundingClientRect();
                tooltip.style.top = (rect.top - tooltip.offsetHeight - 10) + 'px';
                tooltip.style.left = (rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';
                
                this._tooltip = tooltip;
            });
            
            element.addEventListener('mouseleave', function() {
                if (this._tooltip) {
                    this._tooltip.remove();
                    this._tooltip = null;
                }
            });
        });
        
        // Add animation to elements on scroll
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
        
        // Observe elements
        document.querySelectorAll('.milestone-item, .dashboard-card, .notification-item, .activity-item').forEach(el => {
            observer.observe(el);
        });
        
        // Handle form submissions
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    submitBtn.disabled = true;
                }
            });
        });
        
        // Add today's date to date inputs
        document.querySelectorAll('input[type="date"]').forEach(input => {
            if (!input.value) {
                const today = new Date().toISOString().split('T')[0];
                input.value = today;
            }
        });
        
        // Initialize charts if needed
        function initializeCharts() {
            const ctx = document.getElementById('progressChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Completed', 'In Progress', 'Pending', 'Delayed'],
                        datasets: [{
                            data: [
                                <?php echo $stats['completed_milestones'] ?? 0; ?>,
                                <?php echo $stats['in_progress_milestones'] ?? 0; ?>,
                                <?php echo $stats['pending_milestones'] ?? 0; ?>,
                                <?php echo $stats['delayed_milestones'] ?? 0; ?>
                            ],
                            backgroundColor: [
                                '#2D8C47',
                                '#004488',
                                '#F59E0B',
                                '#C53030'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        }
        
        // Initialize on DOM load
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
            
            // Add loading state to document cards
            document.querySelectorAll('.document-card').forEach(card => {
                card.addEventListener('click', function() {
                    this.style.opacity = '0.7';
                    this.style.cursor = 'wait';
                });
            });
            
            // Add copy document number functionality
            document.querySelectorAll('.document-card').forEach(card => {
                const docNumber = card.querySelector('.doc-number');
                if (docNumber) {
                    card.addEventListener('contextmenu', function(e) {
                        e.preventDefault();
                        navigator.clipboard.writeText(docNumber.textContent).then(() => {
                            alert('Document number copied to clipboard!');
                        });
                    });
                }
            });
        });
    </script>
</body>
</html>