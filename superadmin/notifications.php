<?php
// notifications.php - Notification Alerts Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to view notifications
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

// Handle actions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            $conn->beginTransaction();
            
            switch ($_POST['action']) {
                case 'mark_read':
                    $notification_id = $_POST['notification_id'];
                    $update_query = "UPDATE notifications SET is_read = 1, read_at = NOW() 
                                    WHERE id = :id AND user_id = :user_id";
                    $stmt = $conn->prepare($update_query);
                    $stmt->bindParam(':id', $notification_id);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    
                    // Log the action
                    $log_query = "INSERT INTO notification_logs (notification_id, user_id, action, details) 
                                 VALUES (:notification_id, :user_id, 'read', 'Marked as read via user action')";
                    $stmt = $conn->prepare($log_query);
                    $stmt->bindParam(':notification_id', $notification_id);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    
                    $success_message = "Notification marked as read";
                    break;
                    
                case 'mark_all_read':
                    $update_query = "UPDATE notifications SET is_read = 1, read_at = NOW() 
                                    WHERE user_id = :user_id AND is_read = 0";
                    $stmt = $conn->prepare($update_query);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    
                    $success_message = "All notifications marked as read";
                    break;
                    
                case 'delete':
                    $notification_id = $_POST['notification_id'];
                    $delete_query = "DELETE FROM notifications WHERE id = :id AND user_id = :user_id";
                    $stmt = $conn->prepare($delete_query);
                    $stmt->bindParam(':id', $notification_id);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    
                    $success_message = "Notification deleted";
                    break;
                    
                case 'clear_all':
                    $delete_query = "DELETE FROM notifications WHERE user_id = :user_id AND is_read = 1";
                    $stmt = $conn->prepare($delete_query);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    
                    $success_message = "All read notifications cleared";
                    break;
                    
                case 'update_preferences':
                    // Handle notification preferences update
                    // This would typically update multiple preferences
                    $success_message = "Notification preferences updated";
                    break;
            }
            
            $conn->commit();
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Error processing request: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Check if notifications table exists, if not create sample data
$table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
if ($table_check->rowCount() == 0) {
    // Create notifications table if it doesn't exist
    $create_table_sql = "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `title` varchar(255) NOT NULL,
        `message` text NOT NULL,
        `notification_type` enum('system','document','committee','deadline','alert','update') DEFAULT 'system',
        `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
        `related_document_id` int(11) DEFAULT NULL,
        `related_document_type` enum('ordinance','resolution') DEFAULT NULL,
        `related_committee_id` int(11) DEFAULT NULL,
        `action_url` varchar(500) DEFAULT NULL,
        `is_read` tinyint(1) DEFAULT 0,
        `read_at` timestamp NULL DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `expires_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $conn->exec($create_table_sql);
    
    // Insert sample notifications for the current user
    $sample_notifications = [
        [
            'title' => 'Welcome to Notification System',
            'message' => 'Welcome to the QC Ordinance Tracker Notification System. You will receive alerts about document updates, committee assignments, and system messages here.',
            'notification_type' => 'system',
            'priority' => 'medium',
            'is_read' => 0
        ],
        [
            'title' => 'Draft Registration Approved',
            'message' => 'Your draft ordinance QC-ORD-2026-02-005 has been registered successfully with number QC-REG-ORD-2026-02-0001',
            'notification_type' => 'document',
            'priority' => 'high',
            'related_document_id' => 5,
            'related_document_type' => 'ordinance',
            'is_read' => 0
        ],
        [
            'title' => 'Committee Assignment',
            'message' => 'You have been assigned to review document QC-ORD-2026-02-005 in the Committee on Appropriations',
            'notification_type' => 'committee',
            'priority' => 'medium',
            'related_committee_id' => 1,
            'is_read' => 0
        ],
        [
            'title' => 'Document Priority Updated',
            'message' => 'The priority for QC-ORD-2026-02-005 has been set to "Emergency"',
            'notification_type' => 'alert',
            'priority' => 'urgent',
            'related_document_id' => 5,
            'related_document_type' => 'ordinance',
            'is_read' => 1
        ],
        [
            'title' => 'System Maintenance',
            'message' => 'The QC Ordinance Tracker System will undergo maintenance on February 15, 2026 from 10:00 PM to 2:00 AM',
            'notification_type' => 'system',
            'priority' => 'medium',
            'is_read' => 0
        ]
    ];
    
    foreach ($sample_notifications as $sample) {
        $insert_query = "INSERT INTO notifications (user_id, title, message, notification_type, priority, 
                         related_document_id, related_document_type, related_committee_id, is_read, created_at) 
                         VALUES (:user_id, :title, :message, :type, :priority, :doc_id, :doc_type, :committee_id, :is_read, DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 7) DAY))";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':title', $sample['title']);
        $stmt->bindParam(':message', $sample['message']);
        $stmt->bindParam(':type', $sample['notification_type']);
        $stmt->bindParam(':priority', $sample['priority']);
        $stmt->bindParam(':doc_id', $sample['related_document_id'] ?? null);
        $stmt->bindParam(':doc_type', $sample['related_document_type'] ?? null);
        $stmt->bindParam(':committee_id', $sample['related_committee_id'] ?? null);
        $stmt->bindParam(':is_read', $sample['is_read']);
        $stmt->execute();
    }
}

// Build query for notifications
$query = "SELECT n.*, 
                 o.ordinance_number, o.title as ordinance_title,
                 r.resolution_number, r.title as resolution_title,
                 c.committee_name, c.committee_code
          FROM notifications n
          LEFT JOIN ordinances o ON n.related_document_id = o.id AND n.related_document_type = 'ordinance'
          LEFT JOIN resolutions r ON n.related_document_id = r.id AND n.related_document_type = 'resolution'
          LEFT JOIN committees c ON n.related_committee_id = c.id
          WHERE n.user_id = :user_id";

// Add filters
$params = [':user_id' => $user_id];

if ($filter_type !== 'all') {
    $query .= " AND n.notification_type = :type";
    $params[':type'] = $filter_type;
}

if ($filter_priority !== 'all') {
    $query .= " AND n.priority = :priority";
    $params[':priority'] = $filter_priority;
}

if ($filter_status !== 'all') {
    if ($filter_status === 'unread') {
        $query .= " AND n.is_read = 0";
    } elseif ($filter_status === 'read') {
        $query .= " AND n.is_read = 1";
    }
}

// Add ordering and pagination
$query .= " ORDER BY 
            CASE n.priority 
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            n.created_at DESC
            LIMIT :limit OFFSET :offset";

// Prepare and execute query
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM notifications WHERE user_id = :user_id";
if ($filter_type !== 'all') {
    $count_query .= " AND notification_type = :type";
}
if ($filter_priority !== 'all') {
    $count_query .= " AND priority = :priority";
}
if ($filter_status !== 'all') {
    if ($filter_status === 'unread') {
        $count_query .= " AND is_read = 0";
    } elseif ($filter_status === 'read') {
        $count_query .= " AND is_read = 1";
    }
}

$count_stmt = $conn->prepare($count_query);
$count_stmt->bindParam(':user_id', $user_id);
if ($filter_type !== 'all') {
    $count_stmt->bindParam(':type', $filter_type);
}
if ($filter_priority !== 'all') {
    $count_stmt->bindParam(':priority', $filter_priority);
}
$count_stmt->execute();
$total_result = $count_stmt->fetch();
$total_count = $total_result ? $total_result['total'] : 0;
$total_pages = ceil($total_count / $limit);

// Get notification statistics
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN priority = 'urgent' AND is_read = 0 THEN 1 ELSE 0 END) as urgent_unread,
                SUM(CASE WHEN priority = 'high' AND is_read = 0 THEN 1 ELSE 0 END) as high_unread,
                SUM(CASE WHEN notification_type = 'document' THEN 1 ELSE 0 END) as document_notifications,
                SUM(CASE WHEN notification_type = 'deadline' THEN 1 ELSE 0 END) as deadline_notifications
                FROM notifications 
                WHERE user_id = :user_id";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':user_id', $user_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->fetch();

// Initialize stats object
$stats = (object)[
    'total' => 0,
    'unread' => 0,
    'urgent_unread' => 0,
    'high_unread' => 0,
    'document_notifications' => 0,
    'deadline_notifications' => 0
];

if ($stats_result) {
    $stats->total = $stats_result['total'] ?? 0;
    $stats->unread = $stats_result['unread'] ?? 0;
    $stats->urgent_unread = $stats_result['urgent_unread'] ?? 0;
    $stats->high_unread = $stats_result['high_unread'] ?? 0;
    $stats->document_notifications = $stats_result['document_notifications'] ?? 0;
    $stats->deadline_notifications = $stats_result['deadline_notifications'] ?? 0;
}

// Get recent document activities for contextual notifications - FIXED QUERY
$recent_activities = [];

try {
    // First get ordinances
    $ordinances_query = "SELECT 'ordinance' as doc_type, id, ordinance_number as doc_number, title, 
                         status, updated_at, created_by
                  FROM ordinances 
                  WHERE created_by = :user_id 
                  ORDER BY updated_at DESC 
                  LIMIT 5";
    
    $stmt = $conn->prepare($ordinances_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $ordinances = $stmt->fetchAll();
    
    // Then get resolutions
    $resolutions_query = "SELECT 'resolution' as doc_type, id, resolution_number as doc_number, title, 
                          status, updated_at, created_by
                   FROM resolutions 
                   WHERE created_by = :user_id 
                   ORDER BY updated_at DESC 
                   LIMIT 5";
    
    $stmt = $conn->prepare($resolutions_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $resolutions = $stmt->fetchAll();
    
    // Combine results
    $recent_activities = array_merge($ordinances, $resolutions);
    
    // Sort by updated_at
    usort($recent_activities, function($a, $b) {
        return strtotime($b['updated_at']) - strtotime($a['updated_at']);
    });
    
    // Limit to 10
    $recent_activities = array_slice($recent_activities, 0, 10);
    
} catch (PDOException $e) {
    // If there's an error (like table doesn't exist), just continue with empty array
    $recent_activities = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Alerts | QC Ordinance Tracker</title>
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
            --yellow: #F59E0B;
            --purple: #8B5CF6;
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
        
        /* NOTIFICATION DASHBOARD */
        .notification-dashboard {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .notification-dashboard {
                grid-template-columns: 1fr;
            }
        }
        
        .notification-main {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .notification-sidebar {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        /* FILTER CONTROLS */
        .filter-controls {
            background: var(--off-white);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid var(--gray-light);
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.9rem;
        }
        
        .filter-select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--gray-dark);
            font-family: inherit;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        /* NOTIFICATION LIST */
        .notification-list {
            list-style: none;
        }
        
        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 20px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .notification-item:hover {
            background: var(--off-white);
            transform: translateX(5px);
        }
        
        .notification-item.unread {
            background: rgba(0, 123, 255, 0.05);
            border-left: 3px solid var(--qc-blue);
        }
        
        .notification-item.urgent {
            background: rgba(220, 38, 38, 0.05);
            border-left: 3px solid var(--red);
        }
        
        .notification-item.high {
            background: rgba(245, 158, 11, 0.05);
            border-left: 3px solid var(--yellow);
        }
        
        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .icon-system { background: rgba(107, 114, 128, 0.1); color: var(--gray); }
        .icon-document { background: rgba(59, 130, 246, 0.1); color: var(--qc-blue); }
        .icon-committee { background: rgba(16, 185, 129, 0.1); color: var(--qc-green); }
        .icon-deadline { background: rgba(245, 158, 11, 0.1); color: var(--yellow); }
        .icon-alert { background: rgba(239, 68, 68, 0.1); color: var(--red); }
        .icon-update { background: rgba(139, 92, 246, 0.1); color: var(--purple); }
        
        .notification-content {
            flex: 1;
            min-width: 0;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
            gap: 10px;
        }
        
        .notification-title {
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 1.1rem;
            margin: 0;
        }
        
        .notification-message {
            color: var(--gray);
            line-height: 1.5;
            margin-bottom: 12px;
            font-size: 0.95rem;
        }
        
        .notification-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .notification-time {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .notification-type {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .type-system { background: #e5e7eb; color: #374151; }
        .type-document { background: #dbeafe; color: #1e40af; }
        .type-committee { background: #d1fae5; color: #065f46; }
        .type-deadline { background: #fef3c7; color: #92400e; }
        .type-alert { background: #fee2e2; color: #991b1b; }
        .type-update { background: #f3e8ff; color: #6b21a8; }
        
        .notification-priority {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .priority-urgent { background: #fee2e2; color: #dc2626; }
        .priority-high { background: #fef3c7; color: #d97706; }
        .priority-medium { background: #dbeafe; color: #1d4ed8; }
        .priority-low { background: #f3f4f6; color: #4b5563; }
        
        .notification-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .notification-actions button {
            padding: 5px 12px;
            border: 1px solid var(--gray-light);
            background: var(--white);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .notification-actions button:hover {
            background: var(--off-white);
        }
        
        .action-view { color: var(--qc-blue); }
        .action-mark-read { color: var(--qc-green); }
        .action-delete { color: var(--red); }
        
        .unread-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 8px;
            height: 8px;
            background: var(--qc-blue);
            border-radius: 50%;
        }
        
        /* BULK ACTIONS */
        .bulk-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: var(--off-white);
            border-radius: var(--border-radius);
            margin-top: 30px;
            border: 1px solid var(--gray-light);
        }
        
        .bulk-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .bulk-buttons {
            display: flex;
            gap: 10px;
        }
        
        /* PAGINATION */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }
        
        .pagination-button {
            padding: 8px 16px;
            border: 1px solid var(--gray-light);
            background: var(--white);
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .pagination-button:hover:not(.disabled) {
            background: var(--off-white);
        }
        
        .pagination-button.active {
            background: var(--qc-blue);
            color: var(--white);
            border-color: var(--qc-blue);
        }
        
        .pagination-button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-info {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        /* SIDEBAR WIDGETS */
        .sidebar-widget {
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .sidebar-widget:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .widget-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            color: var(--qc-blue);
            font-size: 1.2rem;
            font-weight: bold;
        }
        
        .widget-title i {
            color: var(--qc-gold);
            font-size: 1.1rem;
        }
        
        /* STATS WIDGET */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .stat-card {
            background: var(--off-white);
            border-radius: var(--border-radius);
            padding: 15px;
            text-align: center;
            border: 1px solid var(--gray-light);
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .stat-card.urgent .stat-value { color: var(--red); }
        .stat-card.unread .stat-value { color: var(--yellow); }
        .stat-card.total .stat-value { color: var(--qc-green); }
        
        /* QUICK ACTIONS */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 15px 10px;
            background: var(--off-white);
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
            text-decoration: none;
            color: var(--gray-dark);
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .quick-action:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            background: var(--white);
        }
        
        .quick-action i {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }
        
        .quick-action.mark-read i { color: var(--qc-green); }
        .quick-action.clear-read i { color: var(--gray); }
        .quick-action.settings i { color: var(--qc-blue); }
        .quick-action.export i { color: var(--purple); }
        
        /* RECENT ACTIVITIES */
        .activities-list {
            list-style: none;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .activity-item:last-child {
            border-bottom: none;
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
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.9rem;
            margin-bottom: 3px;
        }
        
        .activity-meta {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        /* PREFERENCE SETTINGS */
        .preference-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .preference-item:last-child {
            border-bottom: none;
        }
        
        .preference-info h4 {
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 1rem;
            margin-bottom: 3px;
        }
        
        .preference-info p {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
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
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: var(--white);
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: var(--qc-green);
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
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
        
        /* Action Buttons */
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
        
        .btn-primary {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
        }
        
        .btn-primary:hover {
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
        
        .btn-success {
            background: linear-gradient(135deg, var(--qc-green) 0%, var(--qc-green-dark) 100%);
            color: var(--white);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--red) 0%, #9b2c2c 100%);
            color: var(--white);
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: var(--gray-light);
        }
        
        .empty-state h3 {
            color: var(--gray-dark);
            margin-bottom: 10px;
            font-size: 1.3rem;
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
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        .slide-in {
            animation: slideIn 0.4s ease-out;
        }
        
        .pulse {
            animation: pulse 2s infinite;
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
            .notification-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .bulk-actions {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .bulk-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .pagination {
                flex-wrap: wrap;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
                    <p>Notification Alerts Module | Status Tracking System</p>
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
                        <i class="fas fa-bell"></i> Notification Module
                    </h3>
                    <p class="sidebar-subtitle">Alert Management Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="notifications-container">
                <!-- FIXED MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">NOTIFICATION ALERTS MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-bell"></i>
                            </div>
                            <div class="module-title">
                                <h1>Notification Alerts Center</h1>
                                <p class="module-subtitle">
                                    Manage all your system notifications, alerts, and updates in one centralized location. 
                                    Stay informed about document status changes, committee assignments, deadlines, and system updates.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats->total ?? 0; ?></h3>
                                    <p>Total Notifications</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-envelope-open-text"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats->unread ?? 0; ?></h3>
                                    <p>Unread Notifications</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo $stats->urgent_unread ?? 0; ?></h3>
                                    <p>Urgent Alerts</p>
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

                <!-- Notification Dashboard -->
                <div class="notification-dashboard fade-in">
                    <!-- Main Notifications Area -->
                    <div class="notification-main">
                        <!-- Filter Controls -->
                        <div class="filter-controls">
                            <form method="GET" action="" id="filterForm">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label class="filter-label">Notification Type</label>
                                        <select name="type" class="filter-select" onchange="this.form.submit()">
                                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                            <option value="system" <?php echo $filter_type === 'system' ? 'selected' : ''; ?>>System Notifications</option>
                                            <option value="document" <?php echo $filter_type === 'document' ? 'selected' : ''; ?>>Document Updates</option>
                                            <option value="committee" <?php echo $filter_type === 'committee' ? 'selected' : ''; ?>>Committee Notifications</option>
                                            <option value="deadline" <?php echo $filter_type === 'deadline' ? 'selected' : ''; ?>>Deadline Alerts</option>
                                            <option value="alert" <?php echo $filter_type === 'alert' ? 'selected' : ''; ?>>Urgent Alerts</option>
                                            <option value="update" <?php echo $filter_type === 'update' ? 'selected' : ''; ?>>General Updates</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">Priority Level</label>
                                        <select name="priority" class="filter-select" onchange="this.form.submit()">
                                            <option value="all" <?php echo $filter_priority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                            <option value="urgent" <?php echo $filter_priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                            <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High</option>
                                            <option value="medium" <?php echo $filter_priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                            <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low</option>
                                        </select>
                                    </div>
                                    
                                    <div class="filter-group">
                                        <label class="filter-label">Status</label>
                                        <select name="status" class="filter-select" onchange="this.form.submit()">
                                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Notifications</option>
                                            <option value="unread" <?php echo $filter_status === 'unread' ? 'selected' : ''; ?>>Unread Only</option>
                                            <option value="read" <?php echo $filter_status === 'read' ? 'selected' : ''; ?>>Read Only</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fas fa-filter"></i> Apply Filters
                                    </button>
                                    <a href="notifications.php" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-redo"></i> Reset Filters
                                    </a>
                                </div>
                            </form>
                        </div>

                        <!-- Notifications List -->
                        <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <h3>No Notifications Found</h3>
                            <p>You don't have any notifications matching your current filters.</p>
                            <a href="notifications.php" class="btn btn-primary" style="margin-top: 20px;">
                                <i class="fas fa-redo"></i> View All Notifications
                            </a>
                        </div>
                        <?php else: ?>
                        <ul class="notification-list">
                            <?php foreach ($notifications as $notification): 
                                $is_unread = !$notification['is_read'];
                                $notification_class = '';
                                if ($is_unread) $notification_class .= ' unread';
                                if ($notification['priority'] === 'urgent') $notification_class .= ' urgent';
                                if ($notification['priority'] === 'high') $notification_class .= ' high';
                                
                                $icon_class = 'icon-' . $notification['notification_type'];
                                $type_class = 'type-' . $notification['notification_type'];
                                $priority_class = 'priority-' . $notification['priority'];
                                
                                $time_ago = getTimeAgo($notification['created_at']);
                                
                                // Get document info if available
                                $document_info = '';
                                if ($notification['related_document_type'] === 'ordinance' && isset($notification['ordinance_number'])) {
                                    $document_info = $notification['ordinance_number'] . ': ' . $notification['ordinance_title'];
                                } elseif ($notification['related_document_type'] === 'resolution' && isset($notification['resolution_number'])) {
                                    $document_info = $notification['resolution_number'] . ': ' . $notification['resolution_title'];
                                }
                                
                                // Get committee info if available
                                $committee_info = '';
                                if (isset($notification['committee_name'])) {
                                    $committee_info = $notification['committee_name'] . ' (' . $notification['committee_code'] . ')';
                                }
                            ?>
                            <li class="notification-item <?php echo $notification_class; ?>">
                                <?php if ($is_unread): ?>
                                <div class="unread-badge pulse"></div>
                                <?php endif; ?>
                                
                                <div class="notification-icon <?php echo $icon_class; ?>">
                                    <?php 
                                    $icon_map = [
                                        'system' => 'fa-cogs',
                                        'document' => 'fa-file-alt',
                                        'committee' => 'fa-users',
                                        'deadline' => 'fa-calendar-exclamation',
                                        'alert' => 'fa-exclamation-triangle',
                                        'update' => 'fa-sync-alt'
                                    ];
                                    $icon = $icon_map[$notification['notification_type']] ?? 'fa-bell';
                                    ?>
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                
                                <div class="notification-content">
                                    <div class="notification-header">
                                        <h3 class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></h3>
                                        <div class="notification-meta">
                                            <span class="notification-type <?php echo $type_class; ?>">
                                                <?php echo $notification['notification_type']; ?>
                                            </span>
                                            <span class="notification-priority <?php echo $priority_class; ?>">
                                                <?php echo $notification['priority']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <p class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    
                                    <div class="notification-meta">
                                        <span class="notification-time">
                                            <i class="far fa-clock"></i> <?php echo $time_ago; ?>
                                        </span>
                                        
                                        <?php if ($document_info): ?>
                                        <span class="notification-document">
                                            <i class="fas fa-file-contract"></i> <?php echo htmlspecialchars($document_info); ?>
                                        </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($committee_info): ?>
                                        <span class="notification-committee">
                                            <i class="fas fa-users"></i> <?php echo htmlspecialchars($committee_info); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="notification-actions">
                                        <?php if ($is_unread): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                            <button type="submit" class="action-mark-read">
                                                <i class="fas fa-check"></i> Mark as Read
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($document_info && isset($notification['related_document_id'])): ?>
                                        <a href="view_document.php?type=<?php echo $notification['related_document_type']; ?>&id=<?php echo $notification['related_document_id']; ?>" 
                                           class="action-view">
                                            <i class="fas fa-eye"></i> View Document
                                        </a>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this notification?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                            <button type="submit" class="action-delete">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <!-- Bulk Actions -->
                        <div class="bulk-actions">
                            <div class="bulk-checkbox">
                                <input type="checkbox" id="selectAll">
                                <label for="selectAll">Select All</label>
                            </div>
                            
                            <div class="bulk-buttons">
                                <form method="POST">
                                    <input type="hidden" name="action" value="mark_all_read">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="fas fa-check-double"></i> Mark All as Read
                                    </button>
                                </form>
                                
                                <form method="POST" onsubmit="return confirm('Are you sure you want to clear all read notifications?');">
                                    <input type="hidden" name="action" value="clear_all">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash-alt"></i> Clear Read Notifications
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <a href="?page=1&type=<?php echo $filter_type; ?>&priority=<?php echo $filter_priority; ?>&status=<?php echo $filter_status; ?>" 
                               class="pagination-button <?php echo $page == 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-double-left"></i> First
                            </a>
                            
                            <a href="?page=<?php echo max(1, $page - 1); ?>&type=<?php echo $filter_type; ?>&priority=<?php echo $filter_priority; ?>&status=<?php echo $filter_status; ?>" 
                               class="pagination-button <?php echo $page == 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-angle-left"></i> Prev
                            </a>
                            
                            <span class="pagination-info">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </span>
                            
                            <a href="?page=<?php echo min($total_pages, $page + 1); ?>&type=<?php echo $filter_type; ?>&priority=<?php echo $filter_priority; ?>&status=<?php echo $filter_status; ?>" 
                               class="pagination-button <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                                Next <i class="fas fa-angle-right"></i>
                            </a>
                            
                            <a href="?page=<?php echo $total_pages; ?>&type=<?php echo $filter_type; ?>&priority=<?php echo $filter_priority; ?>&status=<?php echo $filter_status; ?>" 
                               class="pagination-button <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                                Last <i class="fas fa-angle-double-right"></i>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar -->
                    <div class="notification-sidebar">
                        <!-- Statistics Widget -->
                        <div class="sidebar-widget">
                            <h3 class="widget-title">
                                <i class="fas fa-chart-pie"></i>
                                Notification Statistics
                            </h3>
                            
                            <div class="stats-grid">
                                <div class="stat-card total">
                                    <div class="stat-value"><?php echo $stats->total ?? 0; ?></div>
                                    <div class="stat-label">Total</div>
                                </div>
                                <div class="stat-card unread">
                                    <div class="stat-value"><?php echo $stats->unread ?? 0; ?></div>
                                    <div class="stat-label">Unread</div>
                                </div>
                                <div class="stat-card urgent">
                                    <div class="stat-value"><?php echo $stats->urgent_unread ?? 0; ?></div>
                                    <div class="stat-label">Urgent</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value"><?php echo $stats->document_notifications ?? 0; ?></div>
                                    <div class="stat-label">Documents</div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="sidebar-widget">
                            <h3 class="widget-title">
                                <i class="fas fa-bolt"></i>
                                Quick Actions
                            </h3>
                            
                            <div class="quick-actions">
                                <form method="POST" class="quick-action mark-read">
                                    <input type="hidden" name="action" value="mark_all_read">
                                    <button type="submit" style="background: none; border: none; cursor: pointer; width: 100%;">
                                        <i class="fas fa-check-double"></i>
                                        <span>Mark All Read</span>
                                    </button>
                                </form>
                                
                                <form method="POST" onsubmit="return confirm('Clear all read notifications?');" class="quick-action clear-read">
                                    <input type="hidden" name="action" value="clear_all">
                                    <button type="submit" style="background: none; border: none; cursor: pointer; width: 100%;">
                                        <i class="fas fa-trash-alt"></i>
                                        <span>Clear Read</span>
                                    </button>
                                </form>
                                
                                <a href="#settings" onclick="openSettings()" class="quick-action settings">
                                    <i class="fas fa-cogs"></i>
                                    <span>Settings</span>
                                </a>
                                
                                <a href="export_notifications.php" class="quick-action export">
                                    <i class="fas fa-file-export"></i>
                                    <span>Export</span>
                                </a>
                            </div>
                        </div>

                        <!-- Recent Document Activities -->
                        <div class="sidebar-widget">
                            <h3 class="widget-title">
                                <i class="fas fa-history"></i>
                                Recent Activities
                            </h3>
                            
                            <?php if (empty($recent_activities)): ?>
                            <p style="color: var(--gray); text-align: center; padding: 20px 0;">
                                No recent activities
                            </p>
                            <?php else: ?>
                            <ul class="activities-list">
                                <?php foreach ($recent_activities as $activity): 
                                    $activity_time = getTimeAgo($activity['updated_at']);
                                    $icon = $activity['doc_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                                    $color = $activity['doc_type'] === 'ordinance' ? 'var(--qc-blue)' : 'var(--qc-green)';
                                ?>
                                <li class="activity-item">
                                    <div class="activity-icon" style="color: <?php echo $color; ?>;">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                        <div class="activity-meta">
                                            <?php echo htmlspecialchars($activity['doc_number']); ?> • 
                                            <?php echo ucfirst($activity['status']); ?> • 
                                            <?php echo $activity_time; ?>
                                        </div>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>

                        <!-- Notification Preferences -->
                        <div class="sidebar-widget">
                            <h3 class="widget-title">
                                <i class="fas fa-sliders-h"></i>
                                Quick Preferences
                            </h3>
                            
                            <div class="preference-item">
                                <div class="preference-info">
                                    <h4>Email Notifications</h4>
                                    <p>Receive notifications via email</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            
                            <div class="preference-item">
                                <div class="preference-info">
                                    <h4>Desktop Alerts</h4>
                                    <p>Show desktop notifications</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            
                            <div class="preference-item">
                                <div class="preference-info">
                                    <h4>Urgent Only</h4>
                                    <p>Only show urgent notifications</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>Notification Alerts Module</h3>
                    <p>
                        Centralized notification management system for tracking document updates, 
                        committee assignments, deadlines, and system alerts. Stay informed and 
                        never miss important updates.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="tracking.php"><i class="fas fa-chart-line"></i> Tracking Dashboard</a></li>
                    <li><a href="my_documents.php"><i class="fas fa-folder-open"></i> My Documents</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Notification Types</h3>
                <ul class="footer-links">
                    <li><a href="?type=document"><i class="fas fa-file-alt"></i> Document Updates</a></li>
                    <li><a href="?type=committee"><i class="fas fa-users"></i> Committee Notices</a></li>
                    <li><a href="?type=deadline"><i class="fas fa-calendar-alt"></i> Deadline Alerts</a></li>
                    <li><a href="?type=system"><i class="fas fa-cogs"></i> System Messages</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Notification Alerts Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All notification activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Notification Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
        
        // Select all functionality
        const selectAllCheckbox = document.getElementById('selectAll');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.notification-item input[type="checkbox"]');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });
        }
        
        // Notification actions
        function markAsRead(notificationId) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'mark_read';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'notification_id';
            idInput.value = notificationId;
            
            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
        
        function deleteNotification(notificationId) {
            if (confirm('Are you sure you want to delete this notification?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'notification_id';
                idInput.value = notificationId;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Auto-refresh notifications every 30 seconds
        let refreshTimer;
        function startAutoRefresh() {
            refreshTimer = setInterval(() => {
                const unreadCount = <?php echo $stats->unread ?? 0; ?>;
                if (unreadCount > 0) {
                    // Check for new notifications
                    fetch('check_new_notifications.php')
                        .then(response => response.json())
                        .then(data => {
                            if (data.new_count > 0) {
                                // Show notification badge
                                updateNotificationBadge(data.new_count);
                                // Refresh page if user is on notifications page
                                if (window.location.pathname.includes('notifications.php')) {
                                    window.location.reload();
                                }
                            }
                        });
                }
            }, 30000); // 30 seconds
        }
        
        function updateNotificationBadge(count) {
            // Update badge in header if exists
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                badge.textContent = count;
                badge.style.display = count > 0 ? 'flex' : 'none';
            }
        }
        
        // Start auto-refresh
        startAutoRefresh();
        
        // Stop auto-refresh when leaving page
        window.addEventListener('beforeunload', function() {
            clearInterval(refreshTimer);
        });
        
        // Settings modal
        function openSettings() {
            // In a real application, this would open a settings modal
            alert('Notification settings feature coming soon!');
        }
        
        // Filter form auto-submit
        document.querySelectorAll('.filter-select').forEach(select => {
            select.addEventListener('change', function() {
                // Add a small delay to allow multiple selections
                setTimeout(() => {
                    document.getElementById('filterForm').submit();
                }, 100);
            });
        });
        
        // Add animation to notification items
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
        
        // Observe notification items
        document.querySelectorAll('.notification-item').forEach(item => {
            observer.observe(item);
        });
        
        // Real-time notification simulation
        function simulateNewNotification() {
            // This is for demonstration purposes only
            const notificationTypes = ['document', 'committee', 'system', 'alert'];
            const priorities = ['low', 'medium', 'high', 'urgent'];
            
            const type = notificationTypes[Math.floor(Math.random() * notificationTypes.length)];
            const priority = priorities[Math.floor(Math.random() * priorities.length)];
            
            // Create a simulated notification
            const notificationItem = document.createElement('li');
            notificationItem.className = 'notification-item unread fade-in';
            if (priority === 'urgent') notificationItem.classList.add('urgent');
            if (priority === 'high') notificationItem.classList.add('high');
            
            const iconClass = 'icon-' + type;
            const typeClass = 'type-' + type;
            const priorityClass = 'priority-' + priority;
            
            notificationItem.innerHTML = `
                <div class="unread-badge pulse"></div>
                <div class="notification-icon ${iconClass}">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-header">
                        <h3 class="notification-title">New Test Notification</h3>
                        <div class="notification-meta">
                            <span class="notification-type ${typeClass}">${type}</span>
                            <span class="notification-priority ${priorityClass}">${priority}</span>
                        </div>
                    </div>
                    <p class="notification-message">This is a simulated notification for demonstration purposes.</p>
                    <div class="notification-meta">
                        <span class="notification-time">
                            <i class="far fa-clock"></i> Just now
                        </span>
                    </div>
                </div>
            `;
            
            // Add to top of list
            const notificationList = document.querySelector('.notification-list');
            if (notificationList) {
                notificationList.insertBefore(notificationItem, notificationList.firstChild);
                
                // Update statistics
                updateStats(1, 0);
            }
        }
        
        function updateStats(newCount, urgentCount) {
            // Update stats display
            const totalStat = document.querySelector('.stat-item:nth-child(1) h3');
            const unreadStat = document.querySelector('.stat-item:nth-child(2) h3');
            
            if (totalStat) {
                const currentTotal = parseInt(totalStat.textContent);
                totalStat.textContent = currentTotal + newCount;
            }
            
            if (unreadStat) {
                const currentUnread = parseInt(unreadStat.textContent);
                unreadStat.textContent = currentUnread + newCount;
            }
        }
        
        // For demo: simulate a new notification every 60 seconds
        setInterval(simulateNewNotification, 60000);
        
    </script>
</body>
</html>

<?php
// Helper function to format time ago
function getTimeAgo($timestamp) {
    $time = strtotime($timestamp);
    $time_diff = time() - $time;
    
    if ($time_diff < 60) {
        return 'Just now';
    } elseif ($time_diff < 3600) {
        $minutes = floor($time_diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($time_diff < 86400) {
        $hours = floor($time_diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($time_diff < 604800) {
        $days = floor($time_diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}
?>