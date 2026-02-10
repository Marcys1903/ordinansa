<?php
// action_history.php - Action History Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission
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

// Filter parameters
$document_type = $_GET['document_type'] ?? 'all';
$action_type = $_GET['action_type'] ?? 'all';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$document_id = $_GET['document_id'] ?? '';
$user_filter = $_GET['user_filter'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query for action history
$where_conditions = [];
$params = [];
$types = [];

// Base query - combine multiple history tables
$base_query = "
    -- Document Status History
    SELECT 
        'status_change' as action_type,
        sh.id as action_id,
        sh.document_id,
        sh.document_type,
        CONCAT('Status changed from ', sh.old_status, ' to ', sh.new_status) as action_description,
        sh.notes as details,
        sh.changed_by as user_id,
        u.first_name,
        u.last_name,
        u.role,
        sh.changed_at as action_date,
        NULL as ip_address,
        NULL as user_agent,
        'status_history' as source_table,
        NULL as reference_number,
        NULL as ordinance_number,
        NULL as resolution_number
    FROM status_history sh
    LEFT JOIN users u ON sh.changed_by = u.id
    
    UNION ALL
    
    -- Document Priority History
    SELECT 
        'priority_change' as action_type,
        dph.id as action_id,
        dph.document_id,
        dph.document_type,
        CONCAT('Priority changed from ', 
               COALESCE(dph.previous_priority, 'not set'), 
               ' to ', dph.new_priority) as action_description,
        dph.reason as details,
        dph.changed_by as user_id,
        u.first_name,
        u.last_name,
        u.role,
        dph.changed_at as action_date,
        NULL as ip_address,
        NULL as user_agent,
        'document_priority_history' as source_table,
        NULL as reference_number,
        NULL as ordinance_number,
        NULL as resolution_number
    FROM document_priority_history dph
    LEFT JOIN users u ON dph.changed_by = u.id
    
    UNION ALL
    
    -- Document Numbering Logs
    SELECT 
        'numbering_change' as action_type,
        dnl.id as action_id,
        dnl.document_id,
        dnl.document_type,
        CONCAT('Document number changed from ', 
               COALESCE(dnl.old_number, 'not set'), 
               ' to ', dnl.new_number) as action_description,
        dnl.reason as details,
        dnl.changed_by as user_id,
        u.first_name,
        u.last_name,
        u.role,
        dnl.changed_at as action_date,
        NULL as ip_address,
        NULL as user_agent,
        'document_numbering_logs' as source_table,
        dnl.new_number as reference_number,
        NULL as ordinance_number,
        NULL as resolution_number
    FROM document_numbering_logs dnl
    LEFT JOIN users u ON dnl.changed_by = u.id
    
    UNION ALL
    
    -- Tagging History
    SELECT 
        'tagging_change' as action_type,
        th.id as action_id,
        th.document_id,
        th.document_type,
        CONCAT(th.action_type, ' keyword: ', th.keyword_text) as action_description,
        th.notes as details,
        th.performed_by as user_id,
        u.first_name,
        u.last_name,
        u.role,
        th.performed_at as action_date,
        NULL as ip_address,
        NULL as user_agent,
        'tagging_history' as source_table,
        NULL as reference_number,
        NULL as ordinance_number,
        NULL as resolution_number
    FROM tagging_history th
    LEFT JOIN users u ON th.performed_by = u.id
    
    UNION ALL
    
    -- Audit Logs (System actions)
    SELECT 
        LOWER(al.action) as action_type,
        al.id as action_id,
        NULL as document_id,
        NULL as document_type,
        al.description as action_description,
        NULL as details,
        al.user_id,
        u.first_name,
        u.last_name,
        u.role,
        al.created_at as action_date,
        al.ip_address,
        al.user_agent,
        'audit_logs' as source_table,
        NULL as reference_number,
        NULL as ordinance_number,
        NULL as resolution_number
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.action NOT IN ('LOGIN', 'FAILED_LOGIN')  -- Exclude login logs for cleaner view
    
    UNION ALL
    
    -- Document Classification Actions
    SELECT 
        'classification' as action_type,
        dc.id as action_id,
        dc.document_id,
        dc.document_type,
        CONCAT('Document classified as ', dc.classification_type) as action_description,
        dc.classification_notes as details,
        dc.classified_by as user_id,
        u.first_name,
        u.last_name,
        u.role,
        dc.classified_at as action_date,
        NULL as ip_address,
        NULL as user_agent,
        'document_classification' as source_table,
        dc.reference_number,
        NULL as ordinance_number,
        NULL as resolution_number
    FROM document_classification dc
    LEFT JOIN users u ON dc.classified_by = u.id
    WHERE dc.classified_at IS NOT NULL
    
    UNION ALL
    
    -- Draft Registrations
    SELECT 
        'registration' as action_type,
        dr.id as action_id,
        dr.document_id,
        dr.document_type,
        CONCAT('Draft registered with number: ', dr.registration_number) as action_description,
        dr.registration_notes as details,
        dr.registered_by as user_id,
        u.first_name,
        u.last_name,
        u.role,
        dr.created_at as action_date,
        NULL as ip_address,
        NULL as user_agent,
        'draft_registrations' as source_table,
        dr.registration_number as reference_number,
        NULL as ordinance_number,
        NULL as resolution_number
    FROM draft_registrations dr
    LEFT JOIN users u ON dr.registered_by = u.id
    
    UNION ALL
    
    -- Committee Assignments
    SELECT 
        'committee_assignment' as action_type,
        dc.id as action_id,
        dc.document_id,
        dc.document_type,
        CONCAT('Assigned to committee: ', c.committee_name) as action_description,
        dc.comments as details,
        dc.assigned_by as user_id,
        u.first_name,
        u.last_name,
        u.role,
        dc.assigned_at as action_date,
        NULL as ip_address,
        NULL as user_agent,
        'document_committees' as source_table,
        NULL as reference_number,
        NULL as ordinance_number,
        NULL as resolution_number
    FROM document_committees dc
    LEFT JOIN users u ON dc.assigned_by = u.id
    LEFT JOIN committees c ON dc.committee_id = c.id
    WHERE dc.assigned_at IS NOT NULL
";

// Apply filters
if ($document_type !== 'all') {
    $where_conditions[] = "document_type = :document_type";
    $params[':document_type'] = $document_type;
    $types[':document_type'] = PDO::PARAM_STR;
}

if ($action_type !== 'all') {
    $where_conditions[] = "action_type = :action_type";
    $params[':action_type'] = $action_type;
    $types[':action_type'] = PDO::PARAM_STR;
}

if (!empty($document_id)) {
    $where_conditions[] = "document_id = :document_id";
    $params[':document_id'] = $document_id;
    $types[':document_id'] = PDO::PARAM_INT;
}

if (!empty($user_filter)) {
    $where_conditions[] = "user_id = :user_filter";
    $params[':user_filter'] = $user_filter;
    $types[':user_filter'] = PDO::PARAM_INT;
}

// Date range filter
if (!empty($start_date) && !empty($end_date)) {
    $where_conditions[] = "DATE(action_date) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
    $types[':start_date'] = PDO::PARAM_STR;
    $types[':end_date'] = PDO::PARAM_STR;
}

// Build final query with filters
$query = "SELECT * FROM ($base_query) as combined_history";
if (!empty($where_conditions)) {
    $query .= " WHERE " . implode(" AND ", $where_conditions);
}
$query .= " ORDER BY action_date DESC";

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM ($query) as filtered";
$count_stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value, $types[$key] ?? PDO::PARAM_STR);
}
$count_stmt->execute();
$total_result = $count_stmt->fetch();
$total_records = $total_result['total'];
$total_pages = ceil($total_records / $per_page);

// Add pagination to main query
$query .= " LIMIT :limit OFFSET :offset";

// Execute main query
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, $types[$key] ?? PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$action_history = $stmt->fetchAll();

// Get available users for filter
$users_query = "SELECT id, CONCAT(first_name, ' ', last_name) as name, role FROM users WHERE is_active = 1 ORDER BY last_name";
$users_stmt = $conn->query($users_query);
$available_users = $users_stmt->fetchAll();

// Get action types for filter
$action_types_query = "
    SELECT DISTINCT action_type FROM (
        SELECT 'status_change' as action_type
        UNION SELECT 'priority_change'
        UNION SELECT 'numbering_change'
        UNION SELECT 'tagging_change'
        UNION SELECT 'classification'
        UNION SELECT 'registration'
        UNION SELECT 'committee_assignment'
        UNION SELECT 'draft_create'
        UNION SELECT 'template_use'
        UNION SELECT 'template_create'
        UNION SELECT 'document_upload'
        UNION SELECT 'draft_register'
    ) as types
    ORDER BY action_type
";
$action_types_stmt = $conn->query($action_types_query);
$action_types = $action_types_stmt->fetchAll();

// Get recent documents for quick filter
$recent_docs_query = "
    (SELECT id, ordinance_number as doc_number, title, 'ordinance' as doc_type FROM ordinances ORDER BY created_at DESC LIMIT 10)
    UNION ALL
    (SELECT id, resolution_number as doc_number, title, 'resolution' as doc_type FROM resolutions ORDER BY created_at DESC LIMIT 10)
    ORDER BY doc_number DESC
";
$recent_docs_stmt = $conn->query($recent_docs_query);
$recent_documents = $recent_docs_stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_actions,
        COUNT(DISTINCT document_id) as unique_documents,
        COUNT(DISTINCT user_id) as unique_users,
        MIN(action_date) as earliest_action,
        MAX(action_date) as latest_action
    FROM ($base_query) as history
    WHERE action_date BETWEEN :stats_start_date AND :stats_end_date
";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindValue(':stats_start_date', $start_date . ' 00:00:00');
$stats_stmt->bindValue(':stats_end_date', $end_date . ' 23:59:59');
$stats_stmt->execute();
$statistics = $stats_stmt->fetch();

// Get top action types
$top_actions_query = "
    SELECT action_type, COUNT(*) as count 
    FROM ($base_query) as history
    WHERE action_date BETWEEN :top_start_date AND :top_end_date
    GROUP BY action_type 
    ORDER BY count DESC 
    LIMIT 5
";
$top_actions_stmt = $conn->prepare($top_actions_query);
$top_actions_stmt->bindValue(':top_start_date', $start_date . ' 00:00:00');
$top_actions_stmt->bindValue(':top_end_date', $end_date . ' 23:59:59');
$top_actions_stmt->execute();
$top_actions = $top_actions_stmt->fetchAll();

// Get top users
$top_users_query = "
    SELECT 
        CONCAT(u.first_name, ' ', u.last_name) as user_name,
        u.role,
        COUNT(*) as action_count
    FROM ($base_query) as h
    LEFT JOIN users u ON h.user_id = u.id
    WHERE h.action_date BETWEEN :users_start_date AND :users_end_date
    GROUP BY h.user_id, u.first_name, u.last_name, u.role
    ORDER BY action_count DESC
    LIMIT 5
";
$top_users_stmt = $conn->prepare($top_users_query);
$top_users_stmt->bindValue(':users_start_date', $start_date . ' 00:00:00');
$top_users_stmt->bindValue(':users_end_date', $end_date . ' 23:59:59');
$top_users_stmt->execute();
$top_users = $top_users_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action History | QC Ordinance Tracker</title>
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
        
        /* FILTER PANEL */
        .filter-panel {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            margin-bottom: 30px;
            border: 1px solid var(--gray-light);
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .filter-header h2 {
            color: var(--qc-blue);
            font-size: 1.5rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .filter-header i {
            color: var(--qc-gold);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-light);
        }
        
        /* ACTION HISTORY TABLE */
        .history-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            margin-bottom: 40px;
            border: 1px solid var(--gray-light);
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .table-header h2 {
            color: var(--qc-blue);
            font-size: 1.5rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .table-header i {
            color: var(--qc-gold);
        }
        
        .action-history-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .action-history-table th {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 3px solid var(--qc-gold);
        }
        
        .action-history-table td {
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            vertical-align: top;
        }
        
        .action-history-table tr:hover {
            background: var(--off-white);
        }
        
        .action-history-table tr:nth-child(even) {
            background: rgba(0, 51, 102, 0.02);
        }
        
        .action-history-table tr:nth-child(even):hover {
            background: rgba(0, 51, 102, 0.05);
        }
        
        .action-type {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .action-status-change { background: #dbeafe; color: #1e40af; }
        .action-priority-change { background: #fef3c7; color: #92400e; }
        .action-numbering-change { background: #d1fae5; color: #065f46; }
        .action-tagging-change { background: #e0e7ff; color: #3730a3; }
        .action-classification { background: #fce7f3; color: #9d174d; }
        .action-registration { background: #dcfce7; color: #166534; }
        .action-committee_assignment { background: #fef9c3; color: #854d0e; }
        .action-draft_create { background: #dbeafe; color: #1e40af; }
        .action-template_use { background: #f3e8ff; color: #6b21a8; }
        .action-template_create { background: #ffe4e6; color: #9f1239; }
        .action-document_upload { background: #f0f9ff; color: #0369a1; }
        .action-draft_register { background: #f0fdf4; color: #166534; }
        .action-login { background: #fefce8; color: #854d0e; }
        
        .document-link {
            color: var(--qc-blue);
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .document-link:hover {
            color: var(--qc-gold);
            text-decoration: underline;
        }
        
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--off-white);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        .user-avatar-small {
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: var(--qc-blue);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .action-details {
            max-width: 300px;
            word-wrap: break-word;
        }
        
        .action-meta {
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        .source-badge {
            display: inline-block;
            padding: 3px 8px;
            background: var(--gray-light);
            color: var(--gray-dark);
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* PAGINATION */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--gray-dark);
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .pagination a:hover {
            background: var(--qc-blue);
            color: var(--white);
            border-color: var(--qc-blue);
        }
        
        .pagination .current {
            background: var(--qc-blue);
            color: var(--white);
            border-color: var(--qc-blue);
        }
        
        /* STATISTICS PANELS */
        .statistics-panels {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .stat-panel {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .stat-panel-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .stat-panel-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 1.5rem;
        }
        
        .stat-panel-title {
            flex: 1;
        }
        
        .stat-panel-title h3 {
            color: var(--qc-blue);
            font-size: 1.3rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-panel-title p {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .stat-list {
            list-style: none;
        }
        
        .stat-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .stat-list li:last-child {
            border-bottom: none;
        }
        
        .stat-label {
            color: var(--gray-dark);
            font-weight: 500;
        }
        
        .stat-value {
            font-weight: bold;
            color: var(--qc-blue);
        }
        
        /* ACTION DETAILS MODAL */
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
        
        .modal-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .modal-detail-item {
            margin-bottom: 15px;
        }
        
        .modal-detail-label {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .modal-detail-value {
            color: var(--qc-blue);
            font-size: 1rem;
            word-wrap: break-word;
        }
        
        /* BUTTONS */
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
        
        .btn-small {
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
            
            .action-history-table {
                display: block;
                overflow-x: auto;
            }
            
            .statistics-panels {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .pagination {
                flex-wrap: wrap;
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
        
        /* Export buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-export {
            padding: 8px 15px;
            background: var(--gray-light);
            color: var(--gray-dark);
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-export:hover {
            background: var(--qc-blue);
            color: var(--white);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            color: var(--gray-dark);
            margin-bottom: 10px;
            font-size: 1.5rem;
        }
        
        .empty-state p {
            max-width: 500px;
            margin: 0 auto 20px;
            line-height: 1.6;
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
                    <p>Action History Module | Complete Activity Log</p>
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
                        <i class="fas fa-history"></i> Action History
                    </h3>
                    <p class="sidebar-subtitle">Complete Activity Tracking</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="action-history-container">
                <!-- MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">ACTION HISTORY MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <div class="module-title">
                                <h1>Complete Action History</h1>
                                <p class="module-subtitle">
                                    Track all activities and changes made to ordinances and resolutions. 
                                    View status updates, priority changes, document numbering, tagging activities, and system actions.
                                </p>
                            </div>
                        </div>
                        
                        <div class="module-stats">
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-list-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo number_format($total_records); ?></h3>
                                    <p>Total Actions</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-file-contract"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo number_format($statistics['unique_documents']); ?></h3>
                                    <p>Documents Tracked</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo number_format($statistics['unique_users']); ?></h3>
                                    <p>Active Users</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo date('M d, Y', strtotime($statistics['earliest_action'] ?? date('Y-m-d'))); ?></h3>
                                    <p>Since Date</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STATISTICS PANELS -->
                <div class="statistics-panels fade-in">
                    <div class="stat-panel">
                        <div class="stat-panel-header">
                            <div class="stat-panel-icon">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <div class="stat-panel-title">
                                <h3>Action Type Distribution</h3>
                                <p>Most frequent activities</p>
                            </div>
                        </div>
                        <ul class="stat-list">
                            <?php foreach ($top_actions as $action): ?>
                            <li>
                                <span class="stat-label"><?php echo ucwords(str_replace('_', ' ', $action['action_type'])); ?></span>
                                <span class="stat-value"><?php echo number_format($action['count']); ?></span>
                            </li>
                            <?php endforeach; ?>
                            <?php if (empty($top_actions)): ?>
                            <li>
                                <span class="stat-label">No actions found</span>
                                <span class="stat-value">0</span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <div class="stat-panel">
                        <div class="stat-panel-header">
                            <div class="stat-panel-icon">
                                <i class="fas fa-user-chart"></i>
                            </div>
                            <div class="stat-panel-title">
                                <h3>Top Active Users</h3>
                                <p>Most active participants</p>
                            </div>
                        </div>
                        <ul class="stat-list">
                            <?php foreach ($top_users as $top_user): ?>
                            <li>
                                <span class="stat-label"><?php echo htmlspecialchars($top_user['user_name']); ?></span>
                                <span class="stat-value"><?php echo number_format($top_user['action_count']); ?></span>
                            </li>
                            <?php endforeach; ?>
                            <?php if (empty($top_users)): ?>
                            <li>
                                <span class="stat-label">No user data</span>
                                <span class="stat-value">0</span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <div class="stat-panel">
                        <div class="stat-panel-header">
                            <div class="stat-panel-icon">
                                <i class="fas fa-filter"></i>
                            </div>
                            <div class="stat-panel-title">
                                <h3>Current Filters</h3>
                                <p>Active search criteria</p>
                            </div>
                        </div>
                        <ul class="stat-list">
                            <li>
                                <span class="stat-label">Date Range</span>
                                <span class="stat-value"><?php echo date('M d, Y', strtotime($start_date)) . ' - ' . date('M d, Y', strtotime($end_date)); ?></span>
                            </li>
                            <li>
                                <span class="stat-label">Document Type</span>
                                <span class="stat-value"><?php echo $document_type === 'all' ? 'All' : ucfirst($document_type); ?></span>
                            </li>
                            <li>
                                <span class="stat-label">Action Type</span>
                                <span class="stat-value"><?php echo $action_type === 'all' ? 'All' : ucwords(str_replace('_', ' ', $action_type)); ?></span>
                            </li>
                            <li>
                                <span class="stat-label">Document ID</span>
                                <span class="stat-value"><?php echo $document_id ?: 'Any'; ?></span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- FILTER PANEL -->
                <div class="filter-panel fade-in">
                    <div class="filter-header">
                        <h2><i class="fas fa-filter"></i> Filter Actions</h2>
                        <div class="export-buttons">
                            <button class="btn-export" onclick="exportToCSV()">
                                <i class="fas fa-file-csv"></i> CSV
                            </button>
                            <button class="btn-export" onclick="exportToPDF()">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                            <button class="btn-export" onclick="printHistory()">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>
                    
                    <form method="GET" action="" id="filterForm">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label class="filter-label">Date Range</label>
                                <div style="display: flex; gap: 10px;">
                                    <input type="date" name="start_date" class="filter-control" 
                                           value="<?php echo htmlspecialchars($start_date); ?>">
                                    <input type="date" name="end_date" class="filter-control" 
                                           value="<?php echo htmlspecialchars($end_date); ?>">
                                </div>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Document Type</label>
                                <select name="document_type" class="filter-control">
                                    <option value="all" <?php echo $document_type === 'all' ? 'selected' : ''; ?>>All Documents</option>
                                    <option value="ordinance" <?php echo $document_type === 'ordinance' ? 'selected' : ''; ?>>Ordinances Only</option>
                                    <option value="resolution" <?php echo $document_type === 'resolution' ? 'selected' : ''; ?>>Resolutions Only</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Action Type</label>
                                <select name="action_type" class="filter-control">
                                    <option value="all" <?php echo $action_type === 'all' ? 'selected' : ''; ?>>All Actions</option>
                                    <?php foreach ($action_types as $type): ?>
                                    <option value="<?php echo $type['action_type']; ?>" 
                                            <?php echo $action_type === $type['action_type'] ? 'selected' : ''; ?>>
                                        <?php echo ucwords(str_replace('_', ' ', $type['action_type'])); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Document ID</label>
                                <input type="text" name="document_id" class="filter-control" 
                                       value="<?php echo htmlspecialchars($document_id); ?>" 
                                       placeholder="Enter document ID">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">User</label>
                                <select name="user_filter" class="filter-control">
                                    <option value="">All Users</option>
                                    <?php foreach ($available_users as $available_user): ?>
                                    <option value="<?php echo $available_user['id']; ?>" 
                                            <?php echo $user_filter == $available_user['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($available_user['name'] . ' (' . ucfirst($available_user['role']) . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Recent Documents</label>
                                <select name="recent_doc" class="filter-control" onchange="if(this.value) document.getElementById('filterForm').document_id.value=this.value; this.value='';">
                                    <option value="">Select a document...</option>
                                    <?php foreach ($recent_documents as $recent_doc): ?>
                                    <option value="<?php echo $recent_doc['id']; ?>">
                                        <?php echo htmlspecialchars($recent_doc['doc_number'] . ' - ' . $recent_doc['title']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                <i class="fas fa-undo"></i> Reset Filters
                            </button>
                            <a href="action_history.php" class="btn btn-secondary">
                                <i class="fas fa-sync"></i> Clear All
                            </a>
                        </div>
                    </form>
                </div>

                <!-- ACTION HISTORY TABLE -->
                <div class="history-container fade-in">
                    <div class="table-header">
                        <h2><i class="fas fa-list-ul"></i> Action History Log</h2>
                        <div style="font-size: 0.9rem; color: var(--gray);">
                            Showing <?php echo number_format(min($per_page, count($action_history))); ?> of <?php echo number_format($total_records); ?> actions
                        </div>
                    </div>
                    
                    <?php if (empty($action_history)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No Actions Found</h3>
                        <p>No actions match your current filter criteria. Try adjusting your filters or select a different date range.</p>
                        <button class="btn btn-primary" onclick="resetFilters()">
                            <i class="fas fa-undo"></i> Reset Filters
                        </button>
                    </div>
                    <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="action-history-table">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Action Type</th>
                                    <th>Description</th>
                                    <th>Document</th>
                                    <th>User</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($action_history as $action): 
                                    $action_class = 'action-' . str_replace(' ', '_', strtolower($action['action_type']));
                                ?>
                                <tr onclick="showActionDetails(<?php echo htmlspecialchars(json_encode($action)); ?>)" style="cursor: pointer;">
                                    <td>
                                        <div style="font-weight: 600;"><?php echo date('M d, Y', strtotime($action['action_date'])); ?></div>
                                        <div style="font-size: 0.85rem; color: var(--gray);"><?php echo date('h:i A', strtotime($action['action_date'])); ?></div>
                                    </td>
                                    <td>
                                        <span class="action-type <?php echo $action_class; ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $action['action_type'])); ?>
                                        </span>
                                    </td>
                                    <td class="action-details">
                                        <div style="font-weight: 500; margin-bottom: 5px;"><?php echo htmlspecialchars($action['action_description']); ?></div>
                                        <?php if ($action['details']): ?>
                                        <div style="font-size: 0.85rem; color: var(--gray);"><?php echo htmlspecialchars(substr($action['details'], 0, 100)); ?><?php echo strlen($action['details']) > 100 ? '...' : ''; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($action['document_id']): ?>
                                        <a href="#" class="document-link" onclick="event.stopPropagation(); viewDocument(<?php echo $action['document_id']; ?>, '<?php echo $action['document_type']; ?>');">
                                            <i class="fas fa-external-link-alt"></i>
                                            <?php echo $action['document_type'] === 'ordinance' ? 'Ordinance' : 'Resolution'; ?> #<?php echo $action['document_id']; ?>
                                        </a>
                                        <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">System Action</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($action['user_id']): ?>
                                        <div class="user-badge">
                                            <div class="user-avatar-small">
                                                <?php echo strtoupper(substr($action['first_name'], 0, 1) . substr($action['last_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($action['first_name'] . ' ' . $action['last_name']); ?></div>
                                                <div style="font-size: 0.8rem; color: var(--gray); text-transform: capitalize;"><?php echo str_replace('_', ' ', $action['role']); ?></div>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic;">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-meta">
                                            <div style="margin-bottom: 5px;">
                                                <span class="source-badge"><?php echo $action['source_table']; ?></span>
                                            </div>
                                            <?php if ($action['reference_number']): ?>
                                            <div>Ref: <?php echo htmlspecialchars($action['reference_number']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($action['ip_address']): ?>
                                            <div>IP: <?php echo htmlspecialchars($action['ip_address']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- PAGINATION -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">First</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Prev</a>
                        <?php endif; ?>
                        
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                        <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">Last</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- ACTION DETAILS MODAL -->
    <div class="modal-overlay" id="actionDetailsModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-info-circle"></i> Action Details</h2>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-bullseye"></i>
                        Action Information
                    </h3>
                    <div id="modalActionInfo">
                        <!-- Action info will be loaded here -->
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-user-tag"></i>
                        User Information
                    </h3>
                    <div id="modalUserInfo">
                        <!-- User info will be loaded here -->
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-file-contract"></i>
                        Document Information
                    </h3>
                    <div id="modalDocumentInfo">
                        <!-- Document info will be loaded here -->
                    </div>
                </div>
                
                <div class="modal-section">
                    <h3 class="modal-section-title">
                        <i class="fas fa-network-wired"></i>
                        System Information
                    </h3>
                    <div id="modalSystemInfo">
                        <!-- System info will be loaded here -->
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="modalCancelBtn">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button type="button" class="btn btn-primary" id="modalExportBtn">
                        <i class="fas fa-download"></i> Export Details
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
                    <h3>Action History Module</h3>
                    <p>
                        Complete tracking of all activities and changes made to ordinances and resolutions. 
                        Monitor status updates, priority changes, document numbering, tagging activities, and system actions.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="tracking.php"><i class="fas fa-chart-line"></i> Tracking Dashboard</a></li>
                    <li><a href="status_updates.php"><i class="fas fa-sync-alt"></i> Status Updates</a></li>
                    <li><a href="timeline.php"><i class="fas fa-stream"></i> Timeline Tracking</a></li>
                    <li><a href="progress_reports.php"><i class="fas fa-chart-pie"></i> Progress Summary</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Module Features</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-filter"></i> Advanced Filtering</a></li>
                    <li><a href="#"><i class="fas fa-chart-bar"></i> Statistics & Analytics</a></li>
                    <li><a href="#"><i class="fas fa-download"></i> Export Capabilities</a></li>
                    <li><a href="#"><i class="fas fa-search"></i> Detailed Search</a></li>
                    <li><a href="#"><i class="fas fa-bell"></i> Activity Alerts</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Action History Module - Complete Activity Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All actions are logged for audit purposes.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Action History Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
        
        // Action Details Modal
        const actionDetailsModal = document.getElementById('actionDetailsModal');
        const modalClose = document.getElementById('modalClose');
        const modalCancelBtn = document.getElementById('modalCancelBtn');
        
        function showActionDetails(action) {
            // Format action type
            const actionType = action.action_type.replace(/_/g, ' ');
            const actionTypeFormatted = actionType.charAt(0).toUpperCase() + actionType.slice(1);
            
            // Format date
            const actionDate = new Date(action.action_date);
            const formattedDate = actionDate.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            // Action Information
            let actionInfoHtml = `
                <div class="modal-details-grid">
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Action Type</div>
                        <div class="modal-detail-value">
                            <span class="action-type action-${action.action_type}">
                                ${actionTypeFormatted}
                            </span>
                        </div>
                    </div>
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Date & Time</div>
                        <div class="modal-detail-value">${formattedDate}</div>
                    </div>
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Description</div>
                        <div class="modal-detail-value">${action.action_description || 'No description'}</div>
                    </div>
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Details</div>
                        <div class="modal-detail-value">${action.details || 'No additional details'}</div>
                    </div>
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Source Table</div>
                        <div class="modal-detail-value">
                            <span class="source-badge">${action.source_table}</span>
                        </div>
                    </div>
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">Action ID</div>
                        <div class="modal-detail-value">${action.action_id}</div>
                    </div>
                </div>
            `;
            
            // User Information
            let userInfoHtml = '';
            if (action.user_id) {
                const userInitials = (action.first_name ? action.first_name.charAt(0) : '') + (action.last_name ? action.last_name.charAt(0) : '');
                userInfoHtml = `
                    <div class="modal-details-grid">
                        <div class="modal-detail-item">
                            <div class="modal-detail-label">User Name</div>
                            <div class="modal-detail-value">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="user-avatar-small">${userInitials.toUpperCase()}</div>
                                    <div>${action.first_name} ${action.last_name}</div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-detail-item">
                            <div class="modal-detail-label">User Role</div>
                            <div class="modal-detail-value" style="text-transform: capitalize;">${(action.role || '').replace(/_/g, ' ')}</div>
                        </div>
                        <div class="modal-detail-item">
                            <div class="modal-detail-label">User ID</div>
                            <div class="modal-detail-value">${action.user_id}</div>
                        </div>
                    </div>
                `;
            } else {
                userInfoHtml = `
                    <div style="color: var(--gray); font-style: italic; text-align: center; padding: 20px;">
                        <i class="fas fa-robot" style="font-size: 3rem; margin-bottom: 15px;"></i>
                        <div>System-generated action (no user involved)</div>
                    </div>
                `;
            }
            
            // Document Information
            let documentInfoHtml = '';
            if (action.document_id) {
                documentInfoHtml = `
                    <div class="modal-details-grid">
                        <div class="modal-detail-item">
                            <div class="modal-detail-label">Document Type</div>
                            <div class="modal-detail-value">${action.document_type === 'ordinance' ? 'Ordinance' : 'Resolution'}</div>
                        </div>
                        <div class="modal-detail-item">
                            <div class="modal-detail-label">Document ID</div>
                            <div class="modal-detail-value">${action.document_id}</div>
                        </div>
                        ${action.reference_number ? `
                        <div class="modal-detail-item">
                            <div class="modal-detail-label">Reference Number</div>
                            <div class="modal-detail-value">${action.reference_number}</div>
                        </div>
                        ` : ''}
                    </div>
                    <div style="margin-top: 20px;">
                        <button class="btn btn-primary" onclick="viewDocument(${action.document_id}, '${action.document_type}')">
                            <i class="fas fa-external-link-alt"></i> View Document
                        </button>
                    </div>
                `;
            } else {
                documentInfoHtml = `
                    <div style="color: var(--gray); font-style: italic; text-align: center; padding: 20px;">
                        <i class="fas fa-cogs" style="font-size: 3rem; margin-bottom: 15px;"></i>
                        <div>System action (not related to a specific document)</div>
                    </div>
                `;
            }
            
            // System Information
            let systemInfoHtml = `
                <div class="modal-details-grid">
                    ${action.ip_address ? `
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">IP Address</div>
                        <div class="modal-detail-value">${action.ip_address}</div>
                    </div>
                    ` : ''}
                    ${action.user_agent ? `
                    <div class="modal-detail-item">
                        <div class="modal-detail-label">User Agent</div>
                        <div class="modal-detail-value" style="font-size: 0.9rem;">${action.user_agent}</div>
                    </div>
                    ` : ''}
                </div>
            `;
            
            // Update modal content
            document.getElementById('modalActionInfo').innerHTML = actionInfoHtml;
            document.getElementById('modalUserInfo').innerHTML = userInfoHtml;
            document.getElementById('modalDocumentInfo').innerHTML = documentInfoHtml;
            document.getElementById('modalSystemInfo').innerHTML = systemInfoHtml;
            
            // Show modal
            actionDetailsModal.classList.add('active');
        }
        
        // Close modal
        modalClose.addEventListener('click', closeModal);
        modalCancelBtn.addEventListener('click', closeModal);
        actionDetailsModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        function closeModal() {
            actionDetailsModal.classList.remove('active');
        }
        
        // Export action details
        document.getElementById('modalExportBtn').addEventListener('click', function() {
            const actionInfo = document.getElementById('modalActionInfo').innerText;
            const userInfo = document.getElementById('modalUserInfo').innerText;
            const documentInfo = document.getElementById('modalDocumentInfo').innerText;
            const systemInfo = document.getElementById('modalSystemInfo').innerText;
            
            const content = `
                ACTION DETAILS EXPORT
                =====================
                
                ACTION INFORMATION:
                ${actionInfo}
                
                USER INFORMATION:
                ${userInfo}
                
                DOCUMENT INFORMATION:
                ${documentInfo}
                
                SYSTEM INFORMATION:
                ${systemInfo}
                
                Exported on: ${new Date().toLocaleString()}
                Exported by: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
            `;
            
            const blob = new Blob([content], { type: 'text/plain' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `action-details-${Date.now()}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        });
        
        // View document function
        function viewDocument(documentId, documentType) {
            if (documentType === 'ordinance') {
                window.location.href = `view_ordinance.php?id=${documentId}`;
            } else if (documentType === 'resolution') {
                window.location.href = `view_resolution.php?id=${documentId}`;
            } else {
                alert('Document type not recognized.');
            }
        }
        
        // Filter functions
        function resetFilters() {
            document.getElementById('filterForm').reset();
            window.location.href = 'action_history.php';
        }
        
        function exportToCSV() {
            alert('CSV export feature coming soon!');
            // In a real implementation, this would generate and download a CSV file
        }
        
        function exportToPDF() {
            alert('PDF export feature coming soon!');
            // In a real implementation, this would generate and download a PDF file
        }
        
        function printHistory() {
            window.print();
        }
        
        // Auto-refresh every 5 minutes to show new actions
        let autoRefreshTimer;
        function startAutoRefresh() {
            autoRefreshTimer = setInterval(() => {
                const currentPage = <?php echo $page; ?>;
                if (currentPage === 1) {
                    // Only auto-refresh if on first page
                    location.reload();
                }
            }, 300000); // 5 minutes
        }
        
        // Start auto-refresh
        startAutoRefresh();
        
        // Stop auto-refresh when page is not visible
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearInterval(autoRefreshTimer);
            } else {
                startAutoRefresh();
            }
        });
        
        // Add animation to table rows
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('.action-history-table tbody tr');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';
                row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                
                setTimeout(() => {
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 50);
            });
            
            // Initialize date pickers with max date today
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                input.max = today;
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus on filter
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="document_id"]').focus();
            }
            
            // Esc to close modal
            if (e.key === 'Escape') {
                closeModal();
            }
            
            // Ctrl+P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printHistory();
            }
        });
    </script>
</body>
</html>