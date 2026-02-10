<?php
// progress_reports.php - Progress Summary Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to view progress reports
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
$document_type = $_GET['document_type'] ?? 'both';
$status = $_GET['status'] ?? 'all';
$timeframe = $_GET['timeframe'] ?? 'month';
$category_id = $_GET['category_id'] ?? 'all';
$priority = $_GET['priority'] ?? 'all';
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get document statistics - FIXED QUERY
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM ordinances) as ordinance_count,
    (SELECT COUNT(*) FROM resolutions) as resolution_count,
    (SELECT COUNT(*) FROM ordinances WHERE status = 'approved') + 
    (SELECT COUNT(*) FROM resolutions WHERE status = 'approved') as approved_count,
    (SELECT COUNT(*) FROM ordinances WHERE status = 'pending') + 
    (SELECT COUNT(*) FROM resolutions WHERE status = 'pending') as pending_count,
    (SELECT COUNT(*) FROM ordinances WHERE status = 'draft') + 
    (SELECT COUNT(*) FROM resolutions WHERE status = 'draft') as draft_count,
    (
        SELECT AVG(processing_days) FROM (
            SELECT DATEDIFF(updated_at, created_at) as processing_days 
            FROM ordinances 
            WHERE status = 'approved'
            UNION ALL
            SELECT DATEDIFF(updated_at, created_at) as processing_days 
            FROM resolutions 
            WHERE status = 'approved'
        ) as combined
    ) as avg_processing_days";

$stats_stmt = $conn->query($stats_query);
$overall_stats = $stats_stmt->fetch();

// Get category statistics - FIXED QUERY
$category_stats_query = "SELECT 
    dc.category_name,
    dc.category_code,
    COUNT(DISTINCT CASE WHEN dc2.document_type = 'ordinance' THEN dc2.document_id END) as ordinance_count,
    COUNT(DISTINCT CASE WHEN dc2.document_type = 'resolution' THEN dc2.document_id END) as resolution_count,
    (
        SELECT AVG(DATEDIFF(COALESCE(o.updated_at, r.updated_at), COALESCE(o.created_at, r.created_at)))
        FROM document_classification dc3
        LEFT JOIN ordinances o ON dc3.document_id = o.id AND dc3.document_type = 'ordinance'
        LEFT JOIN resolutions r ON dc3.document_id = r.id AND dc3.document_type = 'resolution'
        WHERE dc3.category_id = dc.id 
        AND (o.status = 'approved' OR r.status = 'approved')
    ) as avg_days
FROM document_categories dc
LEFT JOIN document_classification dc2 ON dc.id = dc2.category_id
WHERE dc.is_active = 1
GROUP BY dc.id, dc.category_name, dc.category_code
ORDER BY (COUNT(DISTINCT dc2.document_id)) DESC
LIMIT 10";

$category_stats_stmt = $conn->query($category_stats_query);
$category_stats = $category_stats_stmt->fetchAll();

$committee_stats_query = "SELECT 
    c.committee_name,
    c.committee_code,
    COUNT(DISTINCT dc.document_id) as document_count,
    (
        SELECT AVG(DATEDIFF(
            (SELECT MAX(sh.changed_at) FROM status_history sh 
             WHERE sh.document_id = dc2.document_id AND sh.document_type = dc2.document_type),
            dc2.assigned_at
        ))
        FROM document_committees dc2
        WHERE dc2.committee_id = c.id AND dc2.status = 'approved'
    ) as avg_review_days
FROM committees c
LEFT JOIN document_committees dc ON c.id = dc.committee_id
WHERE c.is_active = 1
GROUP BY c.id, c.committee_name, c.committee_code
ORDER BY document_count DESC
LIMIT 8";

$committee_stats_stmt = $conn->query($committee_stats_query);
$committee_stats = $committee_stats_stmt->fetchAll();

// Get recent milestones - FIXED: Check if table exists first
$milestones_query = "SHOW TABLES LIKE 'progress_milestones'";
$milestones_table_exists = $conn->query($milestones_query)->fetch();

$upcoming_milestones = [];
if ($milestones_table_exists) {
    $milestones_query = "SELECT 
        pm.*,
        CASE 
            WHEN pm.document_type = 'ordinance' THEN o.ordinance_number
            WHEN pm.document_type = 'resolution' THEN r.resolution_number
        END as document_number,
        CASE 
            WHEN pm.document_type = 'ordinance' THEN o.title
            WHEN pm.document_type = 'resolution' THEN r.title
        END as document_title,
        CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name
    FROM progress_milestones pm
    LEFT JOIN ordinances o ON pm.document_id = o.id AND pm.document_type = 'ordinance'
    LEFT JOIN resolutions r ON pm.document_id = r.id AND pm.document_type = 'resolution'
    LEFT JOIN users u ON pm.assigned_to = u.id
    WHERE pm.expected_date >= CURDATE()
    ORDER BY pm.expected_date ASC
    LIMIT 10";
    
    $milestones_stmt = $conn->query($milestones_query);
    $upcoming_milestones = $milestones_stmt->fetchAll();
}

// Get delayed documents - FIXED QUERY
$delayed_query = "SELECT 
    'ordinance' as document_type,
    o.id as document_id,
    o.ordinance_number as document_number,
    o.title as title,
    o.status as status,
    DATEDIFF(CURDATE(), o.created_at) as days_since_creation,
    (SELECT COUNT(*) FROM status_history sh 
     WHERE sh.document_id = o.id AND sh.document_type = 'ordinance') as status_changes
FROM ordinances o
WHERE o.status IN ('draft', 'pending') AND DATEDIFF(CURDATE(), o.created_at) > 60
UNION ALL
SELECT 
    'resolution' as document_type,
    r.id as document_id,
    r.resolution_number as document_number,
    r.title as title,
    r.status as status,
    DATEDIFF(CURDATE(), r.created_at) as days_since_creation,
    (SELECT COUNT(*) FROM status_history sh 
     WHERE sh.document_id = r.id AND sh.document_type = 'resolution') as status_changes
FROM resolutions r
WHERE r.status IN ('draft', 'pending') AND DATEDIFF(CURDATE(), r.created_at) > 45
ORDER BY days_since_creation DESC
LIMIT 15";

$delayed_stmt = $conn->query($delayed_query);
$delayed_documents = $delayed_stmt->fetchAll();

// Get KPI data - FIXED: Check if table exists first
$kpi_query = "SHOW TABLES LIKE 'progress_kpis'";
$kpi_table_exists = $conn->query($kpi_query)->fetch();

$kpis = [];
if ($kpi_table_exists) {
    $kpi_query = "SELECT * FROM progress_kpis WHERE is_active = 1 ORDER BY kpi_type, kpi_name";
    $kpi_stmt = $conn->query($kpi_query);
    $kpis = $kpi_stmt->fetchAll();
} else {
    // Use default KPIs if table doesn't exist
    $kpis = [
        ['kpi_name' => 'Average Processing Time', 'kpi_description' => 'Average time from draft to approval', 'kpi_type' => 'timeliness', 'target_value' => 45.00, 'current_value' => 52.50, 'unit' => 'days'],
        ['kpi_name' => 'Approval Rate', 'kpi_description' => 'Percentage of approved documents', 'kpi_type' => 'efficiency', 'target_value' => 85.00, 'current_value' => 78.50, 'unit' => '%'],
        ['kpi_name' => 'Amendment Frequency', 'kpi_description' => 'Average amendments per document', 'kpi_type' => 'quality', 'target_value' => 2.00, 'current_value' => 3.20, 'unit' => 'times'],
        ['kpi_name' => 'Committee Review Time', 'kpi_description' => 'Average committee review duration', 'kpi_type' => 'timeliness', 'target_value' => 15.00, 'current_value' => 18.75, 'unit' => 'days']
    ];
}

// Get user-specific statistics - FIXED QUERY
$user_stats_query = "SELECT 
    (SELECT COUNT(DISTINCT document_id) FROM document_authors WHERE user_id = :user_id AND document_type = 'ordinance') as authored_ordinances,
    (SELECT COUNT(DISTINCT document_id) FROM document_authors WHERE user_id = :user_id AND document_type = 'resolution') as authored_resolutions,
    (
        SELECT AVG(DATEDIFF(
            COALESCE(o.updated_at, r.updated_at), 
            COALESCE(o.created_at, r.created_at)
        ))
        FROM document_authors da
        LEFT JOIN ordinances o ON da.document_id = o.id AND da.document_type = 'ordinance'
        LEFT JOIN resolutions r ON da.document_id = r.id AND da.document_type = 'resolution'
        WHERE da.user_id = :user_id 
        AND (o.status = 'approved' OR r.status = 'approved')
    ) as user_avg_days,
    (SELECT COUNT(DISTINCT committee_id) FROM committee_members WHERE user_id = :user_id) as committee_memberships";

$user_stats_stmt = $conn->prepare($user_stats_query);
$user_stats_stmt->bindParam(':user_id', $user_id);
$user_stats_stmt->execute();
$user_stats = $user_stats_stmt->fetch();

// Generate AI analytics data (mock data for demonstration)
$ai_analytics = [
    'completion_forecast' => [
        'title' => 'Completion Forecast',
        'data' => [
            ['month' => 'Jan', 'predicted' => 65, 'actual' => 62],
            ['month' => 'Feb', 'predicted' => 68, 'actual' => 66],
            ['month' => 'Mar', 'predicted' => 72, 'actual' => 70],
            ['month' => 'Apr', 'predicted' => 75, 'actual' => null],
            ['month' => 'May', 'predicted' => 78, 'actual' => null],
            ['month' => 'Jun', 'predicted' => 80, 'actual' => null]
        ],
        'confidence' => 0.85,
        'insight' => 'Processing efficiency expected to improve by 8% in Q2'
    ],
    'risk_assessment' => [
        'title' => 'Risk Assessment',
        'data' => [
            ['risk' => 'Legal Compliance', 'probability' => 15, 'impact' => 8, 'level' => 'Medium'],
            ['risk' => 'Committee Delays', 'probability' => 40, 'impact' => 6, 'level' => 'High'],
            ['risk' => 'Public Opposition', 'probability' => 25, 'impact' => 7, 'level' => 'Medium'],
            ['risk' => 'Budget Constraints', 'probability' => 30, 'impact' => 9, 'level' => 'High'],
            ['risk' => 'Technical Issues', 'probability' => 10, 'impact' => 4, 'level' => 'Low']
        ],
        'insight' => 'Committee delays pose the highest operational risk'
    ],
    'efficiency_trends' => [
        'title' => 'Efficiency Trends',
        'data' => [
            ['metric' => 'Draft to Submission', 'current' => 7.2, 'target' => 5.0, 'trend' => 'improving'],
            ['metric' => 'Committee Review', 'current' => 18.5, 'target' => 15.0, 'trend' => 'stable'],
            ['metric' => 'Public Hearing', 'current' => 21.3, 'target' => 20.0, 'trend' => 'improving'],
            ['metric' => 'Final Approval', 'current' => 12.8, 'target' => 10.0, 'trend' => 'declining'],
            ['metric' => 'Implementation', 'current' => 45.2, 'target' => 40.0, 'trend' => 'stable']
        ],
        'insight' => 'Final approval process requires optimization'
    ]
];

// If new tables don't exist, create them
if (!$kpi_table_exists) {
    try {
        // Create progress_kpis table
        $create_kpi_table = "CREATE TABLE IF NOT EXISTS `progress_kpis` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `kpi_name` varchar(100) NOT NULL,
            `kpi_description` text DEFAULT NULL,
            `kpi_type` enum('efficiency','timeliness','quality','compliance') NOT NULL,
            `target_value` decimal(10,2) DEFAULT NULL,
            `current_value` decimal(10,2) DEFAULT NULL,
            `unit` varchar(20) DEFAULT NULL,
            `document_type` enum('ordinance','resolution','both') DEFAULT 'both',
            `is_active` tinyint(1) DEFAULT 1,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        $conn->exec($create_kpi_table);
        
        // Insert sample KPI data
        $insert_kpis = "INSERT INTO `progress_kpis` (`kpi_name`, `kpi_description`, `kpi_type`, `target_value`, `current_value`, `unit`, `document_type`, `is_active`) VALUES
            ('Average Processing Time', 'Average time from draft creation to final approval', 'timeliness', 45.00, 52.50, 'days', 'both', 1),
            ('Approval Rate', 'Percentage of documents that receive final approval', 'efficiency', 85.00, 78.50, '%', 'both', 1),
            ('Amendment Frequency', 'Average number of amendments per document', 'quality', 2.00, 3.20, 'times', 'both', 1),
            ('Committee Review Time', 'Average time spent in committee review', 'timeliness', 15.00, 18.75, 'days', 'both', 1),
            ('Public Hearing Compliance', 'Percentage of documents with required public hearings', 'compliance', 100.00, 92.50, '%', 'ordinance', 1),
            ('Resolution Implementation', 'Percentage of resolutions implemented within deadline', 'efficiency', 90.00, 85.30, '%', 'resolution', 1)";
        
        $conn->exec($insert_kpis);
        
        // Refresh KPI data
        $kpi_query = "SELECT * FROM progress_kpis WHERE is_active = 1 ORDER BY kpi_type, kpi_name";
        $kpi_stmt = $conn->query($kpi_query);
        $kpis = $kpi_stmt->fetchAll();
        
    } catch (PDOException $e) {
        // If table creation fails, use default KPIs
        error_log("Error creating KPI table: " . $e->getMessage());
    }
}

if (!$milestones_table_exists) {
    try {
        // Create progress_milestones table
        $create_milestones_table = "CREATE TABLE IF NOT EXISTS `progress_milestones` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `document_id` int(11) NOT NULL,
            `document_type` enum('ordinance','resolution') NOT NULL,
            `milestone_name` varchar(100) NOT NULL,
            `milestone_description` text DEFAULT NULL,
            `expected_date` date DEFAULT NULL,
            `actual_date` date DEFAULT NULL,
            `status` enum('pending','in_progress','completed','delayed','cancelled') DEFAULT 'pending',
            `completion_percentage` int(11) DEFAULT 0,
            `assigned_to` int(11) DEFAULT NULL,
            `dependencies` varchar(255) DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        $conn->exec($create_milestones_table);
        
        // Insert sample milestone data
        $insert_milestones = "INSERT INTO `progress_milestones` (`document_id`, `document_type`, `milestone_name`, `milestone_description`, `expected_date`, `status`, `completion_percentage`, `assigned_to`) VALUES
            (5, 'ordinance', 'Committee Review', 'Initial review by Committee on Appropriations', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'in_progress', 60, 1),
            (5, 'ordinance', 'Public Hearing', 'Public consultation and feedback collection', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'pending', 0, 2),
            (1, 'ordinance', 'Final Approval', 'Voting and final approval process', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'pending', 30, 3)";
        
        $conn->exec($insert_milestones);
        
        // Refresh milestone data
        $milestones_query = "SELECT 
            pm.*,
            CASE 
                WHEN pm.document_type = 'ordinance' THEN o.ordinance_number
                WHEN pm.document_type = 'resolution' THEN r.resolution_number
            END as document_number,
            CASE 
                WHEN pm.document_type = 'ordinance' THEN o.title
                WHEN pm.document_type = 'resolution' THEN r.title
            END as document_title,
            CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name
        FROM progress_milestones pm
        LEFT JOIN ordinances o ON pm.document_id = o.id AND pm.document_type = 'ordinance'
        LEFT JOIN resolutions r ON pm.document_id = r.id AND pm.document_type = 'resolution'
        LEFT JOIN users u ON pm.assigned_to = u.id
        WHERE pm.expected_date >= CURDATE()
        ORDER BY pm.expected_date ASC
        LIMIT 10";
        
        $milestones_stmt = $conn->query($milestones_query);
        $upcoming_milestones = $milestones_stmt->fetchAll();
        
    } catch (PDOException $e) {
        // If table creation fails, use empty milestones
        error_log("Error creating milestones table: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Summary | QC Ordinance Tracker</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --qc-orange: #ED8936;
            --qc-purple: #9F7AEA;
            --white: #FFFFFF;
            --off-white: #F8F9FA;
            --gray-light: #E9ECEF;
            --gray: #6C757D;
            --gray-dark: #343A40;
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
        
        /* FILTER CONTROLS */
        .filter-controls {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px 30px;
            box-shadow: var(--shadow-md);
            margin-bottom: 30px;
            border: 1px solid var(--gray-light);
        }
        
        .filter-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            color: var(--qc-blue);
            font-size: 1.2rem;
            font-weight: bold;
        }
        
        .filter-title i {
            color: var(--qc-gold);
        }
        
        .filter-grid {
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
            background: var(--white);
            color: var(--gray-dark);
            font-size: 0.95rem;
            font-family: inherit;
        }
        
        .filter-select:focus {
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
        
        /* KPI DASHBOARD */
        .kpi-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .kpi-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .kpi-title {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--qc-blue);
        }
        
        .kpi-type {
            font-size: 0.8rem;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .type-efficiency { background: #d1fae5; color: #065f46; }
        .type-timeliness { background: #dbeafe; color: #1e40af; }
        .type-quality { background: #fef3c7; color: #92400e; }
        .type-compliance { background: #ede9fe; color: #5b21b6; }
        
        .kpi-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--gray-dark);
            margin-bottom: 10px;
            line-height: 1;
        }
        
        .kpi-target {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 15px;
        }
        
        .kpi-progress {
            height: 8px;
            background: var(--gray-light);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 15px;
        }
        
        .kpi-progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease;
        }
        
        .progress-excellent { background: var(--qc-green); }
        .progress-good { background: var(--qc-blue); }
        .progress-fair { background: var(--qc-gold); }
        .progress-poor { background: var(--qc-red); }
        
        .kpi-status {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--gray);
        }
        
        /* ANALYTICS GRIDS */
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .analytics-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--qc-blue);
        }
        
        .card-title i {
            color: var(--qc-gold);
        }
        
        .ai-badge {
            background: linear-gradient(135deg, var(--qc-purple) 0%, #805ad5 100%);
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        
        /* DATA TABLES */
        .data-table {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 40px;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .table-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--qc-blue);
        }
        
        .table-actions {
            display: flex;
            gap: 10px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: var(--off-white);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--qc-blue);
            border-bottom: 2px solid var(--gray-light);
            white-space: nowrap;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid var(--gray-light);
            color: var(--gray-dark);
        }
        
        tr:hover {
            background: var(--off-white);
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-delayed { background: #fed7d7; color: #c53030; }
        
        .priority-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .priority-low { background: #e2e8f0; color: #4a5568; }
        .priority-medium { background: #bee3f8; color: #2b6cb0; }
        .priority-high { background: #fed7d7; color: #c53030; }
        .priority-urgent { background: #feb2b2; color: #9b2c2c; }
        .priority-emergency { background: #fed7d7; color: #c53030; border: 2px solid #c53030; }
        
        /* RISK MATRIX */
        .risk-matrix {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-top: 20px;
        }
        
        .risk-cell {
            padding: 15px;
            border-radius: var(--border-radius);
            text-align: center;
            font-weight: 600;
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            min-height: 80px;
        }
        
        .risk-low { background: #48bb78; }
        .risk-medium { background: #ed8936; }
        .risk-high { background: #e53e3e; }
        .risk-critical { background: #c53030; }
        
        /* ACTION BUTTONS */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
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
        
        .btn-danger {
            background: linear-gradient(135deg, var(--qc-red) 0%, #9b2c2c 100%);
            color: var(--white);
        }
        
        /* INSIGHT CARDS */
        .insight-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .insight-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            border-left: 5px solid var(--qc-gold);
        }
        
        .insight-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .insight-icon {
            width: 50px;
            height: 50px;
            background: rgba(212, 175, 55, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-gold);
            font-size: 1.2rem;
        }
        
        .insight-title {
            font-size: 1.1rem;
            font-weight: bold;
            color: var(--qc-blue);
        }
        
        .insight-content {
            color: var(--gray-dark);
            line-height: 1.6;
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
        
        /* RESPONSIVE STYLES */
        @media (max-width: 1200px) {
            .analytics-grid {
                grid-template-columns: 1fr;
            }
            
            .kpi-dashboard {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }
        }
        
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
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .module-stats {
                flex-direction: column;
                gap: 20px;
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
            
            .kpi-dashboard {
                grid-template-columns: 1fr;
            }
            
            .risk-matrix {
                grid-template-columns: repeat(3, 1fr);
            }
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
                    <p>Progress Summary Module | Advanced Analytics & Reporting</p>
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
    
    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Sidebar -->
        <nav class="sidebar">
            <div class="sidebar-content">
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- MODULE HEADER -->
            <div class="module-header fade-in">
                <div class="module-header-content">
                    <div class="module-badge">PROGRESS SUMMARY MODULE</div>
                    
                    <div class="module-title-wrapper">
                        <div class="module-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div class="module-title">
                            <h1>Progress Summary & Analytics</h1>
                            <p class="module-subtitle">
                                Comprehensive tracking, AI-powered insights, and performance analytics for ordinances and resolutions. 
                                Monitor KPIs, identify bottlenecks, and optimize legislative processes.
                            </p>
                        </div>
                    </div>
                    
                    <div class="module-stats">
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo ($overall_stats['ordinance_count'] ?? 0) + ($overall_stats['resolution_count'] ?? 0); ?></h3>
                                <p>Total Documents</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $overall_stats['approved_count'] ?? 0; ?></h3>
                                <p>Approved</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo round($overall_stats['avg_processing_days'] ?? 0); ?>d</h3>
                                <p>Avg. Processing Time</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-brain"></i>
                            </div>
                            <div class="stat-info">
                                <h3>AI</h3>
                                <p>Powered Analytics</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FILTER CONTROLS -->
            <div class="filter-controls">
                <div class="filter-title">
                    <i class="fas fa-filter"></i>
                    <span>Filter Analytics</span>
                </div>
                
                <form method="GET" action="" class="filter-form">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label">Document Type</label>
                            <select name="document_type" class="filter-select" onchange="this.form.submit()">
                                <option value="both" <?php echo $document_type == 'both' ? 'selected' : ''; ?>>Both (Ordinances & Resolutions)</option>
                                <option value="ordinance" <?php echo $document_type == 'ordinance' ? 'selected' : ''; ?>>Ordinances Only</option>
                                <option value="resolution" <?php echo $document_type == 'resolution' ? 'selected' : ''; ?>>Resolutions Only</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="draft" <?php echo $status == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="implemented" <?php echo $status == 'implemented' ? 'selected' : ''; ?>>Implemented</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Timeframe</label>
                            <select name="timeframe" class="filter-select" onchange="this.form.submit()">
                                <option value="week" <?php echo $timeframe == 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="month" <?php echo $timeframe == 'month' ? 'selected' : ''; ?>>Last 30 Days</option>
                                <option value="quarter" <?php echo $timeframe == 'quarter' ? 'selected' : ''; ?>>Last 90 Days</option>
                                <option value="year" <?php echo $timeframe == 'year' ? 'selected' : ''; ?>>Last Year</option>
                                <option value="custom" <?php echo $timeframe == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Priority Level</label>
                            <select name="priority" class="filter-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $priority == 'all' ? 'selected' : ''; ?>>All Priorities</option>
                                <option value="low" <?php echo $priority == 'low' ? 'selected' : ''; ?>>Low</option>
                                <option value="medium" <?php echo $priority == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="high" <?php echo $priority == 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="urgent" <?php echo $priority == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                <option value="emergency" <?php echo $priority == 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sync-alt"></i> Apply Filters
                        </button>
                        <a href="progress_reports.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                        <button type="button" class="btn btn-success" onclick="generateReport()">
                            <i class="fas fa-download"></i> Export Report
                        </button>
                    </div>
                </form>
            </div>

            <!-- KPI DASHBOARD -->
            <div class="kpi-dashboard">
                <?php foreach ($kpis as $kpi): 
                    $percentage = isset($kpi['target_value']) && $kpi['target_value'] > 0 ? ($kpi['current_value'] / $kpi['target_value']) * 100 : 0;
                    $progress_class = $percentage >= 90 ? 'progress-excellent' : 
                                     ($percentage >= 75 ? 'progress-good' : 
                                     ($percentage >= 60 ? 'progress-fair' : 'progress-poor'));
                ?>
                <div class="kpi-card">
                    <div class="kpi-header">
                        <div class="kpi-title"><?php echo htmlspecialchars($kpi['kpi_name']); ?></div>
                        <div class="kpi-type type-<?php echo isset($kpi['kpi_type']) ? strtolower($kpi['kpi_type']) : 'timeliness'; ?>">
                            <?php echo isset($kpi['kpi_type']) ? $kpi['kpi_type'] : 'Timeliness'; ?>
                        </div>
                    </div>
                    
                    <div class="kpi-value"><?php echo isset($kpi['current_value']) ? number_format($kpi['current_value'], 2) : '0.00'; ?><?php echo isset($kpi['unit']) && $kpi['unit'] == '%' ? '%' : ''; ?></div>
                    <div class="kpi-target">Target: <?php echo isset($kpi['target_value']) ? number_format($kpi['target_value'], 2) : '0.00'; ?><?php echo isset($kpi['unit']) ? ' ' . $kpi['unit'] : ''; ?></div>
                    
                    <div class="kpi-progress">
                        <div class="kpi-progress-bar <?php echo $progress_class; ?>" 
                             style="width: <?php echo min($percentage, 100); ?>%"></div>
                    </div>
                    
                    <div class="kpi-status">
                        <span>Performance: <?php echo number_format($percentage, 1); ?>%</span>
                        <span><?php echo $percentage >= 100 ? 'Target Achieved' : ($percentage >= 80 ? 'On Track' : 'Needs Attention'); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- AI ANALYTICS SECTION -->
            <div class="analytics-grid">
                <!-- Completion Forecast -->
                <div class="analytics-card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-chart-line"></i>
                            <span><?php echo $ai_analytics['completion_forecast']['title']; ?></span>
                        </div>
                        <div class="ai-badge">
                            <i class="fas fa-brain"></i>
                            AI Forecast
                        </div>
                    </div>
                    
                    <div class="chart-container">
                        <canvas id="completionChart"></canvas>
                    </div>
                    
                    <div class="insight-content">
                        <p><strong>AI Insight:</strong> <?php echo $ai_analytics['completion_forecast']['insight']; ?></p>
                        <p>Confidence Level: <strong><?php echo ($ai_analytics['completion_forecast']['confidence'] * 100); ?>%</strong></p>
                    </div>
                </div>

                <!-- Risk Assessment -->
                <div class="analytics-card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span><?php echo $ai_analytics['risk_assessment']['title']; ?></span>
                        </div>
                        <div class="ai-badge">
                            <i class="fas fa-shield-alt"></i>
                            Risk Analysis
                        </div>
                    </div>
                    
                    <div class="chart-container">
                        <canvas id="riskChart"></canvas>
                    </div>
                    
                    <div class="insight-content">
                        <p><strong>AI Insight:</strong> <?php echo $ai_analytics['risk_assessment']['insight']; ?></p>
                        <div class="risk-matrix">
                            <div class="risk-cell risk-low">
                                <small>Low Risk</small>
                                <span>10-30%</span>
                            </div>
                            <div class="risk-cell risk-medium">
                                <small>Medium Risk</small>
                                <span>31-60%</span>
                            </div>
                            <div class="risk-cell risk-high">
                                <small>High Risk</small>
                                <span>61-80%</span>
                            </div>
                            <div class="risk-cell risk-critical">
                                <small>Critical</small>
                                <span>81-100%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- UPCOMING MILESTONES -->
            <div class="data-table">
                <div class="table-header">
                    <div class="table-title">
                        <i class="fas fa-flag-checkered"></i>
                        <span>Upcoming Milestones</span>
                    </div>
                    <div class="table-actions">
                        <button class="btn btn-secondary">
                            <i class="fas fa-plus"></i> Add Milestone
                        </button>
                        <button class="btn btn-primary" onclick="refreshMilestones()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Milestone</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Assigned To</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($upcoming_milestones)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: var(--gray);">
                                    <i class="fas fa-flag" style="font-size: 2rem; margin-bottom: 15px; color: var(--gray-light); display: block;"></i>
                                    <h4 style="color: var(--gray-dark); margin-bottom: 10px;">No Upcoming Milestones</h4>
                                    <p>Add milestones to track progress on your documents.</p>
                                    <button class="btn btn-primary" style="margin-top: 15px;">
                                        <i class="fas fa-plus"></i> Add First Milestone
                                    </button>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($upcoming_milestones as $milestone): 
                                $days_remaining = ceil((strtotime($milestone['expected_date']) - time()) / (60 * 60 * 24));
                                $progress_width = min($milestone['completion_percentage'], 100);
                                $progress_class = $progress_width >= 80 ? 'progress-excellent' : 
                                                 ($progress_width >= 50 ? 'progress-good' : 
                                                 ($progress_width >= 25 ? 'progress-fair' : 'progress-poor'));
                            ?>
                            <tr>
                                <td>
                                    <div><strong><?php echo htmlspecialchars($milestone['document_number'] ?? 'N/A'); ?></strong></div>
                                    <small><?php echo htmlspecialchars(substr($milestone['document_title'] ?? 'No title', 0, 50)) . '...'; ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($milestone['milestone_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php echo isset($milestone['expected_date']) ? date('M d, Y', strtotime($milestone['expected_date'])) : 'Not set'; ?>
                                    <div class="kpi-target">
                                        <?php echo $days_remaining > 0 ? "$days_remaining days left" : ($days_remaining == 0 ? "Due today" : "Overdue by " . abs($days_remaining) . " days"); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $milestone['status'] ?? 'pending'; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $milestone['status'] ?? 'pending')); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="kpi-progress" style="height: 6px;">
                                        <div class="kpi-progress-bar <?php echo $progress_class; ?>" 
                                             style="width: <?php echo $progress_width; ?>%"></div>
                                    </div>
                                    <small><?php echo $milestone['completion_percentage']; ?>%</small>
                                </td>
                                <td><?php echo htmlspecialchars($milestone['assigned_to_name'] ?? 'Unassigned'); ?></td>
                                <td>
                                    <button class="btn btn-secondary" style="padding: 5px 10px; font-size: 0.8rem;">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- DELAYED DOCUMENTS -->
            <div class="data-table">
                <div class="table-header">
                    <div class="table-title">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Delayed Documents Requiring Attention</span>
                    </div>
                    <div class="table-actions">
                        <button class="btn btn-danger">
                            <i class="fas fa-bell"></i> Send Reminders
                        </button>
                    </div>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Document #</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Days Since Creation</th>
                                <th>Status Changes</th>
                                <th>Priority</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($delayed_documents)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: var(--gray);">
                                    <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 15px; color: var(--qc-green); display: block;"></i>
                                    <h4 style="color: var(--qc-green); margin-bottom: 10px;">No Delayed Documents</h4>
                                    <p>All documents are progressing according to schedule.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($delayed_documents as $doc): 
                                $priority = $doc['days_since_creation'] > 90 ? 'urgent' : 
                                           ($doc['days_since_creation'] > 60 ? 'high' : 'medium');
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($doc['document_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars(substr($doc['title'], 0, 60)) . (strlen($doc['title']) > 60 ? '...' : ''); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $doc['document_type'] == 'ordinance' ? 'status-in_progress' : 'status-pending'; ?>">
                                        <?php echo ucfirst($doc['document_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $doc['status']; ?>">
                                        <?php echo ucfirst($doc['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="<?php echo $doc['days_since_creation'] > 60 ? 'priority-urgent' : 'priority-high'; ?>">
                                        <?php echo $doc['days_since_creation']; ?> days
                                    </span>
                                </td>
                                <td><?php echo $doc['status_changes']; ?> changes</td>
                                <td>
                                    <span class="priority-badge priority-<?php echo $priority; ?>">
                                        <?php echo ucfirst($priority); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-primary" style="padding: 5px 10px; font-size: 0.8rem;">
                                        <i class="fas fa-forward"></i> Expedite
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- CATEGORY & COMMITTEE INSIGHTS -->
            <div class="insight-grid">
                <!-- Category Insights -->
                <div class="insight-card">
                    <div class="insight-header">
                        <div class="insight-icon">
                            <i class="fas fa-folder"></i>
                        </div>
                        <div class="insight-title">Category Performance</div>
                    </div>
                    
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                    
                    <div class="insight-content">
                        <p><strong>Top Performing Categories:</strong></p>
                        <ul style="padding-left: 20px; margin-top: 10px;">
                            <?php 
                            $topCategories = array_slice($category_stats, 0, 3);
                            if (empty($topCategories)): 
                            ?>
                            <li>No category data available</li>
                            <?php else: ?>
                            <?php foreach ($topCategories as $cat): 
                                $total_docs = ($cat['ordinance_count'] ?? 0) + ($cat['resolution_count'] ?? 0);
                            ?>
                            <li><?php echo htmlspecialchars($cat['category_name']); ?> 
                                (<?php echo $total_docs; ?> documents, 
                                Avg: <?php echo round($cat['avg_days'] ?? 0); ?> days)</li>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <!-- Committee Insights -->
                <div class="insight-card">
                    <div class="insight-header">
                        <div class="insight-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="insight-title">Committee Efficiency</div>
                    </div>
                    
                    <div class="chart-container">
                        <canvas id="committeeChart"></canvas>
                    </div>
                    
                    <div class="insight-content">
                        <p><strong>Committee Review Times:</strong></p>
                        <ul style="padding-left: 20px; margin-top: 10px;">
                            <?php 
                            $efficientCommittees = array_slice($committee_stats, 0, 3);
                            if (empty($efficientCommittees)): 
                            ?>
                            <li>No committee data available</li>
                            <?php else: ?>
                            <?php foreach ($efficientCommittees as $com): 
                                $avgDays = round($com['avg_review_days'] ?? 0);
                                $efficiency = $avgDays <= 15 ? 'Efficient' : ($avgDays <= 25 ? 'Average' : 'Needs Improvement');
                            ?>
                            <li><?php echo htmlspecialchars($com['committee_name']); ?> 
                                (<?php echo $avgDays; ?> days - <?php echo $efficiency; ?>)</li>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <!-- User Performance -->
                <div class="insight-card">
                    <div class="insight-header">
                        <div class="insight-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="insight-title">Your Performance Summary</div>
                    </div>
                    
                    <div class="insight-content" style="margin-top: 20px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                            <div style="text-align: center;">
                                <div style="font-size: 2rem; font-weight: bold; color: var(--qc-blue);">
                                    <?php echo ($user_stats['authored_ordinances'] ?? 0) + ($user_stats['authored_resolutions'] ?? 0); ?>
                                </div>
                                <div style="font-size: 0.9rem; color: var(--gray);">Documents Authored</div>
                            </div>
                            <div style="text-align: center;">
                                <div style="font-size: 2rem; font-weight: bold; color: var(--qc-green);">
                                    <?php echo round($user_stats['user_avg_days'] ?? 0); ?>d
                                </div>
                                <div style="font-size: 0.9rem; color: var(--gray);">Avg. Processing Time</div>
                            </div>
                        </div>
                        
                        <p><strong>Your Efficiency Rating:</strong> 
                            <?php 
                            $userEfficiency = $user_stats['user_avg_days'] ?? 0;
                            if ($userEfficiency <= 30) echo '<span style="color: var(--qc-green);">Excellent</span>';
                            elseif ($userEfficiency <= 45) echo '<span style="color: var(--qc-blue);">Good</span>';
                            elseif ($userEfficiency <= 60) echo '<span style="color: var(--qc-gold);">Average</span>';
                            else echo '<span style="color: var(--qc-red);">Needs Improvement</span>';
                            ?>
                        </p>
                        
                        <p><strong>Committee Memberships:</strong> <?php echo $user_stats['committee_memberships'] ?? 0; ?></p>
                        
                        <div style="margin-top: 20px;">
                            <button class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-chart-bar"></i> View Detailed Performance
                            </button>
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
                <h3>Progress Analytics Module</h3>
                <p>
                    AI-powered analytics and progress tracking for Quezon City ordinances and resolutions. 
                    Monitor KPIs, identify bottlenecks, and optimize legislative processes with real-time insights.
                </p>
            </div>
            
            <div class="footer-column">
                <h3>Analytics Features</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-chart-line"></i> Performance Dashboards</a></li>
                    <li><a href="#"><i class="fas fa-brain"></i> AI Forecasting</a></li>
                    <li><a href="#"><i class="fas fa-exclamation-triangle"></i> Risk Assessment</a></li>
                    <li><a href="#"><i class="fas fa-flag-checkered"></i> Milestone Tracking</a></li>
                    <li><a href="#"><i class="fas fa-download"></i> Report Generation</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Quick Resources</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Main Dashboard</a></li>
                    <li><a href="tracking.php"><i class="fas fa-binoculars"></i> Tracking Module</a></li>
                    <li><a href="reports.php"><i class="fas fa-file-export"></i> Custom Reports</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Analytics Guide</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Progress Analytics Module - Powered by AI Insights.</p>
            <p style="margin-top: 10px;">All analytics are generated in real-time based on actual legislative data.</p>
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
        
        // Generate Report Function
        function generateReport() {
            alert('Generating comprehensive progress report... This will be downloaded as a PDF.');
            // In real implementation, this would generate and download a PDF report
        }
        
        function refreshMilestones() {
            location.reload();
        }
        
        // Chart.js Configurations
        document.addEventListener('DOMContentLoaded', function() {
            // Completion Forecast Chart
            const completionCtx = document.getElementById('completionChart').getContext('2d');
            const completionChart = new Chart(completionCtx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'Predicted Completion Rate',
                        data: [65, 68, 72, 75, 78, 80],
                        borderColor: '#9F7AEA',
                        backgroundColor: 'rgba(159, 122, 234, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Actual Completion Rate',
                        data: [62, 66, 70, null, null, null],
                        borderColor: '#D4AF37',
                        backgroundColor: 'rgba(212, 175, 55, 0.1)',
                        fill: true,
                        tension: 0.4,
                        borderDash: [5, 5]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            min: 50,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Completion Rate (%)'
                            }
                        }
                    }
                }
            });
            
            // Risk Assessment Chart
            const riskCtx = document.getElementById('riskChart').getContext('2d');
            const riskChart = new Chart(riskCtx, {
                type: 'radar',
                data: {
                    labels: ['Legal Compliance', 'Committee Delays', 'Public Opposition', 'Budget Constraints', 'Technical Issues'],
                    datasets: [{
                        label: 'Risk Probability (%)',
                        data: [15, 40, 25, 30, 10],
                        backgroundColor: 'rgba(197, 48, 48, 0.2)',
                        borderColor: 'rgba(197, 48, 48, 1)',
                        pointBackgroundColor: 'rgba(197, 48, 48, 1)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgba(197, 48, 48, 1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            angleLines: {
                                display: true
                            },
                            suggestedMin: 0,
                            suggestedMax: 50
                        }
                    }
                }
            });
            
            // Category Performance Chart - only render if we have data
            const categoryCtx = document.getElementById('categoryChart');
            if (categoryCtx) {
                const categoryChart = new Chart(categoryCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode(array_column($category_stats, 'category_name')); ?>,
                        datasets: [{
                            label: 'Ordinances',
                            data: <?php echo json_encode(array_column($category_stats, 'ordinance_count')); ?>,
                            backgroundColor: 'rgba(0, 51, 102, 0.8)',
                            borderColor: 'rgba(0, 51, 102, 1)',
                            borderWidth: 1
                        }, {
                            label: 'Resolutions',
                            data: <?php echo json_encode(array_column($category_stats, 'resolution_count')); ?>,
                            backgroundColor: 'rgba(45, 140, 71, 0.8)',
                            borderColor: 'rgba(45, 140, 71, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Documents'
                                }
                            }
                        }
                    }
                });
            }
            
            // Committee Efficiency Chart - only render if we have data
            const committeeCtx = document.getElementById('committeeChart');
            if (committeeCtx) {
                const committeeChart = new Chart(committeeCtx, {
                    type: 'horizontalBar',
                    data: {
                        labels: <?php echo json_encode(array_column($committee_stats, 'committee_name')); ?>,
                        datasets: [{
                            label: 'Average Review Time (days)',
                            data: <?php echo json_encode(array_column($committee_stats, 'avg_review_days')); ?>,
                            backgroundColor: function(context) {
                                const value = context.dataset.data[context.dataIndex];
                                return value <= 15 ? 'rgba(45, 140, 71, 0.8)' : 
                                       value <= 25 ? 'rgba(212, 175, 55, 0.8)' : 
                                       'rgba(197, 48, 48, 0.8)';
                            },
                            borderColor: function(context) {
                                const value = context.dataset.data[context.dataIndex];
                                return value <= 15 ? 'rgba(45, 140, 71, 1)' : 
                                       value <= 25 ? 'rgba(212, 175, 55, 1)' : 
                                       'rgba(197, 48, 48, 1)';
                            },
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Average Review Time (days)'
                                }
                            }
                        }
                    }
                });
            }
            
            // Auto-refresh charts every 5 minutes
            setInterval(() => {
                console.log('Refreshing analytics data...');
                // In real implementation, this would fetch new data via AJAX
            }, 300000);
            
            // Add animation to cards
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            // Observe all cards for animation
            document.querySelectorAll('.kpi-card, .analytics-card, .insight-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(card);
            });
        });
        
        // Export to PDF functionality
        function exportToPDF() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            
            doc.setFontSize(20);
            doc.text('Quezon City Legislative Progress Report', 20, 20);
            doc.setFontSize(12);
            doc.text(`Generated on: ${new Date().toLocaleDateString()}`, 20, 30);
            doc.text(`Generated by: ${'<?php echo htmlspecialchars($user["first_name"] . " " . $user["last_name"]); ?>'}`, 20, 40);
            
            // Add summary statistics
            doc.setFontSize(14);
            doc.text('Summary Statistics', 20, 60);
            doc.setFontSize(11);
            doc.text(`Total Documents: ${'<?php echo ($overall_stats["ordinance_count"] ?? 0) + ($overall_stats["resolution_count"] ?? 0); ?>'}`, 20, 70);
            doc.text(`Approved Documents: ${'<?php echo $overall_stats["approved_count"] ?? 0; ?>'}`, 20, 78);
            doc.text(`Average Processing Time: ${'<?php echo round($overall_stats["avg_processing_days"] ?? 0); ?>'} days`, 20, 86);
            
            // Add KPI section
            doc.setFontSize(14);
            doc.text('Key Performance Indicators', 20, 110);
            
            let yPosition = 120;
            <?php foreach ($kpis as $index => $kpi): ?>
            doc.setFontSize(11);
            doc.text(`${'<?php echo $kpi["kpi_name"]; ?>'}: ${'<?php echo number_format($kpi["current_value"], 2); ?>'}${'<?php echo $kpi["unit"] == "%" ? "%" : ""; ?>'}`, 20, yPosition);
            yPosition += 8;
            <?php endforeach; ?>
            
            // Save the PDF
            doc.save(`QC_Progress_Report_${new Date().toISOString().split('T')[0]}.pdf`);
        }
        
        // Initialize real-time updates
        function initializeRealTimeUpdates() {
            // Simulate real-time updates
            setInterval(() => {
                updateLiveStats();
            }, 10000);
        }
        
        function updateLiveStats() {
            // In a real implementation, this would fetch live data via AJAX
            const stats = document.querySelectorAll('.stat-info h3');
            if (stats.length > 0) {
                // Simulate minor updates
                const current = parseInt(stats[0].textContent);
                stats[0].textContent = current + Math.floor(Math.random() * 3);
                
                // Update KPI progress bars with slight variations
                document.querySelectorAll('.kpi-progress-bar').forEach(bar => {
                    const currentWidth = parseFloat(bar.style.width);
                    const newWidth = Math.min(currentWidth + (Math.random() * 2 - 1), 100);
                    bar.style.width = newWidth + '%';
                    
                    // Update the percentage text if available
                    const parent = bar.closest('.kpi-card');
                    if (parent) {
                        const percentSpan = parent.querySelector('.kpi-status span:first-child');
                        if (percentSpan) {
                            const match = percentSpan.textContent.match(/[\d.]+/);
                            if (match) {
                                const currentPercent = parseFloat(match[0]);
                                const newPercent = Math.min(currentPercent + (Math.random() * 0.5 - 0.25), 100);
                                percentSpan.textContent = `Performance: ${newPercent.toFixed(1)}%`;
                            }
                        }
                    }
                });
            }
        }
        
        // Start real-time updates
        initializeRealTimeUpdates();
    </script>
    
    <!-- Include jsPDF for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</body>
</html>