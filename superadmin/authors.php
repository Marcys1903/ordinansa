<?php
// authors.php - Author & Committee Assignment Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to assign authors and committees
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

// Get all documents (ordinances and resolutions) that need assignments
$documents_query = "
    (
        SELECT 
            'ordinance' as doc_type, 
            id, 
            ordinance_number as doc_number, 
            title, 
            status, 
            created_at 
        FROM ordinances 
        WHERE status IN ('draft', 'pending')
        ORDER BY created_at DESC
    )
    UNION ALL
    (
        SELECT 
            'resolution' as doc_type, 
            id, 
            resolution_number as doc_number, 
            title, 
            status, 
            created_at 
        FROM resolutions 
        WHERE status IN ('draft', 'pending')
        ORDER BY created_at DESC
    )
    ORDER BY created_at DESC
";

$documents_stmt = $conn->query($documents_query);
$documents = $documents_stmt->fetchAll();

// Get all available users for assignment
$users_query = "SELECT id, first_name, last_name, role, department FROM users 
                WHERE is_active = 1 AND role IN ('councilor', 'admin', 'super_admin')
                ORDER BY last_name, first_name";
$users_stmt = $conn->query($users_query);
$available_users = $users_stmt->fetchAll();

// Get all committees
$committees_query = "SELECT c.*, 
                     CONCAT(u1.first_name, ' ', u1.last_name) as chairperson_name,
                     CONCAT(u2.first_name, ' ', u2.last_name) as vice_chairperson_name
                     FROM committees c
                     LEFT JOIN users u1 ON c.chairperson_id = u1.id
                     LEFT JOIN users u2 ON c.vice_chairperson_id = u2.id
                     WHERE c.is_active = 1
                     ORDER BY c.committee_name";
$committees_stmt = $conn->query($committees_query);
$committees = $committees_stmt->fetchAll();

// Handle author assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_authors'])) {
    try {
        $conn->beginTransaction();
        
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        $authors = $_POST['authors'] ?? [];
        $roles = $_POST['roles'] ?? [];
        
        // Remove existing authors for this document
        $delete_query = "DELETE FROM document_authors WHERE document_id = :doc_id AND document_type = :doc_type";
        $stmt = $conn->prepare($delete_query);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->bindParam(':doc_type', $document_type);
        $stmt->execute();
        
        // Assign new authors with their roles
        foreach ($authors as $index => $author_id) {
            if (!empty($author_id)) {
                $role = isset($roles[$index]) ? $roles[$index] : 'author';
                
                $author_query = "INSERT INTO document_authors (document_id, document_type, user_id, role, assigned_by) 
                               VALUES (:doc_id, :doc_type, :author_id, :role, :assigned_by)";
                $stmt = $conn->prepare($author_query);
                $stmt->bindParam(':doc_id', $document_id);
                $stmt->bindParam(':doc_type', $document_type);
                $stmt->bindParam(':author_id', $author_id);
                $stmt->bindParam(':role', $role);
                $stmt->bindParam(':assigned_by', $user_id);
                $stmt->execute();
            }
        }
        
        $conn->commit();
        
        // Log the action
        $document_query = $document_type === 'ordinance' 
            ? "SELECT ordinance_number FROM ordinances WHERE id = :doc_id" 
            : "SELECT resolution_number FROM resolutions WHERE id = :doc_id";
        
        $stmt = $conn->prepare($document_query);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->execute();
        $doc_info = $stmt->fetch();
        $doc_number = $doc_info[$document_type === 'ordinance' ? 'ordinance_number' : 'resolution_number'];
        
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'AUTHOR_ASSIGNMENT', 'Assigned authors to {$doc_number}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Authors assigned successfully!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error assigning authors: " . $e->getMessage();
    }
}

// Handle committee assignment - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_committees'])) {
    try {
        $conn->beginTransaction();
        
        $document_id = $_POST['document_id'];
        $document_type = $_POST['document_type'];
        $committees_assigned = $_POST['committees'] ?? [];
        $assignment_types = $_POST['assignment_types'] ?? [];
        $committee_statuses = $_POST['committee_statuses'] ?? [];
        $committee_comments = $_POST['committee_comments'] ?? [];
        
        // Remove existing committee assignments for this document
        $delete_query = "DELETE FROM document_committees WHERE document_id = :doc_id AND document_type = :doc_type";
        $stmt = $conn->prepare($delete_query);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->bindParam(':doc_type', $document_type);
        $stmt->execute();
        
        // Assign new committees
        foreach ($committees_assigned as $index => $committee_id) {
            if (!empty($committee_id)) {
                $assignment_type = isset($assignment_types[$index]) ? $assignment_types[$index] : 'primary';
                $status = isset($committee_statuses[$index]) ? $committee_statuses[$index] : 'pending';
                $comments = isset($committee_comments[$index]) ? $committee_comments[$index] : '';
                
                $committee_query = "INSERT INTO document_committees 
                                   (document_id, document_type, committee_id, assignment_type, assigned_by, status, comments) 
                                  VALUES (:doc_id, :doc_type, :committee_id, :assignment_type, :assigned_by, :status, :comments)";
                $stmt = $conn->prepare($committee_query);
                $stmt->bindParam(':doc_id', $document_id);
                $stmt->bindParam(':doc_type', $document_type);
                $stmt->bindParam(':committee_id', $committee_id);
                $stmt->bindParam(':assignment_type', $assignment_type);
                $stmt->bindParam(':assigned_by', $user_id);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':comments', $comments);
                $stmt->execute();
            }
        }
        
        $conn->commit();
        
        // Log the action
        $document_query = $document_type === 'ordinance' 
            ? "SELECT ordinance_number FROM ordinances WHERE id = :doc_id" 
            : "SELECT resolution_number FROM resolutions WHERE id = :doc_id";
        
        $stmt = $conn->prepare($document_query);
        $stmt->bindParam(':doc_id', $document_id);
        $stmt->execute();
        $doc_info = $stmt->fetch();
        $doc_number = $doc_info[$document_type === 'ordinance' ? 'ordinance_number' : 'resolution_number'];
        
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'COMMITTEE_ASSIGNMENT', 'Assigned committees to {$doc_number}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        $success_message = "Committees assigned successfully!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error assigning committees: " . $e->getMessage();
    }
}

// Handle bulk assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_assign'])) {
    try {
        $conn->beginTransaction();
        
        $bulk_documents = $_POST['bulk_documents'] ?? [];
        $bulk_authors = $_POST['bulk_authors'] ?? [];
        
        foreach ($bulk_documents as $doc_info) {
            list($doc_id, $doc_type) = explode('|', $doc_info);
            
            // Remove existing authors for this document
            $delete_query = "DELETE FROM document_authors WHERE document_id = :doc_id AND document_type = :doc_type";
            $stmt = $conn->prepare($delete_query);
            $stmt->bindParam(':doc_id', $doc_id);
            $stmt->bindParam(':doc_type', $doc_type);
            $stmt->execute();
            
            // Assign new authors
            foreach ($bulk_authors as $author_id) {
                $author_query = "INSERT INTO document_authors (document_id, document_type, user_id, role, assigned_by) 
                               VALUES (:doc_id, :doc_type, :author_id, 'author', :assigned_by)";
                $stmt = $conn->prepare($author_query);
                $stmt->bindParam(':doc_id', $doc_id);
                $stmt->bindParam(':doc_type', $doc_type);
                $stmt->bindParam(':author_id', $author_id);
                $stmt->bindParam(':assigned_by', $user_id);
                $stmt->execute();
            }
        }
        
        $conn->commit();
        $success_message = "Bulk author assignment completed successfully!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "Error in bulk assignment: " . $e->getMessage();
    }
}

// Get recent author assignments
$recent_assignments_query = "
    SELECT 
        da.*,
        u.first_name,
        u.last_name,
        u.role as user_role,
        CASE da.document_type 
            WHEN 'ordinance' THEN o.ordinance_number
            WHEN 'resolution' THEN r.resolution_number
        END as doc_number,
        CASE da.document_type 
            WHEN 'ordinance' THEN o.title
            WHEN 'resolution' THEN r.title
        END as doc_title,
        da.document_type,
        da.assigned_at
    FROM document_authors da
    LEFT JOIN users u ON da.user_id = u.id
    LEFT JOIN ordinances o ON da.document_id = o.id AND da.document_type = 'ordinance'
    LEFT JOIN resolutions r ON da.document_id = r.id AND da.document_type = 'resolution'
    WHERE da.assigned_by = :user_id
    ORDER BY da.assigned_at DESC
    LIMIT 10
";

$recent_stmt = $conn->prepare($recent_assignments_query);
$recent_stmt->bindParam(':user_id', $user_id);
$recent_stmt->execute();
$recent_assignments = $recent_stmt->fetchAll();

// Get recent committee assignments
$recent_committee_assignments_query = "
    SELECT 
        dc.*,
        c.committee_name,
        c.committee_code,
        CASE dc.document_type 
            WHEN 'ordinance' THEN o.ordinance_number
            WHEN 'resolution' THEN r.resolution_number
        END as doc_number,
        CASE dc.document_type 
            WHEN 'ordinance' THEN o.title
            WHEN 'resolution' THEN r.title
        END as doc_title,
        dc.document_type,
        dc.assigned_at
    FROM document_committees dc
    LEFT JOIN committees c ON dc.committee_id = c.id
    LEFT JOIN ordinances o ON dc.document_id = o.id AND dc.document_type = 'ordinance'
    LEFT JOIN resolutions r ON dc.document_id = r.id AND dc.document_type = 'resolution'
    WHERE dc.assigned_by = :user_id
    ORDER BY dc.assigned_at DESC
    LIMIT 10
";

$recent_committee_stmt = $conn->prepare($recent_committee_assignments_query);
$recent_committee_stmt->bindParam(':user_id', $user_id);
$recent_committee_stmt->execute();
$recent_committee_assignments = $recent_committee_stmt->fetchAll();

// Get assignment statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT document_id) as total_documents,
        COUNT(DISTINCT user_id) as total_authors,
        COUNT(*) as total_assignments,
        (SELECT COUNT(DISTINCT document_id) FROM document_committees) as total_committee_docs,
        (SELECT COUNT(*) FROM document_committees) as total_committee_assignments
    FROM document_authors
";
$stats_stmt = $conn->query($stats_query);
$stats = $stats_stmt->fetch();

// Get documents without authors
$unassigned_docs_query = "
    (
        SELECT 
            'ordinance' as doc_type,
            o.id,
            o.ordinance_number as doc_number,
            o.title,
            o.created_at,
            COUNT(da.id) as author_count
        FROM ordinances o
        LEFT JOIN document_authors da ON o.id = da.document_id AND da.document_type = 'ordinance'
        WHERE o.status IN ('draft', 'pending')
        GROUP BY o.id
        HAVING author_count = 0
    )
    UNION ALL
    (
        SELECT 
            'resolution' as doc_type,
            r.id,
            r.resolution_number as doc_number,
            r.title,
            r.created_at,
            COUNT(da.id) as author_count
        FROM resolutions r
        LEFT JOIN document_authors da ON r.id = da.document_id AND da.document_type = 'resolution'
        WHERE r.status IN ('draft', 'pending')
        GROUP BY r.id
        HAVING author_count = 0
    )
    ORDER BY created_at DESC
    LIMIT 10
";

$unassigned_stmt = $conn->query($unassigned_docs_query);
$unassigned_docs = $unassigned_stmt->fetchAll();

// Get documents without committees
$unassigned_committee_docs_query = "
    (
        SELECT 
            'ordinance' as doc_type,
            o.id,
            o.ordinance_number as doc_number,
            o.title,
            o.created_at,
            COUNT(dc.id) as committee_count
        FROM ordinances o
        LEFT JOIN document_committees dc ON o.id = dc.document_id AND dc.document_type = 'ordinance'
        WHERE o.status IN ('draft', 'pending')
        GROUP BY o.id
        HAVING committee_count = 0
    )
    UNION ALL
    (
        SELECT 
            'resolution' as doc_type,
            r.id,
            r.resolution_number as doc_number,
            r.title,
            r.created_at,
            COUNT(dc.id) as committee_count
        FROM resolutions r
        LEFT JOIN document_committees dc ON r.id = dc.document_id AND dc.document_type = 'resolution'
        WHERE r.status IN ('draft', 'pending')
        GROUP BY r.id
        HAVING committee_count = 0
    )
    ORDER BY created_at DESC
    LIMIT 10
";

$unassigned_committee_stmt = $conn->query($unassigned_committee_docs_query);
$unassigned_committee_docs = $unassigned_committee_stmt->fetchAll();

// AJAX: Get existing assignments for a document
if (isset($_GET['action']) && $_GET['action'] == 'get_assignments' && isset($_GET['doc_id']) && isset($_GET['doc_type'])) {
    $doc_id = $_GET['doc_id'];
    $doc_type = $_GET['doc_type'];
    
    // Get existing authors
    $existing_authors_query = "SELECT da.*, u.first_name, u.last_name, u.role as user_role 
                              FROM document_authors da
                              JOIN users u ON da.user_id = u.id
                              WHERE da.document_id = :doc_id AND da.document_type = :doc_type";
    $stmt = $conn->prepare($existing_authors_query);
    $stmt->bindParam(':doc_id', $doc_id);
    $stmt->bindParam(':doc_type', $doc_type);
    $stmt->execute();
    $existing_authors = $stmt->fetchAll();
    
    // Get existing committees
    $existing_committees_query = "SELECT dc.*, c.committee_name, c.committee_code 
                                 FROM document_committees dc
                                 JOIN committees c ON dc.committee_id = c.id
                                 WHERE dc.document_id = :doc_id AND dc.document_type = :doc_type";
    $stmt = $conn->prepare($existing_committees_query);
    $stmt->bindParam(':doc_id', $doc_id);
    $stmt->bindParam(':doc_type', $doc_type);
    $stmt->execute();
    $existing_committees = $stmt->fetchAll();
    
    echo json_encode([
        'authors' => $existing_authors,
        'committees' => $existing_committees
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Author & Committee Assignment | QC Ordinance Tracker</title>
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
            --qc-purple: #6B46C1;
            --qc-purple-dark: #553C9A;
            --white: #FFFFFF;
            --off-white: #F8F9FA;
            --gray-light: #E9ECEF;
            --gray: #6C757D;
            --gray-dark: #343A40;
            --red: #C53030;
            --green: #2D8C47;
            --yellow: #D69E2E;
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
        
        /* TABS NAVIGATION */
        .tabs-navigation {
            display: flex;
            background: var(--white);
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            overflow: hidden;
        }
        
        .tab {
            flex: 1;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border-right: 1px solid var(--gray-light);
            font-weight: 600;
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .tab:last-child {
            border-right: none;
        }
        
        .tab:hover {
            background: var(--off-white);
            color: var(--qc-blue);
        }
        
        .tab.active {
            background: var(--qc-blue);
            color: var(--white);
            border-bottom: 3px solid var(--qc-gold);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* CONTENT CONTAINERS */
        .content-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 1200px) {
            .content-container {
                grid-template-columns: 1fr;
            }
        }
        
        .main-panel {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .side-panel {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        /* SECTION STYLES */
        .section {
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .section:last-child {
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
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23343A40' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 12px;
            padding-right: 40px;
        }
        
        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }
        
        /* DOCUMENT SELECTION */
        .document-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .document-card {
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .document-card:hover {
            border-color: var(--qc-blue);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .document-card.selected {
            border-color: var(--qc-gold);
            background: rgba(212, 175, 55, 0.05);
        }
        
        .document-radio {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 20px;
            height: 20px;
            accent-color: var(--qc-gold);
        }
        
        .document-type {
            display: inline-block;
            padding: 4px 12px;
            background: var(--gray-light);
            color: var(--gray-dark);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .document-type.ordinance {
            background: rgba(0, 51, 102, 0.1);
            color: var(--qc-blue);
        }
        
        .document-type.resolution {
            background: rgba(45, 140, 71, 0.1);
            color: var(--qc-green);
        }
        
        .document-number {
            font-weight: bold;
            color: var(--qc-blue);
            margin-bottom: 5px;
            font-size: 1rem;
        }
        
        .document-title {
            color: var(--gray-dark);
            font-size: 0.95rem;
            line-height: 1.4;
            margin-bottom: 10px;
        }
        
        .document-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .document-status {
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-pending { background: #dbeafe; color: #1e40af; }
        
        /* AUTHOR SELECTION */
        .author-selection-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-top: 10px;
        }
        
        .author-row {
            display: grid;
            grid-template-columns: 2fr 1fr 40px;
            gap: 15px;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .author-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
        
        .author-row:hover {
            background: var(--off-white);
        }
        
        .author-row:last-child {
            border-bottom: none;
        }
        
        .author-checkbox {
            width: 20px;
            height: 20px;
            accent-color: var(--qc-blue);
        }
        
        .author-info {
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
        
        .author-details {
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
        
        .role-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            background: var(--white);
        }
        
        .remove-btn {
            color: var(--red);
            cursor: pointer;
            font-size: 1.2rem;
            text-align: center;
            background: none;
            border: none;
            padding: 5px;
        }
        
        .remove-btn:hover {
            color: #9b2c2c;
        }
        
        .add-btn {
            width: 100%;
            padding: 12px;
            background: var(--off-white);
            border: 2px dashed var(--gray-light);
            border-radius: var(--border-radius);
            color: var(--gray);
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .add-btn:hover {
            background: var(--gray-light);
            color: var(--gray-dark);
        }
        
        /* COMMITTEE SELECTION */
        .committee-selection-container {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            padding: 15px;
            margin-top: 10px;
        }
        
        .committee-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 40px;
            gap: 15px;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        @media (max-width: 992px) {
            .committee-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
        
        .committee-row:hover {
            background: var(--off-white);
        }
        
        .committee-row:last-child {
            border-bottom: none;
        }
        
        .committee-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .committee-icon {
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
        
        .committee-details {
            flex: 1;
        }
        
        .committee-name {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 2px;
        }
        
        .committee-code {
            font-size: 0.85rem;
            color: var(--qc-purple);
            font-weight: 600;
        }
        
        .committee-chairperson {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        .assignment-type-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            background: var(--white);
        }
        
        .status-select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            background: var(--white);
        }
        
        /* BULK ASSIGNMENT */
        .bulk-assignment {
            background: rgba(0, 51, 102, 0.05);
            border: 1px solid rgba(0, 51, 102, 0.1);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 30px;
        }
        
        .bulk-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
            color: var(--qc-blue);
            font-weight: 600;
        }
        
        .bulk-header i {
            color: var(--qc-gold);
        }
        
        .bulk-docs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .bulk-doc-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background: var(--white);
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 0.85rem;
        }
        
        .bulk-doc-checkbox {
            accent-color: var(--qc-blue);
        }
        
        /* RECENT ASSIGNMENTS */
        .assignments-list {
            list-style: none;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .assignment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .assignment-item:hover {
            background: var(--off-white);
        }
        
        .assignment-item:last-child {
            border-bottom: none;
        }
        
        .assignment-avatar {
            width: 40px;
            height: 40px;
            background: var(--off-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-blue);
            font-size: 1rem;
            font-weight: bold;
            border: 2px solid var(--gray-light);
        }
        
        .assignment-committee {
            width: 40px;
            height: 40px;
            background: var(--off-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-purple);
            font-size: 1rem;
            font-weight: bold;
            border: 2px solid var(--gray-light);
        }
        
        .assignment-content {
            flex: 1;
        }
        
        .assignment-title {
            font-weight: 600;
            color: var(--qc-blue);
            margin-bottom: 2px;
            font-size: 0.9rem;
        }
        
        .assignment-meta {
            display: flex;
            gap: 10px;
            font-size: 0.8rem;
            color: var(--gray);
            flex-wrap: wrap;
        }
        
        .assignment-role {
            padding: 2px 8px;
            background: rgba(212, 175, 55, 0.1);
            color: var(--qc-gold-dark);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .assignment-type {
            padding: 2px 8px;
            background: rgba(107, 70, 193, 0.1);
            color: var(--qc-purple);
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* UNASSIGNED DOCS */
        .unassigned-list {
            list-style: none;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .unassigned-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-bottom: 1px solid var(--gray-light);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .unassigned-item:hover {
            background: var(--off-white);
        }
        
        .unassigned-item:last-child {
            border-bottom: none;
        }
        
        .unassigned-icon {
            width: 35px;
            height: 35px;
            background: var(--off-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .unassigned-content {
            flex: 1;
        }
        
        .unassigned-number {
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 2px;
            font-size: 0.85rem;
        }
        
        .unassigned-title {
            font-size: 0.8rem;
            color: var(--gray);
        }
        
        /* ACTION BUTTONS */
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
        
        .btn-purple {
            background: linear-gradient(135deg, var(--qc-purple) 0%, var(--qc-purple-dark) 100%);
            color: var(--white);
        }
        
        .btn-purple:hover {
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
            
            .document-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs-navigation {
                flex-direction: column;
            }
            
            .tab {
                border-right: none;
                border-bottom: 1px solid var(--gray-light);
            }
            
            .tab:last-child {
                border-bottom: none;
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
            
            .bulk-docs-grid {
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
        
        /* Status Colors */
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-reviewing { background: #dbeafe; color: #1e40af; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }

        
        /* Add these styles for the comments field in committee rows */
        .committee-comment-input {
            grid-column: 1 / -1;
            margin-top: 10px;
            display: none;
        }
        
        .committee-comment-input.active {
            display: block;
        }
        
        .show-comments-btn {
            background: none;
            border: none;
            color: var(--qc-blue);
            cursor: pointer;
            font-size: 0.8rem;
            margin-top: 5px;
            text-decoration: underline;
        }
        
        .show-comments-btn:hover {
            color: var(--qc-blue-dark);
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
                    <p>Author & Committee Assignment Module | Assign Sponsors, Authors, and Committees</p>
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
                        <i class="fas fa-user-edit"></i> Assignment Module
                    </h3>
                    <p class="sidebar-subtitle">Assign authors and committees to documents</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="assignment-container">
                <!-- FIXED MODULE HEADER -->
                <div class="module-header fade-in">
                    <div class="module-header-content">
                        <div class="module-badge">AUTHOR & COMMITTEE ASSIGNMENT MODULE</div>
                        
                        <div class="module-title-wrapper">
                            <div class="module-icon">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <div class="module-title">
                                <h1>Assign Sponsors, Authors & Committees</h1>
                                <p class="module-subtitle">
                                    Assign sponsors, authors, co-sponsors, and committees to ordinances and resolutions. 
                                    Manage roles, assignment types, and track assignment history.
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
                                    <p>Documents Available</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo count($available_users); ?></h3>
                                    <p>Available Authors</p>
                                </div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-icon">
                                    <i class="fas fa-landmark"></i>
                                </div>
                                <div class="stat-info">
                                    <h3><?php echo count($committees); ?></h3>
                                    <p>Committees</p>
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

                <!-- Tabs Navigation -->
                <div class="tabs-navigation fade-in">
                    <div class="tab active" data-tab="authors">
                        <i class="fas fa-users"></i>
                        <span>Author Assignment</span>
                    </div>
                    <div class="tab" data-tab="committees">
                        <i class="fas fa-landmark"></i>
                        <span>Committee Assignment</span>
                    </div>
                    <div class="tab" data-tab="bulk">
                        <i class="fas fa-layer-group"></i>
                        <span>Bulk Assignment</span>
                    </div>
                </div>

                <!-- Main Content Container -->
                <div class="content-container fade-in">
                    <!-- Main Panel -->
                    <div class="main-panel">
                        <!-- Document Selection (Common for both tabs) -->
                        <div class="section">
                            <h3 class="section-title">
                                <i class="fas fa-file-alt"></i>
                                1. Select Document
                            </h3>
                            
                            <div class="form-group">
                                <label class="form-label required">Choose Document to Assign</label>
                                <div class="document-grid" id="documentGrid">
                                    <?php if (empty($documents)): ?>
                                        <div style="grid-column: 1 / -1; text-align: center; padding: 40px 20px; color: var(--gray);">
                                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                                            <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Documents Found</h3>
                                            <p>There are no documents available for assignment.</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($documents as $document): 
                                            $type_class = $document['doc_type'] === 'ordinance' ? 'ordinance' : 'resolution';
                                            $status_class = 'status-' . $document['status'];
                                            
                                            // Get author count
                                            $authors_query = "SELECT COUNT(*) as author_count FROM document_authors 
                                                            WHERE document_id = :doc_id AND document_type = :doc_type";
                                            $stmt = $conn->prepare($authors_query);
                                            $stmt->bindParam(':doc_id', $document['id']);
                                            $stmt->bindParam(':doc_type', $document['doc_type']);
                                            $stmt->execute();
                                            $author_count = $stmt->fetch()['author_count'];
                                            
                                            // Get committee count
                                            $committees_query = "SELECT COUNT(*) as committee_count FROM document_committees 
                                                               WHERE document_id = :doc_id AND document_type = :doc_type";
                                            $stmt = $conn->prepare($committees_query);
                                            $stmt->bindParam(':doc_id', $document['id']);
                                            $stmt->bindParam(':doc_type', $document['doc_type']);
                                            $stmt->execute();
                                            $committee_count = $stmt->fetch()['committee_count'];
                                        ?>
                                        <div class="document-card" data-document-id="<?php echo $document['id']; ?>" 
                                             data-document-type="<?php echo $document['doc_type']; ?>"
                                             data-doc-number="<?php echo htmlspecialchars($document['doc_number']); ?>">
                                            <input type="radio" name="selected_document" value="<?php echo $document['id'] . '|' . $document['doc_type']; ?>" 
                                                   class="document-radio" id="doc_<?php echo $document['id']; ?>">
                                            <div class="document-type <?php echo $type_class; ?>">
                                                <?php echo ucfirst($document['doc_type']); ?>
                                            </div>
                                            <div class="document-number">
                                                <?php echo htmlspecialchars($document['doc_number']); ?>
                                            </div>
                                            <div class="document-title">
                                                <?php echo htmlspecialchars($document['title']); ?>
                                            </div>
                                            <div class="document-meta">
                                                <div>
                                                    <span class="document-status <?php echo $status_class; ?>">
                                                        <?php echo ucfirst($document['status']); ?>
                                                    </span>
                                                    <span style="margin-left: 10px;">
                                                        <i class="fas fa-users"></i> <?php echo $author_count; ?>
                                                    </span>
                                                    <span style="margin-left: 5px;">
                                                        <i class="fas fa-landmark"></i> <?php echo $committee_count; ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <?php echo date('M d, Y', strtotime($document['created_at'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Author Assignment Tab -->
                        <div class="tab-content active" id="authorsTab">
                            <div class="section">
                                <h3 class="section-title">
                                    <i class="fas fa-user-edit"></i>
                                    2. Assign Authors & Sponsors
                                </h3>
                                
                                <form id="authorAssignmentForm" method="POST">
                                    <input type="hidden" name="document_id" id="documentIdInput">
                                    <input type="hidden" name="document_type" id="documentTypeInput">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Select Authors & Assign Roles</label>
                                        <div class="author-selection-container" id="authorSelectionContainer">
                                            <!-- Authors will be added here dynamically -->
                                            <div class="author-row" id="authorRowTemplate" style="display: none;">
                                                <div class="author-info">
                                                    <select class="form-control" name="authors[]" required>
                                                        <option value="">Select an author...</option>
                                                        <?php foreach ($available_users as $user): ?>
                                                        <option value="<?php echo $user['id']; ?>">
                                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['role'] . ')'); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="author-avatar" style="display: none;">AA</div>
                                                    <div class="author-details" style="display: none;">
                                                        <div class="author-name">Author Name</div>
                                                        <div class="author-role">Role</div>
                                                        <div class="author-department">Department</div>
                                                    </div>
                                                </div>
                                                <select class="role-select" name="roles[]">
                                                    <option value="author">Author</option>
                                                    <option value="sponsor">Sponsor</option>
                                                    <option value="co_sponsor">Co-Sponsor</option>
                                                </select>
                                                <button type="button" class="remove-btn" onclick="removeAuthorRow(this)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            
                                            <!-- Initial empty state -->
                                            <div id="noAuthorsMessage" style="text-align: center; padding: 40px 20px; color: var(--gray);">
                                                <i class="fas fa-user-plus" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                                                <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Authors Selected</h3>
                                                <p>Select a document above, then click "Add Author" to assign authors.</p>
                                            </div>
                                        </div>
                                        
                                        <button type="button" class="add-btn" onclick="addAuthorRow()">
                                            <i class="fas fa-plus"></i> Add Author
                                        </button>
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="button" class="btn btn-secondary" onclick="clearForm('authors')">
                                            <i class="fas fa-eraser"></i> Clear
                                        </button>
                                        <button type="submit" class="btn btn-success" name="assign_authors">
                                            <i class="fas fa-check"></i> Assign Authors
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Committee Assignment Tab -->
                        <div class="tab-content" id="committeesTab">
                            <div class="section">
                                <h3 class="section-title">
                                    <i class="fas fa-landmark"></i>
                                    2. Assign Committees
                                </h3>
                                
                                <form id="committeeAssignmentForm" method="POST">
                                    <input type="hidden" name="document_id" id="committeeDocumentIdInput">
                                    <input type="hidden" name="document_type" id="committeeDocumentTypeInput">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Select Committees & Assignment Details</label>
                                        <div class="committee-selection-container" id="committeeSelectionContainer">
                                            <!-- Committees will be added here dynamically -->
                                            <div class="committee-row" id="committeeRowTemplate" style="display: none;">
                                                <div class="committee-info">
                                                    <select class="form-control" name="committees[]" required>
                                                        <option value="">Select a committee...</option>
                                                        <?php foreach ($committees as $committee): ?>
                                                        <option value="<?php echo $committee['id']; ?>" 
                                                                data-code="<?php echo htmlspecialchars($committee['committee_code']); ?>"
                                                                data-chairperson="<?php echo htmlspecialchars($committee['chairperson_name']); ?>">
                                                            <?php echo htmlspecialchars($committee['committee_name'] . ' (' . $committee['committee_code'] . ')'); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="committee-icon" style="display: none;">C</div>
                                                    <div class="committee-details" style="display: none;">
                                                        <div class="committee-name">Committee Name</div>
                                                        <div class="committee-code">Code</div>
                                                        <div class="committee-chairperson">Chairperson</div>
                                                    </div>
                                                </div>
                                                <select class="assignment-type-select" name="assignment_types[]">
                                                    <option value="primary">Primary Committee</option>
                                                    <option value="secondary">Secondary Committee</option>
                                                    <option value="review">Review Committee</option>
                                                    <option value="recommending">Recommending Committee</option>
                                                </select>
                                                <select class="status-select" name="committee_statuses[]">
                                                    <option value="pending">Pending</option>
                                                    <option value="reviewing">Reviewing</option>
                                                    <option value="approved">Approved</option>
                                                    <option value="rejected">Rejected</option>
                                                </select>
                                                <button type="button" class="remove-btn" onclick="removeCommitteeRow(this)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                                <div class="committee-comment-input">
                                                    <textarea class="form-control" name="committee_comments[]" 
                                                              placeholder="Enter comments for this committee assignment..." 
                                                              rows="2"></textarea>
                                                </div>
                                            </div>
                                            
                                            <!-- Initial empty state -->
                                            <div id="noCommitteesMessage" style="text-align: center; padding: 40px 20px; color: var(--gray);">
                                                <i class="fas fa-landmark" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                                                <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Committees Selected</h3>
                                                <p>Select a document above, then click "Add Committee" to assign committees.</p>
                                            </div>
                                        </div>
                                        
                                        <button type="button" class="add-btn" onclick="addCommitteeRow()">
                                            <i class="fas fa-plus"></i> Add Committee
                                        </button>
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="button" class="btn btn-secondary" onclick="clearForm('committees')">
                                            <i class="fas fa-eraser"></i> Clear
                                        </button>
                                        <button type="submit" class="btn btn-purple" name="assign_committees">
                                            <i class="fas fa-check"></i> Assign Committees
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Bulk Assignment Tab -->
                        <div class="tab-content" id="bulkTab">
                            <div class="section">
                                <h3 class="section-title">
                                    <i class="fas fa-layer-group"></i>
                                    Bulk Author Assignment
                                </h3>
                                
                                <div class="bulk-assignment">
                                    <form method="POST" id="bulkAssignmentForm">
                                        <div class="form-group">
                                            <label class="form-label">Select Multiple Documents</label>
                                            <div class="bulk-docs-grid">
                                                <?php foreach (array_slice($documents, 0, 6) as $document): ?>
                                                <div class="bulk-doc-item">
                                                    <input type="checkbox" name="bulk_documents[]" 
                                                           value="<?php echo $document['id'] . '|' . $document['doc_type']; ?>"
                                                           class="bulk-doc-checkbox">
                                                    <span>
                                                        <?php echo htmlspecialchars($document['doc_number']); ?>
                                                    </span>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Select Authors for Bulk Assignment</label>
                                            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                                                <?php foreach (array_slice($available_users, 0, 6) as $author): ?>
                                                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                                    <input type="checkbox" name="bulk_authors[]" value="<?php echo $author['id']; ?>" 
                                                           class="bulk-author-checkbox">
                                                    <span style="font-size: 0.9rem;">
                                                        <?php echo htmlspecialchars($author['first_name'] . ' ' . $author['last_name']); ?>
                                                    </span>
                                                </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="form-actions" style="margin-top: 20px; padding-top: 20px;">
                                            <button type="submit" class="btn btn-primary" name="bulk_assign">
                                                <i class="fas fa-users"></i> Bulk Assign Authors
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Side Panel -->
                    <div class="side-panel">
                        <!-- Recent Assignments -->
                        <div class="section">
                            <h3 class="section-title">
                                <i class="fas fa-history"></i>
                                Recent Author Assignments
                            </h3>
                            
                            <?php if (empty($recent_assignments)): ?>
                                <div style="text-align: center; padding: 20px 0; color: var(--gray);">
                                    <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 10px; color: var(--gray-light);"></i>
                                    <p>No recent author assignments</p>
                                </div>
                            <?php else: ?>
                                <ul class="assignments-list">
                                    <?php foreach ($recent_assignments as $assignment): ?>
                                    <li class="assignment-item">
                                        <div class="assignment-avatar">
                                            <?php echo strtoupper(substr($assignment['first_name'], 0, 1) . substr($assignment['last_name'], 0, 1)); ?>
                                        </div>
                                        <div class="assignment-content">
                                            <div class="assignment-title">
                                                <?php echo htmlspecialchars($assignment['first_name'] . ' ' . $assignment['last_name']); ?>
                                            </div>
                                            <div class="assignment-meta">
                                                <span class="assignment-role">
                                                    <?php echo ucfirst(str_replace('_', ' ', $assignment['role'])); ?>
                                                </span>
                                                <span><?php echo htmlspecialchars($assignment['doc_number']); ?></span>
                                                <span><?php echo date('M d', strtotime($assignment['assigned_at'])); ?></span>
                                            </div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <!-- Recent Committee Assignments -->
                        <div class="section">
                            <h3 class="section-title">
                                <i class="fas fa-landmark"></i>
                                Recent Committee Assignments
                            </h3>
                            
                            <?php if (empty($recent_committee_assignments)): ?>
                                <div style="text-align: center; padding: 20px 0; color: var(--gray);">
                                    <i class="fas fa-landmark" style="font-size: 2rem; margin-bottom: 10px; color: var(--gray-light);"></i>
                                    <p>No recent committee assignments</p>
                                </div>
                            <?php else: ?>
                                <ul class="assignments-list">
                                    <?php foreach ($recent_committee_assignments as $assignment): ?>
                                    <li class="assignment-item">
                                        <div class="assignment-committee">
                                            <?php echo strtoupper(substr($assignment['committee_code'], 0, 2)); ?>
                                        </div>
                                        <div class="assignment-content">
                                            <div class="assignment-title">
                                                <?php echo htmlspecialchars($assignment['committee_name']); ?>
                                            </div>
                                            <div class="assignment-meta">
                                                <span class="assignment-type">
                                                    <?php echo ucfirst($assignment['assignment_type']); ?>
                                                </span>
                                                <span><?php echo htmlspecialchars($assignment['doc_number']); ?></span>
                                                <span class="status-<?php echo $assignment['status']; ?>">
                                                    <?php echo ucfirst($assignment['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <!-- Unassigned Documents -->
                        <div class="section">
                            <h3 class="section-title">
                                <i class="fas fa-exclamation-triangle"></i>
                                Unassigned Documents
                            </h3>
                            
                            <div style="margin-bottom: 15px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                    <span style="font-size: 0.9rem; color: var(--gray);">Without Authors:</span>
                                    <span style="font-weight: 600;"><?php echo count($unassigned_docs); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="font-size: 0.9rem; color: var(--gray);">Without Committees:</span>
                                    <span style="font-weight: 600;"><?php echo count($unassigned_committee_docs); ?></span>
                                </div>
                            </div>
                            
                            <?php if (count($unassigned_docs) > 0): ?>
                            <div style="margin-top: 15px;">
                                <div style="font-size: 0.9rem; color: var(--gray); margin-bottom: 10px;">Click to assign:</div>
                                <ul class="unassigned-list">
                                    <?php foreach (array_slice($unassigned_docs, 0, 3) as $doc): ?>
                                    <li class="unassigned-item" onclick="selectUnassignedDoc(<?php echo $doc['id']; ?>, '<?php echo $doc['doc_type']; ?>', 'authors')" 
                                        style="cursor: pointer;">
                                        <div class="unassigned-icon">
                                            <i class="fas fa-<?php echo $doc['doc_type'] === 'ordinance' ? 'gavel' : 'file-signature'; ?>"></i>
                                        </div>
                                        <div class="unassigned-content">
                                            <div class="unassigned-number">
                                                <?php echo htmlspecialchars($doc['doc_number']); ?>
                                            </div>
                                            <div class="unassigned-title">
                                                <?php echo htmlspecialchars($doc['title']); ?>
                                            </div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Stats -->
                        <div class="section">
                            <h3 class="section-title">
                                <i class="fas fa-chart-pie"></i>
                                Assignment Stats
                            </h3>
                            
                            <div style="display: flex; flex-direction: column; gap: 15px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Documents:</span>
                                    <strong><?php echo $stats['total_documents']; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Unique Authors:</span>
                                    <strong><?php echo $stats['total_authors']; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Author Assignments:</span>
                                    <strong><?php echo $stats['total_assignments']; ?></strong>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span>Committee Assignments:</span>
                                    <strong><?php echo $stats['total_committee_assignments']; ?></strong>
                                </div>
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
                    <h3>Author & Committee Assignment Module</h3>
                    <p>
                        Assign sponsors, authors, co-sponsors, and committees to ordinances and resolutions. 
                        Manage roles, assignment types, and track assignment history.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="creation.php"><i class="fas fa-file-contract"></i> Document Creation</a></li>
                    <li><a href="authors.php"><i class="fas fa-user-edit"></i> Author Assignment</a></li>
                    <li><a href="committees.php"><i class="fas fa-landmark"></i> Committee Management</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Support Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Assignment Guide</a></li>
                    <li><a href="#"><i class="fas fa-video"></i> Video Tutorials</a></li>
                    <li><a href="#"><i class="fas fa-phone-alt"></i> Technical Support</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Feedback & Suggestions</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Author & Committee Assignment Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All assignment activities are logged.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Assignment Module | Secure Session Active | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
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
        
        // Tab navigation
        const tabs = document.querySelectorAll('.tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Remove active class from all tabs and contents
                tabs.forEach(t => t.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                document.getElementById(`${tabId}Tab`).classList.add('active');
                
                // Update hidden inputs based on active tab
                if (tabId === 'committees') {
                    document.getElementById('committeeDocumentIdInput').value = document.getElementById('documentIdInput').value;
                    document.getElementById('committeeDocumentTypeInput').value = document.getElementById('documentTypeInput').value;
                }
            });
        });
        
        // Document selection
        const documentCards = document.querySelectorAll('.document-card');
        const documentIdInput = document.getElementById('documentIdInput');
        const documentTypeInput = document.getElementById('documentTypeInput');
        const committeeDocumentIdInput = document.getElementById('committeeDocumentIdInput');
        const committeeDocumentTypeInput = document.getElementById('committeeDocumentTypeInput');
        
        const authorSelectionContainer = document.getElementById('authorSelectionContainer');
        const committeeSelectionContainer = document.getElementById('committeeSelectionContainer');
        const noAuthorsMessage = document.getElementById('noAuthorsMessage');
        const noCommitteesMessage = document.getElementById('noCommitteesMessage');
        
        // Available data
        const availableUsers = <?php echo json_encode($available_users); ?>;
        const availableCommittees = <?php echo json_encode($committees); ?>;
        
        // Track selected document
        let selectedDocument = null;
        
        documentCards.forEach(card => {
            card.addEventListener('click', function() {
                // Remove selected class from all cards
                documentCards.forEach(c => c.classList.remove('selected'));
                
                // Add selected class to clicked card
                this.classList.add('selected');
                
                // Update radio button
                const radio = this.querySelector('.document-radio');
                if (radio) {
                    radio.checked = true;
                }
                
                // Get document info
                selectedDocument = {
                    id: this.getAttribute('data-document-id'),
                    type: this.getAttribute('data-document-type'),
                    docNumber: this.getAttribute('data-doc-number')
                };
                
                // Update hidden inputs for both forms
                documentIdInput.value = selectedDocument.id;
                documentTypeInput.value = selectedDocument.type;
                committeeDocumentIdInput.value = selectedDocument.id;
                committeeDocumentTypeInput.value = selectedDocument.type;
                
                // Load existing assignments for this document via AJAX
                loadDocumentAssignments(selectedDocument.id, selectedDocument.type);
            });
        });
        
        // Load existing assignments for a document via AJAX
        function loadDocumentAssignments(documentId, documentType) {
            // Clear current authors
            const authorRows = authorSelectionContainer.querySelectorAll('.author-row:not(#authorRowTemplate)');
            authorRows.forEach(row => row.remove());
            
            // Clear current committees
            const committeeRows = committeeSelectionContainer.querySelectorAll('.committee-row:not(#committeeRowTemplate)');
            committeeRows.forEach(row => row.remove());
            
            // Show loading message
            noAuthorsMessage.style.display = 'block';
            noAuthorsMessage.innerHTML = `
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                <h3 style="color: var(--gray-dark); margin-bottom: 10px;">Loading Assignments...</h3>
                <p>Fetching existing authors and committees for this document.</p>
            `;
            
            noCommitteesMessage.style.display = 'block';
            noCommitteesMessage.innerHTML = `
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                <h3 style="color: var(--gray-dark); margin-bottom: 10px;">Loading Assignments...</h3>
                <p>Fetching existing committees for this document.</p>
            `;
            
            // Make AJAX request to get existing assignments
            fetch(`authors.php?action=get_assignments&doc_id=${documentId}&doc_type=${documentType}`)
                .then(response => response.json())
                .then(data => {
                    // Load existing authors
                    if (data.authors && data.authors.length > 0) {
                        noAuthorsMessage.style.display = 'none';
                        data.authors.forEach(author => {
                            addAuthorRow();
                            const rows = authorSelectionContainer.querySelectorAll('.author-row:not(#authorRowTemplate)');
                            const lastRow = rows[rows.length - 1];
                            
                            // Set the values
                            const authorSelect = lastRow.querySelector('select[name="authors[]"]');
                            const roleSelect = lastRow.querySelector('select[name="roles[]"]');
                            const authorAvatar = lastRow.querySelector('.author-avatar');
                            const authorDetails = lastRow.querySelector('.author-details');
                            const authorName = lastRow.querySelector('.author-name');
                            const authorRole = lastRow.querySelector('.author-role');
                            const authorDepartment = lastRow.querySelector('.author-department');
                            
                            // Find the user in available users
                            const user = availableUsers.find(u => u.id == author.user_id);
                            if (user) {
                                authorSelect.value = author.user_id;
                                
                                // Update avatar with initials
                                const initials = user.first_name[0] + user.last_name[0];
                                authorAvatar.textContent = initials.toUpperCase();
                                authorAvatar.style.display = 'flex';
                                
                                // Update details
                                authorName.textContent = `${user.first_name} ${user.last_name}`;
                                authorRole.textContent = user.role.replace('_', ' ');
                                authorDepartment.textContent = user.department;
                                
                                // Show the details
                                authorDetails.style.display = 'block';
                                
                                // Set role
                                roleSelect.value = author.role;
                            }
                        });
                    } else {
                        noAuthorsMessage.style.display = 'block';
                        noAuthorsMessage.innerHTML = `
                            <i class="fas fa-user-plus" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                            <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Authors Selected</h3>
                            <p>Click "Add Author" to assign authors to this document.</p>
                        `;
                    }
                    
                    // Load existing committees
                    if (data.committees && data.committees.length > 0) {
                        noCommitteesMessage.style.display = 'none';
                        data.committees.forEach(committee => {
                            addCommitteeRow();
                            const rows = committeeSelectionContainer.querySelectorAll('.committee-row:not(#committeeRowTemplate)');
                            const lastRow = rows[rows.length - 1];
                            
                            // Set the values
                            const committeeSelect = lastRow.querySelector('select[name="committees[]"]');
                            const assignmentTypeSelect = lastRow.querySelector('select[name="assignment_types[]"]');
                            const statusSelect = lastRow.querySelector('select[name="committee_statuses[]"]');
                            const commentInput = lastRow.querySelector('textarea[name="committee_comments[]"]');
                            const committeeIcon = lastRow.querySelector('.committee-icon');
                            const committeeDetails = lastRow.querySelector('.committee-details');
                            const committeeName = lastRow.querySelector('.committee-name');
                            const committeeCode = lastRow.querySelector('.committee-code');
                            const committeeChairperson = lastRow.querySelector('.committee-chairperson');
                            
                            // Find the committee in available committees
                            const committeeData = availableCommittees.find(c => c.id == committee.committee_id);
                            if (committeeData) {
                                committeeSelect.value = committee.committee_id;
                                
                                // Update icon with committee code
                                committeeIcon.textContent = committeeData.committee_code.substring(0, 2).toUpperCase();
                                committeeIcon.style.display = 'flex';
                                
                                // Update details
                                committeeName.textContent = committeeData.committee_name;
                                committeeCode.textContent = committeeData.committee_code;
                                committeeChairperson.textContent = `Chair: ${committeeData.chairperson_name}`;
                                
                                // Show the details
                                committeeDetails.style.display = 'block';
                                
                                // Set assignment type and status
                                assignmentTypeSelect.value = committee.assignment_type;
                                statusSelect.value = committee.status;
                                
                                // Set comments if any
                                if (committee.comments) {
                                    commentInput.value = committee.comments;
                                }
                            }
                        });
                    } else {
                        noCommitteesMessage.style.display = 'block';
                        noCommitteesMessage.innerHTML = `
                            <i class="fas fa-landmark" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                            <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Committees Selected</h3>
                            <p>Click "Add Committee" to assign committees to this document.</p>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading assignments:', error);
                    noAuthorsMessage.innerHTML = `
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 15px; color: var(--red);"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">Error Loading Assignments</h3>
                        <p>Could not load existing assignments. Please try again.</p>
                    `;
                    noCommitteesMessage.innerHTML = `
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 15px; color: var(--red);"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">Error Loading Assignments</h3>
                        <p>Could not load existing assignments. Please try again.</p>
                    `;
                });
            
            // Enable add buttons
            document.querySelectorAll('.add-btn').forEach(btn => btn.disabled = false);
        }
        
        // Add author row
        function addAuthorRow() {
            // Hide no authors message
            noAuthorsMessage.style.display = 'none';
            
            // Clone template
            const template = document.getElementById('authorRowTemplate');
            const newRow = template.cloneNode(true);
            newRow.id = '';
            newRow.style.display = 'grid';
            
            // Add event listener to update info when author is selected
            const authorSelect = newRow.querySelector('select[name="authors[]"]');
            const authorAvatar = newRow.querySelector('.author-avatar');
            const authorDetails = newRow.querySelector('.author-details');
            const authorName = newRow.querySelector('.author-name');
            const authorRole = newRow.querySelector('.author-role');
            const authorDepartment = newRow.querySelector('.author-department');
            
            authorSelect.addEventListener('change', function() {
                const selectedUserId = this.value;
                const selectedUser = availableUsers.find(u => u.id == selectedUserId);
                
                if (selectedUser) {
                    // Update avatar with initials
                    const initials = selectedUser.first_name[0] + selectedUser.last_name[0];
                    authorAvatar.textContent = initials.toUpperCase();
                    authorAvatar.style.display = 'flex';
                    
                    // Update details
                    authorName.textContent = `${selectedUser.first_name} ${selectedUser.last_name}`;
                    authorRole.textContent = selectedUser.role.replace('_', ' ');
                    authorDepartment.textContent = selectedUser.department;
                    
                    // Show the details
                    authorDetails.style.display = 'block';
                } else {
                    // Hide details if no user selected
                    authorAvatar.style.display = 'none';
                    authorDetails.style.display = 'none';
                }
            });
            
            // Add to container
            authorSelectionContainer.appendChild(newRow);
            
            // Scroll to new row
            newRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        // Add committee row
        function addCommitteeRow() {
            // Hide no committees message
            noCommitteesMessage.style.display = 'none';
            
            // Clone template
            const template = document.getElementById('committeeRowTemplate');
            const newRow = template.cloneNode(true);
            newRow.id = '';
            newRow.style.display = 'grid';
            
            // Add event listener to update info when committee is selected
            const committeeSelect = newRow.querySelector('select[name="committees[]"]');
            const committeeIcon = newRow.querySelector('.committee-icon');
            const committeeDetails = newRow.querySelector('.committee-details');
            const committeeName = newRow.querySelector('.committee-name');
            const committeeCode = newRow.querySelector('.committee-code');
            const committeeChairperson = newRow.querySelector('.committee-chairperson');
            const commentInput = newRow.querySelector('.committee-comment-input');
            
            committeeSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const committeeId = this.value;
                
                if (committeeId) {
                    const selectedCommittee = availableCommittees.find(c => c.id == committeeId);
                    
                    if (selectedCommittee) {
                        // Update icon with committee code
                        committeeIcon.textContent = selectedCommittee.committee_code.substring(0, 2).toUpperCase();
                        committeeIcon.style.display = 'flex';
                        
                        // Update details
                        committeeName.textContent = selectedCommittee.committee_name;
                        committeeCode.textContent = selectedCommittee.committee_code;
                        committeeChairperson.textContent = `Chair: ${selectedCommittee.chairperson_name}`;
                        
                        // Show the details
                        committeeDetails.style.display = 'block';
                        
                        // Add show comments button
                        if (!newRow.querySelector('.show-comments-btn')) {
                            const showCommentsBtn = document.createElement('button');
                            showCommentsBtn.type = 'button';
                            showCommentsBtn.className = 'show-comments-btn';
                            showCommentsBtn.innerHTML = '<i class="fas fa-comment"></i> Add Comments';
                            showCommentsBtn.addEventListener('click', function() {
                                commentInput.classList.toggle('active');
                                this.innerHTML = commentInput.classList.contains('active') 
                                    ? '<i class="fas fa-times"></i> Hide Comments' 
                                    : '<i class="fas fa-comment"></i> Add Comments';
                            });
                            committeeDetails.appendChild(showCommentsBtn);
                        }
                    }
                } else {
                    // Hide details if no committee selected
                    committeeIcon.style.display = 'none';
                    committeeDetails.style.display = 'none';
                    
                    // Remove show comments button if exists
                    const showCommentsBtn = newRow.querySelector('.show-comments-btn');
                    if (showCommentsBtn) {
                        showCommentsBtn.remove();
                    }
                }
            });
            
            // Add to container
            committeeSelectionContainer.appendChild(newRow);
            
            // Scroll to new row
            newRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        // Remove author row
        function removeAuthorRow(button) {
            const row = button.closest('.author-row');
            row.remove();
            
            // Show no authors message if no rows left
            const authorRows = authorSelectionContainer.querySelectorAll('.author-row:not(#authorRowTemplate)');
            if (authorRows.length === 0) {
                noAuthorsMessage.style.display = 'block';
            }
        }
        
        // Remove committee row
        function removeCommitteeRow(button) {
            const row = button.closest('.committee-row');
            row.remove();
            
            // Show no committees message if no rows left
            const committeeRows = committeeSelectionContainer.querySelectorAll('.committee-row:not(#committeeRowTemplate)');
            if (committeeRows.length === 0) {
                noCommitteesMessage.style.display = 'block';
            }
        }
        
        // Clear form
        function clearForm(formType) {
            if (confirm('Are you sure you want to clear the form? All unsaved changes will be lost.')) {
                if (formType === 'authors') {
                    // Clear author rows
                    const authorRows = authorSelectionContainer.querySelectorAll('.author-row:not(#authorRowTemplate)');
                    authorRows.forEach(row => row.remove());
                    
                    // Show no authors message
                    noAuthorsMessage.style.display = 'block';
                    noAuthorsMessage.innerHTML = `
                        <i class="fas fa-user-plus" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Authors Selected</h3>
                        <p>Click "Add Author" to assign authors to this document.</p>
                    `;
                } else if (formType === 'committees') {
                    // Clear committee rows
                    const committeeRows = committeeSelectionContainer.querySelectorAll('.committee-row:not(#committeeRowTemplate)');
                    committeeRows.forEach(row => row.remove());
                    
                    // Show no committees message
                    noCommitteesMessage.style.display = 'block';
                    noCommitteesMessage.innerHTML = `
                        <i class="fas fa-landmark" style="font-size: 3rem; margin-bottom: 15px; color: var(--gray-light);"></i>
                        <h3 style="color: var(--gray-dark); margin-bottom: 10px;">No Committees Selected</h3>
                        <p>Click "Add Committee" to assign committees to this document.</p>
                    `;
                }
            }
        }
        
        // Select unassigned document
        function selectUnassignedDoc(docId, docType, tab) {
            // Find and click the corresponding document card
            const docCard = document.querySelector(`.document-card[data-document-id="${docId}"][data-document-type="${docType}"]`);
            if (docCard) {
                docCard.click();
                
                // Switch to appropriate tab
                const tabElement = document.querySelector(`.tab[data-tab="${tab}"]`);
                if (tabElement) {
                    tabElement.click();
                }
                
                // Scroll to document selection
                docCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // Highlight the card
                docCard.style.animation = 'none';
                setTimeout(() => {
                    docCard.style.animation = 'pulse 1s';
                }, 10);
            }
        }
        
        // Form validation
        document.getElementById('authorAssignmentForm').addEventListener('submit', function(e) {
            if (!documentIdInput.value || !documentTypeInput.value) {
                e.preventDefault();
                alert('Please select a document first.');
                return;
            }
            
            const authorRows = authorSelectionContainer.querySelectorAll('.author-row:not(#authorRowTemplate)');
            if (authorRows.length === 0) {
                e.preventDefault();
                alert('Please add at least one author.');
                return;
            }
            
            // Validate that all authors have been selected
            const authorSelects = this.querySelectorAll('select[name="authors[]"]');
            for (const select of authorSelects) {
                if (!select.value) {
                    e.preventDefault();
                    alert('Please select an author for all rows.');
                    select.focus();
                    return;
                }
            }
            
            if (!confirm('Are you sure you want to assign these authors?')) {
                e.preventDefault();
            }
        });
        
        document.getElementById('committeeAssignmentForm').addEventListener('submit', function(e) {
            if (!committeeDocumentIdInput.value || !committeeDocumentTypeInput.value) {
                e.preventDefault();
                alert('Please select a document first.');
                return;
            }
            
            const committeeRows = committeeSelectionContainer.querySelectorAll('.committee-row:not(#committeeRowTemplate)');
            if (committeeRows.length === 0) {
                e.preventDefault();
                alert('Please add at least one committee.');
                return;
            }
            
            // Validate that all committees have been selected
            const committeeSelects = this.querySelectorAll('select[name="committees[]"]');
            for (const select of committeeSelects) {
                if (!select.value) {
                    e.preventDefault();
                    alert('Please select a committee for all rows.');
                    select.focus();
                    return;
                }
            }
            
            if (!confirm('Are you sure you want to assign these committees?')) {
                e.preventDefault();
            }
        });
        
        document.getElementById('bulkAssignmentForm').addEventListener('submit', function(e) {
            const selectedDocs = this.querySelectorAll('input[name="bulk_documents[]"]:checked');
            const selectedAuthors = this.querySelectorAll('input[name="bulk_authors[]"]:checked');
            
            if (selectedDocs.length === 0) {
                e.preventDefault();
                alert('Please select at least one document for bulk assignment.');
                return;
            }
            
            if (selectedAuthors.length === 0) {
                e.preventDefault();
                alert('Please select at least one author for bulk assignment.');
                return;
            }
            
            if (!confirm(`Are you sure you want to assign ${selectedAuthors.length} author(s) to ${selectedDocs.length} document(s)?`)) {
                e.preventDefault();
            }
        });
        
        // Add pulse animation for highlighting
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { box-shadow: 0 0 0 0 rgba(212, 175, 55, 0.7); }
                70% { box-shadow: 0 0 0 10px rgba(212, 175, 55, 0); }
                100% { box-shadow: 0 0 0 0 rgba(212, 175, 55, 0); }
            }
        `;
        document.head.appendChild(style);
        
        // Initialize with first document selected if available
        document.addEventListener('DOMContentLoaded', function() {
            if (documentCards.length > 0) {
                documentCards[0].click();
            }
            
            // Add animation to form elements
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
            document.querySelectorAll('.section, .bulk-assignment').forEach(section => {
                observer.observe(section);
            });
        });
    </script>
</body>
</html>