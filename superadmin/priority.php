<?php
// priority.php - Priority Setting Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to set priorities (admin or super_admin only)
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

// Handle priority update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_priority') {
        try {
            $conn->beginTransaction();
            
            $document_id = $_POST['document_id'];
            $document_type = $_POST['document_type'];
            $new_priority = $_POST['priority'];
            $reason = $_POST['reason'] ?? '';
            
            // Get current priority
            $current_query = "SELECT priority_level FROM document_classification 
                            WHERE document_id = :doc_id AND document_type = :doc_type";
            $current_stmt = $conn->prepare($current_query);
            $current_stmt->bindParam(':doc_id', $document_id);
            $current_stmt->bindParam(':doc_type', $document_type);
            $current_stmt->execute();
            $current_result = $current_stmt->fetch();
            $current_priority = $current_result['priority_level'] ?? 'medium';
            
            // Update or insert classification
            if ($current_result) {
                // Update existing
                $update_query = "UPDATE document_classification 
                               SET priority_level = :priority, 
                                   classified_by = :classified_by,
                                   classified_at = NOW(),
                                   status = 'classified'
                               WHERE document_id = :doc_id AND document_type = :doc_type";
                $stmt = $conn->prepare($update_query);
                $stmt->bindParam(':priority', $new_priority);
                $stmt->bindParam(':classified_by', $user_id);
                $stmt->bindParam(':doc_id', $document_id);
                $stmt->bindParam(':doc_type', $document_type);
                $stmt->execute();
            } else {
                // Insert new
                $insert_query = "INSERT INTO document_classification 
                                (document_id, document_type, classification_type, 
                                 priority_level, status, classified_by, classified_at)
                                VALUES (:doc_id, :doc_type, 
                                       (SELECT CASE WHEN :doc_type = 'ordinance' THEN 'ordinance' ELSE 'resolution' END),
                                       :priority, 'classified', :classified_by, NOW())";
                $stmt = $conn->prepare($insert_query);
                $stmt->bindParam(':doc_id', $document_id);
                $stmt->bindParam(':doc_type', $document_type);
                $stmt->bindParam(':priority', $new_priority);
                $stmt->bindParam(':classified_by', $user_id);
                $stmt->execute();
            }
            
            // Record in priority history
            $history_query = "INSERT INTO document_priority_history 
                            (document_id, document_type, previous_priority, 
                             new_priority, reason, changed_by)
                            VALUES (:doc_id, :doc_type, :prev_priority, 
                                    :new_priority, :reason, :changed_by)";
            $stmt = $conn->prepare($history_query);
            $stmt->bindParam(':doc_id', $document_id);
            $stmt->bindParam(':doc_type', $document_type);
            $stmt->bindParam(':prev_priority', $current_priority);
            $stmt->bindParam(':new_priority', $new_priority);
            $stmt->bindParam(':reason', $reason);
            $stmt->bindParam(':changed_by', $user_id);
            $stmt->execute();
            
            $conn->commit();
            
            // Log the action
            $doc_number = '';
            if ($document_type === 'ordinance') {
                $doc_query = "SELECT ordinance_number FROM ordinances WHERE id = :doc_id";
                $doc_stmt = $conn->prepare($doc_query);
                $doc_stmt->bindParam(':doc_id', $document_id);
                $doc_stmt->execute();
                $doc_result = $doc_stmt->fetch();
                $doc_number = $doc_result['ordinance_number'] ?? '';
            } else {
                $doc_query = "SELECT resolution_number FROM resolutions WHERE id = :doc_id";
                $doc_stmt = $conn->prepare($doc_query);
                $doc_stmt->bindParam(':doc_id', $document_id);
                $doc_stmt->execute();
                $doc_result = $doc_stmt->fetch();
                $doc_number = $doc_result['resolution_number'] ?? '';
            }
            
            $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                           VALUES (:user_id, 'PRIORITY_SET', 'Updated priority for {$doc_number} to {$new_priority}', :ip, :agent)";
            $stmt = $conn->prepare($audit_query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
            $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
            $stmt->execute();
            
            $success_message = "Priority updated successfully to: " . ucfirst($new_priority);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Error updating priority: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_priority = $_GET['priority'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query for documents
$documents_query = "(
    SELECT 'ordinance' as doc_type, o.id, o.ordinance_number as doc_number, 
           o.title, o.status, o.created_at, 
           dc.priority_level, dc.classified_at,
           CONCAT(u.first_name, ' ', u.last_name) as classified_by_name,
           dc.category_id, cat.category_name
    FROM ordinances o
    LEFT JOIN document_classification dc ON dc.document_id = o.id AND dc.document_type = 'ordinance'
    LEFT JOIN users u ON u.id = dc.classified_by
    LEFT JOIN document_categories cat ON cat.id = dc.category_id
    WHERE o.status IN ('draft', 'pending')
) UNION ALL (
    SELECT 'resolution' as doc_type, r.id, r.resolution_number as doc_number, 
           r.title, r.status, r.created_at,
           dc.priority_level, dc.classified_at,
           CONCAT(u.first_name, ' ', u.last_name) as classified_by_name,
           dc.category_id, cat.category_name
    FROM resolutions r
    LEFT JOIN document_classification dc ON dc.document_id = r.id AND dc.document_type = 'resolution'
    LEFT JOIN users u ON u.id = dc.classified_by
    LEFT JOIN document_categories cat ON cat.id = dc.category_id
    WHERE r.status IN ('draft', 'pending')
)";

// Apply filters
$conditions = [];
$params = [];

if ($filter_type !== 'all') {
    $conditions[] = "doc_type = :type";
    $params[':type'] = $filter_type;
}

if ($filter_status !== 'all') {
    $conditions[] = "status = :status";
    $params[':status'] = $filter_status;
}

if ($filter_priority !== 'all') {
    if ($filter_priority === 'unset') {
        $conditions[] = "priority_level IS NULL";
    } else {
        $conditions[] = "priority_level = :priority";
        $params[':priority'] = $filter_priority;
    }
}

if (!empty($search_query)) {
    $conditions[] = "(doc_number LIKE :search OR title LIKE :search)";
    $params[':search'] = "%{$search_query}%";
}

if (!empty($conditions)) {
    $documents_query .= " WHERE " . implode(" AND ", $conditions);
}

$documents_query .= " ORDER BY 
    CASE 
        WHEN priority_level = 'emergency' THEN 1
        WHEN priority_level = 'urgent' THEN 2
        WHEN priority_level = 'high' THEN 3
        WHEN priority_level = 'medium' THEN 4
        WHEN priority_level = 'low' THEN 5
        ELSE 6
    END, created_at DESC";

$stmt = $conn->prepare($documents_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$documents = $stmt->fetchAll();

// Get priority statistics
$stats_query = "SELECT 
    COALESCE(priority_level, 'unset') as priority,
    COUNT(*) as count
FROM (
    SELECT dc.priority_level 
    FROM ordinances o
    LEFT JOIN document_classification dc ON dc.document_id = o.id AND dc.document_type = 'ordinance'
    WHERE o.status IN ('draft', 'pending')
    UNION ALL
    SELECT dc.priority_level 
    FROM resolutions r
    LEFT JOIN document_classification dc ON dc.document_id = r.id AND dc.document_type = 'resolution'
    WHERE r.status IN ('draft', 'pending')
) as combined
GROUP BY COALESCE(priority_level, 'unset')
ORDER BY 
    CASE COALESCE(priority_level, 'unset')
        WHEN 'emergency' THEN 1
        WHEN 'urgent' THEN 2
        WHEN 'high' THEN 3
        WHEN 'medium' THEN 4
        WHEN 'low' THEN 5
        ELSE 6
    END";

$stats_stmt = $conn->query($stats_query);
$priority_stats = $stats_stmt->fetchAll();

// Get recent priority changes
$recent_changes_query = "SELECT ph.*, 
                        CONCAT(u.first_name, ' ', u.last_name) as changed_by_name,
                        CASE 
                            WHEN ph.document_type = 'ordinance' THEN o.ordinance_number
                            ELSE r.resolution_number
                        END as doc_number,
                        CASE 
                            WHEN ph.document_type = 'ordinance' THEN o.title
                            ELSE r.title
                        END as doc_title
                        FROM document_priority_history ph
                        LEFT JOIN users u ON u.id = ph.changed_by
                        LEFT JOIN ordinances o ON o.id = ph.document_id AND ph.document_type = 'ordinance'
                        LEFT JOIN resolutions r ON r.id = ph.document_id AND ph.document_type = 'resolution'
                        ORDER BY ph.changed_at DESC
                        LIMIT 10";
$recent_changes_stmt = $conn->query($recent_changes_query);
$recent_changes = $recent_changes_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Priority Setting | QC Ordinance Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
            --yellow: #F59E0B;
            --orange: #EA580C;
            --purple: #8B5CF6;
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
        
        /* PRIORITY INDICATORS */
        .priority-indicators {
            display: flex;
            gap: 15px;
            margin: 30px 0;
            flex-wrap: wrap;
        }
        
        .priority-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: var(--shadow-sm);
        }
        
        .priority-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .priority-emergency { background: linear-gradient(135deg, #DC2626 0%, #991B1B 100%); color: white; }
        .priority-urgent { background: linear-gradient(135deg, #EA580C 0%, #9A3412 100%); color: white; }
        .priority-high { background: linear-gradient(135deg, #F59E0B 0%, #92400E 100%); color: white; }
        .priority-medium { background: linear-gradient(135deg, #3B82F6 0%, #1E40AF 100%); color: white; }
        .priority-low { background: linear-gradient(135deg, #10B981 0%, #065F46 100%); color: white; }
        .priority-unset { background: var(--gray-light); color: var(--gray-dark); }
        
        /* FILTERS SECTION */
        .filters-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            margin-bottom: 30px;
            border: 1px solid var(--gray-light);
        }
        
        .filters-title {
            font-size: 1.2rem;
            color: var(--qc-blue);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            margin-bottom: 15px;
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
            font-size: 0.95rem;
            color: var(--gray-dark);
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
            padding: 10px 15px 10px 45px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        .filter-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }
        
        /* DOCUMENTS TABLE */
        .documents-section {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            margin-bottom: 40px;
            border: 1px solid var(--gray-light);
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            color: var(--qc-blue);
            font-size: 1.3rem;
            font-weight: bold;
        }
        
        .section-title i {
            color: var(--qc-gold);
        }
        
        .documents-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .documents-table th {
            background: var(--qc-blue);
            color: var(--white);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .documents-table th:first-child {
            border-top-left-radius: var(--border-radius);
        }
        
        .documents-table th:last-child {
            border-top-right-radius: var(--border-radius);
        }
        
        .documents-table td {
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: top;
        }
        
        .documents-table tr:hover {
            background: var(--off-white);
        }
        
        .documents-table tr:last-child td {
            border-bottom: none;
        }
        
        .document-type {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .type-ordinance {
            background: rgba(0, 51, 102, 0.1);
            color: var(--qc-blue);
            border: 1px solid rgba(0, 51, 102, 0.2);
        }
        
        .type-resolution {
            background: rgba(45, 140, 71, 0.1);
            color: var(--qc-green);
            border: 1px solid rgba(45, 140, 71, 0.2);
        }
        
        .document-number {
            font-weight: 600;
            color: var(--gray-dark);
        }
        
        .document-title {
            color: var(--gray-dark);
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .document-category {
            display: inline-block;
            padding: 3px 10px;
            background: var(--gray-light);
            color: var(--gray-dark);
            border-radius: 20px;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .priority-selector {
            min-width: 120px;
        }
        
        .priority-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .priority-select.emergency { 
            background: rgba(220, 38, 38, 0.1); 
            color: #DC2626; 
            border-color: rgba(220, 38, 38, 0.3); 
        }
        
        .priority-select.urgent { 
            background: rgba(234, 88, 12, 0.1); 
            color: #EA580C; 
            border-color: rgba(234, 88, 12, 0.3); 
        }
        
        .priority-select.high { 
            background: rgba(245, 158, 11, 0.1); 
            color: #D97706; 
            border-color: rgba(245, 158, 11, 0.3); 
        }
        
        .priority-select.medium { 
            background: rgba(59, 130, 246, 0.1); 
            color: #2563EB; 
            border-color: rgba(59, 130, 246, 0.3); 
        }
        
        .priority-select.low { 
            background: rgba(16, 185, 129, 0.1); 
            color: #059669; 
            border-color: rgba(16, 185, 129, 0.3); 
        }
        
        .priority-select.unset { 
            background: var(--gray-light); 
            color: var(--gray); 
        }
        
        .priority-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .badge-emergency { background: linear-gradient(135deg, #DC2626 0%, #991B1B 100%); color: white; }
        .badge-urgent { background: linear-gradient(135deg, #EA580C 0%, #9A3412 100%); color: white; }
        .badge-high { background: linear-gradient(135deg, #F59E0B 0%, #92400E 100%); color: white; }
        .badge-medium { background: linear-gradient(135deg, #3B82F6 0%, #1E40AF 100%); color: white; }
        .badge-low { background: linear-gradient(135deg, #10B981 0%, #065F46 100%); color: white; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        /* STATISTICS SECTION */
        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .stats-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .stats-card h3 {
            color: var(--qc-blue);
            margin-bottom: 20px;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .priority-chart {
            margin-top: 20px;
        }
        
        .chart-bar {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .chart-label {
            min-width: 80px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .chart-bar-container {
            flex: 1;
            height: 24px;
            background: var(--gray-light);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .chart-bar-fill {
            height: 100%;
            border-radius: 12px;
            transition: width 0.5s ease;
        }
        
        .chart-count {
            min-width: 40px;
            text-align: right;
            font-weight: 600;
        }
        
        /* ACTION BUTTONS */
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
        
        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
        }
        
        .btn-icon {
            padding: 8px;
            width: 36px;
            height: 36px;
            justify-content: center;
        }
        
        /* ALERTS */
        .alert {
            padding: 15px 20px;
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
            font-size: 1.2rem;
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
            padding: 20px;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            width: 100%;
            max-width: 500px;
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
            padding: 20px;
            border-top-left-radius: var(--border-radius-lg);
            border-top-right-radius: var(--border-radius-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--qc-gold);
        }
        
        .modal-header h3 {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--white);
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--white);
            font-size: 1.5rem;
            cursor: pointer;
            width: 36px;
            height: 36px;
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
            padding: 25px;
        }
        
        .modal-section {
            margin-bottom: 20px;
        }
        
        .modal-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.95rem;
        }
        
        .modal-doc-info {
            background: var(--off-white);
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
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
            
            .documents-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-section {
                grid-template-columns: 1fr;
            }
            
            .priority-indicators {
                flex-direction: column;
                gap: 10px;
            }
            
            .modal {
                width: 95%;
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
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }
        
        .empty-state-icon {
            font-size: 3rem;
            color: var(--gray-light);
            margin-bottom: 20px;
        }
        
        /* Tooltip */
        .tooltip {
            position: relative;
            display: inline-block;
            cursor: help;
        }
        
        .tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background: var(--gray-dark);
            color: var(--white);
            text-align: center;
            padding: 10px;
            border-radius: var(--border-radius);
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.85rem;
            font-weight: normal;
        }
        
        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        .tooltip .tooltip-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: var(--gray-dark) transparent transparent transparent;
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
                    <p>Priority Setting Module | Classification & Organization</p>
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
                    <p class="sidebar-subtitle">Priority Setting Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- FIXED MODULE HEADER -->
            <div class="module-header fade-in">
                <div class="module-header-content">
                    <div class="module-badge">PRIORITY SETTING MODULE</div>
                    
                    <div class="module-title-wrapper">
                        <div class="module-icon">
                            <i class="fas fa-flag"></i>
                        </div>
                        <div class="module-title">
                            <h1>Document Priority Setting</h1>
                            <p class="module-subtitle">
                                Set urgency and importance levels for ordinances and resolutions. 
                                Assign priority classifications to manage document processing order and resource allocation.
                            </p>
                        </div>
                    </div>
                    
                    <div class="module-stats">
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo count($documents); ?></h3>
                                <p>Documents to Prioritize</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <div class="stat-info">
                                <?php 
                                    $urgent_count = 0;
                                    foreach ($priority_stats as $stat) {
                                        if (in_array($stat['priority'], ['emergency', 'urgent', 'high'])) {
                                            $urgent_count += $stat['count'];
                                        }
                                    }
                                ?>
                                <h3><?php echo $urgent_count; ?></h3>
                                <p>High Priority Documents</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo count($recent_changes); ?></h3>
                                <p>Recent Priority Changes</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PRIORITY LEGEND -->
            <div class="priority-indicators fade-in">
                <div class="priority-indicator priority-emergency">
                    <div class="priority-dot"></div>
                    <span>Emergency - Immediate action required</span>
                </div>
                <div class="priority-indicator priority-urgent">
                    <div class="priority-dot"></div>
                    <span>Urgent - High attention needed</span>
                </div>
                <div class="priority-indicator priority-high">
                    <div class="priority-dot"></div>
                    <span>High - Important matters</span>
                </div>
                <div class="priority-indicator priority-medium">
                    <div class="priority-dot"></div>
                    <span>Medium - Standard processing</span>
                </div>
                <div class="priority-indicator priority-low">
                    <div class="priority-dot"></div>
                    <span>Low - Routine matters</span>
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
                <h3 class="filters-title">
                    <i class="fas fa-filter"></i> Filter Documents
                </h3>
                
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label">Document Type</label>
                            <select name="type" class="filter-select">
                                <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="ordinance" <?php echo $filter_type === 'ordinance' ? 'selected' : ''; ?>>Ordinances Only</option>
                                <option value="resolution" <?php echo $filter_type === 'resolution' ? 'selected' : ''; ?>>Resolutions Only</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select">
                                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Priority Level</label>
                            <select name="priority" class="filter-select">
                                <option value="all" <?php echo $filter_priority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                <option value="unset" <?php echo $filter_priority === 'unset' ? 'selected' : ''; ?>>Not Set</option>
                                <option value="emergency" <?php echo $filter_priority === 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                                <option value="urgent" <?php echo $filter_priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                <option value="high" <?php echo $filter_priority === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $filter_priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low" <?php echo $filter_priority === 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <div class="search-box">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" name="search" class="search-input" 
                                       placeholder="Search by document number or title..." 
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="priority.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- STATISTICS SECTION -->
            <div class="stats-section fade-in">
                <div class="stats-card">
                    <h3><i class="fas fa-chart-pie"></i> Priority Distribution</h3>
                    
                    <div class="priority-chart">
                        <?php 
                        $total_docs = count($documents);
                        $total_count = 0;
                        foreach ($priority_stats as $stat) {
                            $total_count += $stat['count'];
                        }
                        
                        $priority_order = ['emergency', 'urgent', 'high', 'medium', 'low', 'unset'];
                        foreach ($priority_order as $priority) {
                            $stat = array_filter($priority_stats, function($s) use ($priority) {
                                return $s['priority'] === $priority;
                            });
                            $stat = reset($stat);
                            $count = $stat ? $stat['count'] : 0;
                            $percentage = $total_count > 0 ? ($count / $total_count * 100) : 0;
                            
                            $class = 'priority-' . $priority;
                            $label = ucfirst($priority);
                            $color = '';
                            
                            switch ($priority) {
                                case 'emergency': $color = '#DC2626'; break;
                                case 'urgent': $color = '#EA580C'; break;
                                case 'high': $color = '#F59E0B'; break;
                                case 'medium': $color = '#3B82F6'; break;
                                case 'low': $color = '#10B981'; break;
                                default: $color = '#6B7280'; break;
                            }
                        ?>
                        <div class="chart-bar">
                            <div class="chart-label <?php echo $class; ?>">
                                <?php echo $label; ?>
                            </div>
                            <div class="chart-bar-container">
                                <div class="chart-bar-fill" 
                                     style="width: <?php echo $percentage; ?>%; background: <?php echo $color; ?>;">
                                </div>
                            </div>
                            <div class="chart-count"><?php echo $count; ?></div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                
                <div class="stats-card">
                    <h3><i class="fas fa-history"></i> Recent Priority Changes</h3>
                    
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php if (empty($recent_changes)): ?>
                            <div style="text-align: center; padding: 20px; color: var(--gray);">
                                <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                <p>No priority changes recorded yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_changes as $change): 
                                $priority_class = 'badge-' . $change['new_priority'];
                            ?>
                            <div style="padding: 10px; border-bottom: 1px solid var(--gray-light);">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                    <span style="font-weight: 600;"><?php echo htmlspecialchars($change['doc_number']); ?></span>
                                    <span class="priority-badge <?php echo $priority_class; ?>">
                                        <?php echo ucfirst($change['new_priority']); ?>
                                    </span>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--gray);">
                                    By <?php echo htmlspecialchars($change['changed_by_name']); ?> • 
                                    <?php echo date('M d, Y H:i', strtotime($change['changed_at'])); ?>
                                </div>
                                <?php if (!empty($change['reason'])): ?>
                                <div style="font-size: 0.85rem; margin-top: 5px; color: var(--gray-dark);">
                                    <i class="fas fa-comment"></i> <?php echo htmlspecialchars($change['reason']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- DOCUMENTS SECTION -->
            <div class="documents-section fade-in">
                <div class="section-title">
                    <i class="fas fa-file-alt"></i>
                    <h2>Documents for Priority Setting</h2>
                    <span style="margin-left: auto; font-size: 0.9rem; color: var(--gray);">
                        <?php echo count($documents); ?> document(s) found
                    </span>
                </div>
                
                <?php if (empty($documents)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox empty-state-icon"></i>
                        <h3>No Documents Found</h3>
                        <p>No documents match your current filter criteria.</p>
                        <a href="priority.php" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Category</th>
                                    <th>Current Priority</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): 
                                    $priority = $doc['priority_level'] ?? 'unset';
                                    $priority_class = 'badge-' . $priority;
                                ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: flex-start; gap: 10px;">
                                            <div style="min-width: 40px;">
                                                <span class="document-type <?php echo $doc['doc_type'] === 'ordinance' ? 'type-ordinance' : 'type-resolution'; ?>">
                                                    <?php echo $doc['doc_type'] === 'ordinance' ? 'ORD' : 'RES'; ?>
                                                </span>
                                            </div>
                                            <div>
                                                <div class="document-number"><?php echo htmlspecialchars($doc['doc_number']); ?></div>
                                                <div class="document-title"><?php echo htmlspecialchars($doc['title']); ?></div>
                                                <div style="font-size: 0.85rem; color: var(--gray); margin-top: 5px;">
                                                    <span style="margin-right: 15px;">
                                                        <i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime($doc['created_at'])); ?>
                                                    </span>
                                                    <span class="tooltip">
                                                        <i class="far fa-user"></i> <?php echo htmlspecialchars($doc['classified_by_name'] ?? 'Not classified'); ?>
                                                        <?php if ($doc['classified_by_name']): ?>
                                                        <span class="tooltip-text">Classified by <?php echo htmlspecialchars($doc['classified_by_name']); ?><br>
                                                        <?php echo date('M d, Y', strtotime($doc['classified_at'])); ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <?php if ($doc['category_name']): ?>
                                                <div class="document-category"><?php echo htmlspecialchars($doc['category_name']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($doc['category_name']): ?>
                                            <?php echo htmlspecialchars($doc['category_name']); ?>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-style: italic;">Not categorized</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($priority === 'unset'): ?>
                                            <span class="priority-badge priority-unset">Not Set</span>
                                        <?php else: ?>
                                            <span class="priority-badge <?php echo $priority_class; ?>">
                                                <?php echo ucfirst($priority); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-primary btn-small set-priority-btn" 
                                                    data-doc-id="<?php echo $doc['id']; ?>"
                                                    data-doc-type="<?php echo $doc['doc_type']; ?>"
                                                    data-doc-number="<?php echo htmlspecialchars($doc['doc_number']); ?>"
                                                    data-doc-title="<?php echo htmlspecialchars($doc['title']); ?>"
                                                    data-current-priority="<?php echo $priority; ?>">
                                                <i class="fas fa-flag"></i> Set Priority
                                            </button>
                                            <a href="classification.php?doc_id=<?php echo $doc['id']; ?>&doc_type=<?php echo $doc['doc_type']; ?>" 
                                               class="btn btn-secondary btn-small" title="View Classification">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- SET PRIORITY MODAL -->
    <div class="modal-overlay" id="setPriorityModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-flag"></i> Set Document Priority</h3>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <form id="priorityForm" method="POST">
                    <input type="hidden" name="action" value="update_priority">
                    <input type="hidden" name="document_id" id="modalDocId">
                    <input type="hidden" name="document_type" id="modalDocType">
                    
                    <div class="modal-section">
                        <div class="modal-doc-info">
                            <div style="margin-bottom: 10px;">
                                <strong>Document:</strong> <span id="modalDocNumber"></span>
                            </div>
                            <div>
                                <strong>Title:</strong> <span id="modalDocTitle"></span>
                            </div>
                            <div style="margin-top: 10px; font-size: 0.9rem; color: var(--gray);">
                                <strong>Current Priority:</strong> <span id="modalCurrentPriority"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-section">
                        <label class="modal-label required">Priority Level</label>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 15px;">
                            <label class="priority-option" data-priority="emergency">
                                <input type="radio" name="priority" value="emergency" class="priority-radio">
                                <div class="priority-indicator priority-emergency" style="width: 100%; justify-content: center;">
                                    <div class="priority-dot"></div>
                                    <span>Emergency</span>
                                </div>
                            </label>
                            <label class="priority-option" data-priority="urgent">
                                <input type="radio" name="priority" value="urgent" class="priority-radio">
                                <div class="priority-indicator priority-urgent" style="width: 100%; justify-content: center;">
                                    <div class="priority-dot"></div>
                                    <span>Urgent</span>
                                </div>
                            </label>
                            <label class="priority-option" data-priority="high">
                                <input type="radio" name="priority" value="high" class="priority-radio">
                                <div class="priority-indicator priority-high" style="width: 100%; justify-content: center;">
                                    <div class="priority-dot"></div>
                                    <span>High</span>
                                </div>
                            </label>
                            <label class="priority-option" data-priority="medium">
                                <input type="radio" name="priority" value="medium" class="priority-radio">
                                <div class="priority-indicator priority-medium" style="width: 100%; justify-content: center;">
                                    <div class="priority-dot"></div>
                                    <span>Medium</span>
                                </div>
                            </label>
                            <label class="priority-option" data-priority="low">
                                <input type="radio" name="priority" value="low" class="priority-radio">
                                <div class="priority-indicator priority-low" style="width: 100%; justify-content: center;">
                                    <div class="priority-dot"></div>
                                    <span>Low</span>
                                </div>
                            </label>
                        </div>
                        
                        <div class="tooltip" style="display: inline-block; margin-bottom: 10px;">
                            <i class="fas fa-info-circle" style="color: var(--qc-blue);"></i>
                            <span class="tooltip-text">
                                <strong>Priority Guidelines:</strong><br>
                                • Emergency: Life/safety issues, legal deadlines<br>
                                • Urgent: Time-sensitive matters<br>
                                • High: Important initiatives<br>
                                • Medium: Standard legislation<br>
                                • Low: Routine updates
                            </span>
                        </div>
                    </div>
                    
                    <div class="modal-section">
                        <label class="modal-label">Reason for Priority (Optional)</label>
                        <textarea name="reason" id="priorityReason" class="form-control" 
                                  rows="3" placeholder="Explain why this priority level was chosen..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="modalCancelBtn">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Priority</button>
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
                    <h3>Priority Setting Module</h3>
                    <p>
                        Set and manage urgency levels for ordinances and resolutions. 
                        Classify documents by priority to ensure proper resource allocation 
                        and timely processing of legislative matters.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Classification Tools</h3>
                <ul class="footer-links">
                    <li><a href="type_identification.php"><i class="fas fa-fingerprint"></i> Type Identification</a></li>
                    <li><a href="categorization.php"><i class="fas fa-folder"></i> Subject Categorization</a></li>
                    <li><a href="priority.php"><i class="fas fa-flag"></i> Priority Setting</a></li>
                    <li><a href="numbering.php"><i class="fas fa-hashtag"></i> Reference Numbering</a></li>
                    <li><a href="tagging.php"><i class="fas fa-tag"></i> Keyword Tagging</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Priority Guidelines</a></li>
                    <li><a href="#"><i class="fas fa-question-circle"></i> Help Center</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Technical Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Priority Setting Module - Classification & Organization System.</p>
            <p style="margin-top: 10px;">All priority classifications are logged for audit purposes.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Priority Setting Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
            </div>
        </div>
    </footer>

    <!-- Include Select2 for enhanced dropdowns -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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
        
        // Priority Setting Modal
        const setPriorityModal = document.getElementById('setPriorityModal');
        const modalClose = document.getElementById('modalClose');
        const modalCancelBtn = document.getElementById('modalCancelBtn');
        
        // Open modal when set priority button is clicked
        document.querySelectorAll('.set-priority-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const docId = this.getAttribute('data-doc-id');
                const docType = this.getAttribute('data-doc-type');
                const docNumber = this.getAttribute('data-doc-number');
                const docTitle = this.getAttribute('data-doc-title');
                const currentPriority = this.getAttribute('data-current-priority');
                
                // Set modal values
                document.getElementById('modalDocId').value = docId;
                document.getElementById('modalDocType').value = docType;
                document.getElementById('modalDocNumber').textContent = docNumber;
                document.getElementById('modalDocTitle').textContent = docTitle;
                
                // Set current priority display
                let currentPriorityText = currentPriority === 'unset' ? 'Not Set' : currentPriority.charAt(0).toUpperCase() + currentPriority.slice(1);
                document.getElementById('modalCurrentPriority').textContent = currentPriorityText;
                
                // Check current priority radio button
                document.querySelectorAll('.priority-radio').forEach(radio => {
                    radio.checked = radio.value === currentPriority;
                });
                
                // Highlight selected option
                document.querySelectorAll('.priority-option').forEach(option => {
                    option.classList.remove('selected');
                    const priority = option.getAttribute('data-priority');
                    if (priority === currentPriority) {
                        option.classList.add('selected');
                    }
                });
                
                // Open modal
                setPriorityModal.classList.add('active');
            });
        });
        
        // Priority option click handling
        document.querySelectorAll('.priority-option').forEach(option => {
            option.addEventListener('click', function() {
                const priority = this.getAttribute('data-priority');
                const radio = this.querySelector('.priority-radio');
                radio.checked = true;
                
                // Update selected styling
                document.querySelectorAll('.priority-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
            });
        });
        
        // Close modal
        modalClose.addEventListener('click', closeModal);
        modalCancelBtn.addEventListener('click', closeModal);
        setPriorityModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        function closeModal() {
            setPriorityModal.classList.remove('active');
            document.getElementById('priorityForm').reset();
        }
        
        // Form validation
        document.getElementById('priorityForm').addEventListener('submit', function(e) {
            const priority = document.querySelector('input[name="priority"]:checked');
            
            if (!priority) {
                e.preventDefault();
                alert('Please select a priority level.');
                return;
            }
            
            // Show confirmation for emergency priority
            if (priority.value === 'emergency') {
                if (!confirm('You are setting this document to EMERGENCY priority. This will place it at the top of all processing queues. Are you sure?')) {
                    e.preventDefault();
                    return;
                }
            }
            
            // Show confirmation for urgent priority
            if (priority.value === 'urgent') {
                if (!confirm('You are setting this document to URGENT priority. This will expedite its processing. Are you sure?')) {
                    e.preventDefault();
                    return;
                }
            }
        });
        
        // Auto-save form state
        let formState = {};
        
        function saveFormState() {
            formState = {
                type: document.querySelector('select[name="type"]').value,
                status: document.querySelector('select[name="status"]').value,
                priority: document.querySelector('select[name="priority"]').value,
                search: document.querySelector('input[name="search"]').value
            };
            localStorage.setItem('priorityFilterState', JSON.stringify(formState));
        }
        
        function loadFormState() {
            const savedState = localStorage.getItem('priorityFilterState');
            if (savedState) {
                formState = JSON.parse(savedState);
                document.querySelector('select[name="type"]').value = formState.type || 'all';
                document.querySelector('select[name="status"]').value = formState.status || 'all';
                document.querySelector('select[name="priority"]').value = formState.priority || 'all';
                document.querySelector('input[name="search"]').value = formState.search || '';
            }
        }
        
        // Save form state on change
        document.querySelectorAll('.filter-select, .search-input').forEach(input => {
            input.addEventListener('change', saveFormState);
            input.addEventListener('input', saveFormState);
        });
        
        // Load form state on page load
        window.addEventListener('load', loadFormState);
        
        // Initialize enhanced select elements
        $(document).ready(function() {
            $('.filter-select').select2({
                minimumResultsForSearch: 10,
                width: '100%'
            });
        });
        
        // Auto-refresh data every 5 minutes
        let refreshTimer;
        
        function startAutoRefresh() {
            refreshTimer = setInterval(() => {
                // Check if modal is open
                if (!setPriorityModal.classList.contains('active')) {
                    console.log('Auto-refreshing priority data...');
                    // In a real application, you would refresh the data via AJAX
                }
            }, 300000); // 5 minutes
        }
        
        function stopAutoRefresh() {
            clearInterval(refreshTimer);
        }
        
        // Start auto-refresh when page loads
        startAutoRefresh();
        
        // Stop auto-refresh when leaving page
        window.addEventListener('beforeunload', stopAutoRefresh);
        
        // Add animation to table rows
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
        
        // Observe table rows
        document.querySelectorAll('.documents-table tbody tr').forEach(row => {
            observer.observe(row);
        });
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('.search-input').focus();
            }
            
            // Escape to close modal
            if (e.key === 'Escape' && setPriorityModal.classList.contains('active')) {
                closeModal();
            }
            
            // Ctrl+R to refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }
        });
        
        // Add tooltip for priority levels
        document.querySelectorAll('.priority-badge').forEach(badge => {
            const priority = badge.textContent.toLowerCase();
            let tooltip = '';
            
            switch(priority) {
                case 'emergency':
                    tooltip = 'Requires immediate action. Life/safety issues, legal deadlines.';
                    break;
                case 'urgent':
                    tooltip = 'High attention needed. Time-sensitive matters.';
                    break;
                case 'high':
                    tooltip = 'Important initiatives. Schedule for early review.';
                    break;
                case 'medium':
                    tooltip = 'Standard processing. Regular legislative matters.';
                    break;
                case 'low':
                    tooltip = 'Routine matters. Process as capacity allows.';
                    break;
                case 'unset':
                    tooltip = 'Priority not yet assigned. Needs classification.';
                    break;
            }
            
            badge.title = tooltip;
        });
        
        // Initialize priority chart animation
        window.addEventListener('load', function() {
            const chartBars = document.querySelectorAll('.chart-bar-fill');
            chartBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 300);
            });
        });
    </script>
</body>
</html>