<?php
// templates.php - Template Selection Module
session_start();

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user has permission to access templates
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

// Handle template actions
$success_message = '';
$error_message = '';

// Handle template preview
if (isset($_GET['preview'])) {
    $template_id = $_GET['preview'];
    $query = "SELECT * FROM document_templates WHERE id = :id AND is_active = 1";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $template_id);
    $stmt->execute();
    $template = $stmt->fetch();
    
    if ($template) {
        // Return JSON instead of HTML for AJAX
        header('Content-Type: application/json');
        echo json_encode($template);
        exit();
    }
}

// Handle template use
if (isset($_POST['use_template'])) {
    $template_id = $_POST['template_id'];
    $document_type = $_POST['document_type'];
    
    // Record template usage
    $usage_query = "INSERT INTO template_usage (template_id, user_id, document_type) 
                   VALUES (:template_id, :user_id, :doc_type)";
    $stmt = $conn->prepare($usage_query);
    $stmt->bindParam(':template_id', $template_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':doc_type', $document_type);
    
    if ($stmt->execute()) {
        // Update use count
        $update_query = "UPDATE document_templates SET use_count = use_count + 1, 
                         last_used = NOW() WHERE id = :template_id";
        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':template_id', $template_id);
        $stmt->execute();
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'TEMPLATE_USE', 'Used template ID: {$template_id}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        // Redirect to draft creation with template
        header("Location: draft_creation.php?template={$template_id}&type={$document_type}");
        exit();
    } else {
        $error_message = "Error using template. Please try again.";
    }
}

// Handle add to favorites
if (isset($_POST['add_to_favorites'])) {
    $template_id = $_POST['template_id'];
    
    $check_query = "SELECT * FROM template_favorites WHERE user_id = :user_id AND template_id = :template_id";
    $stmt = $conn->prepare($check_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':template_id', $template_id);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        $favorite_query = "INSERT INTO template_favorites (user_id, template_id) 
                          VALUES (:user_id, :template_id)";
        $stmt = $conn->prepare($favorite_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':template_id', $template_id);
        
        if ($stmt->execute()) {
            $success_message = "Template added to favorites!";
            
            // Log the action
            $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                           VALUES (:user_id, 'TEMPLATE_FAVORITE', 'Added template to favorites: {$template_id}', :ip, :agent)";
            $stmt = $conn->prepare($audit_query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
            $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
            $stmt->execute();
        } else {
            $error_message = "Error adding to favorites. Please try again.";
        }
    } else {
        $error_message = "Template already in favorites.";
    }
}

// Handle remove from favorites
if (isset($_POST['remove_favorite'])) {
    $template_id = $_POST['template_id'];
    
    $remove_query = "DELETE FROM template_favorites WHERE user_id = :user_id AND template_id = :template_id";
    $stmt = $conn->prepare($remove_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':template_id', $template_id);
    
    if ($stmt->execute()) {
        $success_message = "Template removed from favorites!";
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'TEMPLATE_UNFAVORITE', 'Removed template from favorites: {$template_id}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
    } else {
        $error_message = "Error removing from favorites. Please try again.";
    }
}

// Handle create new template (admin only)
if (isset($_POST['create_template']) && in_array($user_role, ['super_admin', 'admin'])) {
    $template_name = $_POST['template_name'];
    $template_type = $_POST['template_type'];
    $category = $_POST['category'];
    $description = $_POST['description'];
    $content = $_POST['content'];
    
    $query = "INSERT INTO document_templates (template_name, template_type, category, description, content, created_by) 
             VALUES (:name, :type, :category, :description, :content, :created_by)";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':name', $template_name);
    $stmt->bindParam(':type', $template_type);
    $stmt->bindParam(':category', $category);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':content', $content);
    $stmt->bindParam(':created_by', $user_id);
    
    if ($stmt->execute()) {
        $template_id = $conn->lastInsertId();
        $success_message = "Template created successfully!";
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'TEMPLATE_CREATE', 'Created new template: {$template_name}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        // Generate PDF for the new template
        generateTemplatePDF($template_id, $conn);
    } else {
        $error_message = "Error creating template. Please try again.";
    }
}

// Handle update template (admin only)
if (isset($_POST['update_template']) && in_array($user_role, ['super_admin', 'admin'])) {
    $template_id = $_POST['template_id'];
    $template_name = $_POST['template_name'];
    $template_type = $_POST['template_type'];
    $category = $_POST['category'];
    $description = $_POST['description'];
    $content = $_POST['content'];
    $is_active = $_POST['is_active'] ?? 1;
    
    $query = "UPDATE document_templates SET 
              template_name = :name,
              template_type = :type,
              category = :category,
              description = :description,
              content = :content,
              is_active = :active,
              updated_at = NOW()
              WHERE id = :id";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':name', $template_name);
    $stmt->bindParam(':type', $template_type);
    $stmt->bindParam(':category', $category);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':content', $content);
    $stmt->bindParam(':active', $is_active);
    $stmt->bindParam(':id', $template_id);
    
    if ($stmt->execute()) {
        $success_message = "Template updated successfully!";
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'TEMPLATE_UPDATE', 'Updated template: {$template_name}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
        
        // Regenerate PDF for the updated template
        generateTemplatePDF($template_id, $conn);
    } else {
        $error_message = "Error updating template. Please try again.";
    }
}

// Handle delete template (admin only)
if (isset($_POST['delete_template']) && in_array($user_role, ['super_admin', 'admin'])) {
    $template_id = $_POST['template_id'];
    
    // Get template name for logging
    $get_query = "SELECT template_name FROM document_templates WHERE id = :id";
    $stmt = $conn->prepare($get_query);
    $stmt->bindParam(':id', $template_id);
    $stmt->execute();
    $template = $stmt->fetch();
    
    // Soft delete (deactivate)
    $query = "UPDATE document_templates SET is_active = 0, updated_at = NOW() WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $template_id);
    
    if ($stmt->execute()) {
        $success_message = "Template deactivated successfully!";
        
        // Log the action
        $audit_query = "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) 
                       VALUES (:user_id, 'TEMPLATE_DELETE', 'Deactivated template: {$template['template_name']}', :ip, :agent)";
        $stmt = $conn->prepare($audit_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $stmt->bindParam(':agent', $_SERVER['HTTP_USER_AGENT']);
        $stmt->execute();
    } else {
        $error_message = "Error deactivating template. Please try again.";
    }
}

// Function to generate PDF for template
function generateTemplatePDF($template_id, $conn) {
    // Get template data
    $query = "SELECT * FROM document_templates WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $template_id);
    $stmt->execute();
    $template = $stmt->fetch();
    
    if (!$template) return;
    
    // Create PDF directory if it doesn't exist
    $pdf_dir = dirname(__FILE__) . '/../uploads/template_pdfs/';
    if (!file_exists($pdf_dir)) {
        mkdir($pdf_dir, 0755, true);
    }
    
    // Create PDF based on template type
    if ($template['template_type'] === 'ordinance') {
        $pdf_path = generateOrdinancePDF($template, $pdf_dir, $conn);
    } else {
        $pdf_path = generateResolutionPDF($template, $pdf_dir, $conn);
    }
    
    return $pdf_path;
}

// Function to generate Ordinance PDF
function generateOrdinancePDF($template, $pdf_dir, $conn) {
    require_once('../vendor/autoload.php');
    
    $pdf = new FPDF();
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'QUEZON CITY GOVERNMENT', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'SANGGUNIANG PANLUNGSOD', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'CITY ORDINANCE NO. ______', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Template name
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, $template['template_name'], 0, 1, 'C');
    $pdf->Ln(5);
    
    // Category
    $pdf->SetFont('Arial', 'I', 12);
    $pdf->Cell(0, 10, 'Category: ' . $template['category'], 0, 1, 'C');
    $pdf->Ln(10);
    
    // Description
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 8, $template['description']);
    $pdf->Ln(10);
    
    // Content (strip HTML tags for PDF)
    $clean_content = strip_tags($template['content']);
    $pdf->MultiCell(0, 8, $clean_content);
    
    // Footer
    $pdf->Ln(20);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'ENACTED by the Sangguniang Panlungsod of Quezon City', 0, 1, 'C');
    $pdf->Cell(0, 10, 'in a regular session assembled.', 0, 1, 'C');
    $pdf->Ln(10);
    $pdf->Cell(0, 10, '[DATE OF APPROVAL]', 0, 1, 'C');
    $pdf->Ln(15);
    $pdf->Cell(0, 10, '_________________________', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'City Vice Mayor & Presiding Officer', 0, 1, 'C');
    
    // Save PDF
    $pdf_path = $pdf_dir . 'template_' . $template['id'] . '.pdf';
    
    // Ensure directory exists
    if (!file_exists(dirname($pdf_path))) {
        mkdir(dirname($pdf_path), 0755, true);
    }
    
    try {
        $pdf->Output('F', $pdf_path);
        
        // Update database with PDF path (relative path for web access)
        $web_path = '../uploads/template_pdfs/template_' . $template['id'] . '.pdf';
        $update_query = "UPDATE document_templates SET thumbnail = :pdf_path WHERE id = :id";
        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':pdf_path', $web_path);
        $stmt->bindParam(':id', $template['id']);
        $stmt->execute();
        
        return $web_path;
    } catch (Exception $e) {
        error_log("Error generating PDF: " . $e->getMessage());
        return null;
    }
}

// Function to generate Resolution PDF
function generateResolutionPDF($template, $pdf_dir, $conn) {
    require_once('../vendor/autoload.php');
    
    $pdf = new FPDF();
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'QUEZON CITY GOVERNMENT', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'SANGGUNIANG PANLUNGSOD', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'RESOLUTION NO. ______', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Template name
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, $template['template_name'], 0, 1, 'C');
    $pdf->Ln(5);
    
    // Category
    $pdf->SetFont('Arial', 'I', 12);
    $pdf->Cell(0, 10, 'Category: ' . $template['category'], 0, 1, 'C');
    $pdf->Ln(10);
    
    // Description
    $pdf->SetFont('Arial', '', 12);
    $pdf->MultiCell(0, 8, $template['description']);
    $pdf->Ln(10);
    
    // Content (strip HTML tags for PDF)
    $clean_content = strip_tags($template['content']);
    $pdf->MultiCell(0, 8, $clean_content);
    
    // Footer
    $pdf->Ln(20);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'RESOLVED this ______ day of ____________, 20___.', 0, 1, 'C');
    $pdf->Ln(15);
    $pdf->Cell(0, 10, '_________________________', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'City Vice Mayor & Presiding Officer', 0, 1, 'C');
    $pdf->Ln(10);
    $pdf->Cell(0, 10, 'ATTESTED:', 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->Cell(0, 10, '_________________________', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Secretary to the Sanggunian', 0, 1, 'C');
    
    // Save PDF
    $pdf_path = $pdf_dir . 'template_' . $template['id'] . '.pdf';
    
    // Ensure directory exists
    if (!file_exists(dirname($pdf_path))) {
        mkdir(dirname($pdf_path), 0755, true);
    }
    
    try {
        $pdf->Output('F', $pdf_path);
        
        // Update database with PDF path (relative path for web access)
        $web_path = '../uploads/template_pdfs/template_' . $template['id'] . '.pdf';
        $update_query = "UPDATE document_templates SET thumbnail = :pdf_path WHERE id = :id";
        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':pdf_path', $web_path);
        $stmt->bindParam(':id', $template['id']);
        $stmt->execute();
        
        return $web_path;
    } catch (Exception $e) {
        error_log("Error generating PDF: " . $e->getMessage());
        return null;
    }
}

// Get templates with filters
$document_type_filter = $_GET['type'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$sort_by = $_GET['sort'] ?? 'popular';
$search_query = $_GET['search'] ?? '';

// Pagination variables
$templates_per_page = 4;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $templates_per_page;

// Build base query for counting total templates
$count_query = "SELECT COUNT(*) as total FROM document_templates t WHERE t.is_active = 1";

// Build base query for fetching templates
$query = "SELECT t.*, 
          CONCAT(u.first_name, ' ', u.last_name) as creator_name,
          COALESCE(fav_count.favorite_count, 0) as favorite_count,
          CASE WHEN user_fav.template_id IS NOT NULL THEN 1 ELSE 0 END as is_favorite
          FROM document_templates t
          LEFT JOIN users u ON t.created_by = u.id
          LEFT JOIN (
              SELECT template_id, COUNT(*) as favorite_count 
              FROM template_favorites 
              GROUP BY template_id
          ) fav_count ON t.id = fav_count.template_id
          LEFT JOIN (
              SELECT template_id 
              FROM template_favorites 
              WHERE user_id = :user_id
          ) user_fav ON t.id = user_fav.template_id
          WHERE t.is_active = 1";

// Add filters to both queries
$params = [':user_id' => $user_id];
$count_params = [];

if ($document_type_filter !== 'all') {
    $query .= " AND t.template_type = :doc_type";
    $count_query .= " AND t.template_type = :doc_type";
    $params[':doc_type'] = $document_type_filter;
    $count_params[':doc_type'] = $document_type_filter;
}

if ($category_filter !== 'all') {
    $query .= " AND t.category = :category";
    $count_query .= " AND t.category = :category";
    $params[':category'] = $category_filter;
    $count_params[':category'] = $category_filter;
}

if ($search_query) {
    $query .= " AND (t.template_name LIKE :search OR t.description LIKE :search OR t.category LIKE :search)";
    $count_query .= " AND (t.template_name LIKE :search OR t.description LIKE :search OR t.category LIKE :search)";
    $search_param = "%{$search_query}%";
    $params[':search'] = $search_param;
    $count_params[':search'] = $search_param;
}

// Add sorting
switch ($sort_by) {
    case 'name':
        $query .= " ORDER BY t.template_name ASC";
        break;
    case 'newest':
        $query .= " ORDER BY t.created_at DESC";
        break;
    case 'oldest':
        $query .= " ORDER BY t.created_at ASC";
        break;
    case 'popular':
    default:
        $query .= " ORDER BY t.use_count DESC, t.created_at DESC";
        break;
}

// Add pagination to main query
$query .= " LIMIT :limit OFFSET :offset";

// Get total count
$count_stmt = $conn->prepare($count_query);
foreach ($count_params as $key => $value) {
    $count_stmt->bindParam($key, $value);
}
$count_stmt->execute();
$total_templates = $count_stmt->fetchColumn();
$total_pages = ceil($total_templates / $templates_per_page);

// Get paginated templates
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindParam($key, $value);
}
$stmt->bindParam(':limit', $templates_per_page, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$templates = $stmt->fetchAll();

// Get user's favorite templates (without pagination)
$favorites_query = "SELECT t.*, 
                    COALESCE(fc.favorite_count, 0) as favorite_count 
                    FROM document_templates t
                    INNER JOIN template_favorites f ON t.id = f.template_id
                    LEFT JOIN (
                        SELECT template_id, COUNT(*) as favorite_count 
                        FROM template_favorites 
                        GROUP BY template_id
                    ) fc ON t.id = fc.template_id
                    WHERE f.user_id = :user_id AND t.is_active = 1
                    ORDER BY f.created_at DESC
                    LIMIT 4";
$favorites_stmt = $conn->prepare($favorites_query);
$favorites_stmt->bindParam(':user_id', $user_id);
$favorites_stmt->execute();
$favorite_templates = $favorites_stmt->fetchAll();

// Get template categories
$categories_query = "SELECT DISTINCT category FROM document_templates WHERE is_active = 1 ORDER BY category";
$categories_stmt = $conn->query($categories_query);
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get template usage statistics
$stats_query = "SELECT 
               COUNT(*) as total_templates,
               SUM(use_count) as total_uses,
               AVG(use_count) as avg_uses,
               COUNT(CASE WHEN template_type = 'ordinance' THEN 1 END) as ordinance_templates,
               COUNT(CASE WHEN template_type = 'resolution' THEN 1 END) as resolution_templates
               FROM document_templates 
               WHERE is_active = 1";
$stats_stmt = $conn->query($stats_query);
$stats = $stats_stmt->fetch();

// Get user's template usage
$user_stats_query = "SELECT 
                    COUNT(*) as templates_used,
                    COUNT(DISTINCT t.template_type) as types_used
                    FROM template_usage tu
                    INNER JOIN document_templates t ON tu.template_id = t.id
                    WHERE tu.user_id = :user_id";
$user_stats_stmt = $conn->prepare($user_stats_query);
$user_stats_stmt->bindParam(':user_id', $user_id);
$user_stats_stmt->execute();
$user_stats = $user_stats_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Template Selection | QC Ordinance Tracker</title>
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
            --orange: #F59E0B;
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
        
        /* FILTERS AND CONTROLS */
        .controls-container {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-md);
            margin-bottom: 30px;
            border: 1px solid var(--gray-light);
        }
        
        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .control-group {
            margin-bottom: 0;
        }
        
        .control-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.95rem;
        }
        
        .control-select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-family: 'Times New Roman', Times, serif;
            background: var(--white);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .control-select:focus {
            outline: none;
            border-color: var(--qc-blue);
            box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.1);
        }
        
        .search-box {
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 1rem;
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        /* TEMPLATES GRID */
        .templates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        @media (max-width: 768px) {
            .templates-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .template-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .template-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            border-color: var(--qc-gold);
        }
        
        .template-card.featured {
            border: 2px solid var(--qc-gold);
        }
        
        .template-header {
            background: linear-gradient(135deg, var(--qc-blue) 0%, var(--qc-blue-dark) 100%);
            color: var(--white);
            padding: 25px;
            position: relative;
            overflow: hidden;
        }
        
        .template-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 10px,
                rgba(255, 255, 255, 0.05) 10px,
                rgba(255, 255, 255, 0.05) 20px
            );
        }
        
        .template-type-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-ordinance {
            background: rgba(212, 175, 55, 0.9);
            color: var(--qc-blue-dark);
        }
        
        .badge-resolution {
            background: rgba(45, 140, 71, 0.9);
            color: var(--white);
        }
        
        .template-icon {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
            border: 2px solid rgba(212, 175, 55, 0.3);
        }
        
        .template-title {
            font-size: 1.3rem;
            font-weight: bold;
            margin-bottom: 8px;
            line-height: 1.3;
        }
        
        .template-category {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .template-body {
            padding: 25px;
        }
        
        .template-description {
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        
        .template-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .meta-item i {
            color: var(--qc-gold);
        }
        
        .template-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-badge {
            flex: 1;
            text-align: center;
            padding: 10px;
            background: var(--off-white);
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--qc-blue);
            line-height: 1;
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 3px;
        }
        
        .template-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }
        
        /* PDF Preview in modal */
        .pdf-preview-container {
            width: 100%;
            height: 500px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            overflow: hidden;
        }
        
        .pdf-preview-container iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        
        /* FAVORITES SECTION */
        .favorites-section {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.1) 0%, rgba(212, 175, 55, 0.05) 100%);
            border: 2px solid var(--qc-gold);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 40px;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .section-icon {
            width: 50px;
            height: 50px;
            background: var(--qc-gold);
            color: var(--qc-blue-dark);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .section-title {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--qc-blue-dark);
        }
        
        .section-subtitle {
            color: var(--gray);
            margin-top: 5px;
        }
        
        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 60px 30px;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            border: 2px dashed var(--gray-light);
        }
        
        .empty-icon {
            font-size: 4rem;
            color: var(--gray-light);
            margin-bottom: 20px;
        }
        
        .empty-title {
            font-size: 1.5rem;
            color: var(--gray-dark);
            margin-bottom: 10px;
        }
        
        .empty-description {
            color: var(--gray);
            max-width: 500px;
            margin: 0 auto 25px;
            line-height: 1.6;
        }
        
        /* PAGINATION STYLES */
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 40px;
            padding: 20px;
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }
        
        .pagination-info {
            margin-right: 20px;
            color: var(--gray-dark);
            font-weight: 500;
        }
        
        .pagination {
            display: flex;
            gap: 10px;
            list-style: none;
        }
        
        .pagination-item {
            display: inline-block;
        }
        
        .pagination-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius);
            background: var(--white);
            color: var(--qc-blue);
            text-decoration: none;
            font-weight: 600;
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
        }
        
        .pagination-link:hover {
            background: var(--qc-blue);
            color: var(--white);
            border-color: var(--qc-blue);
        }
        
        .pagination-link.active {
            background: var(--qc-blue);
            color: var(--white);
            border-color: var(--qc-blue);
        }
        
        .pagination-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-link.disabled:hover {
            background: var(--white);
            color: var(--qc-blue);
            border-color: var(--gray-light);
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
            width: 90%;
            max-width: 1000px;
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
            position: sticky;
            top: 0;
            z-index: 10;
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
        
        .modal-preview {
            background: var(--off-white);
            border-radius: var(--border-radius);
            padding: 20px;
            border: 1px solid var(--gray-light);
            max-height: 500px;
            overflow-y: auto;
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        .modal-preview h1, .modal-preview h2, .modal-preview h3 {
            color: var(--qc-blue);
            margin-top: 20px;
            margin-bottom: 10px;
        }
        
        .modal-preview h1 {
            font-size: 1.5rem;
            border-bottom: 2px solid var(--qc-gold);
            padding-bottom: 10px;
        }
        
        .modal-preview h2 {
            font-size: 1.3rem;
        }
        
        .modal-preview h3 {
            font-size: 1.1rem;
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
        .btn {
            padding: 12px 25px;
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
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 0.9rem;
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
            background: linear-gradient(135deg, var(--red) 0%, #9B2C2C 100%);
            color: var(--white);
        }
        
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--qc-blue);
            color: var(--qc-blue);
        }
        
        .btn-outline:hover {
            background: var(--qc-blue);
            color: var(--white);
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
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
            
            .pagination-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .pagination-info {
                margin-right: 0;
                margin-bottom: 10px;
            }
        }
        
        @media (max-width: 768px) {
            .template-actions {
                grid-template-columns: 1fr;
            }
            
            .controls-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                flex-direction: column;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
                    <p>Template Selection Module | Ordinance & Resolution Templates</p>
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
                        <i class="fas fa-file-alt"></i> Template Module
                    </h3>
                    <p class="sidebar-subtitle">Template Selection Interface</p>
                </div>
                
                <?php include 'sidebar.php'; ?>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- FIXED MODULE HEADER -->
            <div class="module-header fade-in">
                <div class="module-header-content">
                    <div class="module-badge">TEMPLATE SELECTION MODULE</div>
                    
                    <div class="module-title-wrapper">
                        <div class="module-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="module-title">
                            <h1>Template Library & Selection</h1>
                            <p class="module-subtitle">
                                Browse, preview, and select from standard legal templates for ordinances and resolutions. 
                                Use professionally crafted templates to ensure compliance with Quezon City legislative formats.
                            </p>
                        </div>
                    </div>
                    
                    <div class="module-stats">
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-file-contract"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['total_templates']; ?></h3>
                                <p>Available Templates</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-download"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $stats['total_uses']; ?></h3>
                                <p>Total Uses</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $user_stats['templates_used'] ?? 0; ?></h3>
                                <p>Your Template Uses</p>
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

            <!-- Favorites Section -->
            <?php if (!empty($favorite_templates)): ?>
            <div class="favorites-section fade-in">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div>
                        <h2 class="section-title">Your Favorite Templates</h2>
                        <p class="section-subtitle">Quick access to your most-used templates</p>
                    </div>
                </div>
                
                <div class="templates-grid">
                    <?php foreach ($favorite_templates as $template): 
                        $badge_class = 'badge-' . $template['template_type'];
                        $icon = $template['template_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                    ?>
                    <div class="template-card featured">
                        <div class="template-header">
                            <div class="template-type-badge <?php echo $badge_class; ?>">
                                <?php echo ucfirst($template['template_type']); ?>
                            </div>
                            <div class="template-icon">
                                <i class="fas <?php echo $icon; ?>"></i>
                            </div>
                            <h3 class="template-title"><?php echo htmlspecialchars($template['template_name']); ?></h3>
                            <div class="template-category"><?php echo htmlspecialchars($template['category']); ?></div>
                        </div>
                        
                        <div class="template-body">
                            <p class="template-description"><?php echo htmlspecialchars($template['description']); ?></p>
                            
                            <div class="template-meta">
                                <div class="meta-item">
                                    <i class="fas fa-user"></i>
                                    <span><?php echo htmlspecialchars($template['creator_name'] ?? 'System'); ?></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-calendar"></i>
                                    <span><?php echo date('M d, Y', strtotime($template['created_at'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="template-stats">
                                <div class="stat-badge">
                                    <div class="stat-value"><?php echo $template['use_count']; ?></div>
                                    <div class="stat-label">Uses</div>
                                </div>
                                <div class="stat-badge">
                                    <div class="stat-value"><?php echo $template['favorite_count']; ?></div>
                                    <div class="stat-label">Favorites</div>
                                </div>
                            </div>
                            
                            <div class="template-actions">
                                <form method="POST" style="grid-column: span 2;">
                                    <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                    <button type="submit" name="use_template" class="btn btn-success btn-sm">
                                        <i class="fas fa-play"></i> Use Template
                                    </button>
                                    <input type="hidden" name="document_type" value="<?php echo $template['template_type']; ?>">
                                </form>
                                
                                <button type="button" class="btn btn-secondary btn-sm preview-template" 
                                        data-template-id="<?php echo $template['id']; ?>"
                                        data-template-name="<?php echo htmlspecialchars($template['template_name']); ?>"
                                        data-has-pdf="<?php echo !empty($template['thumbnail']) ? '1' : '0'; ?>">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                                
                                <form method="POST">
                                    <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                    <button type="submit" name="remove_favorite" class="btn btn-danger btn-sm">
                                        <i class="fas fa-star"></i> Remove
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters and Controls -->
            <div class="controls-container fade-in">
                <form method="GET" id="filterForm">
                    <input type="hidden" name="page" value="1">
                    <div class="controls-grid">
                        <div class="control-group">
                            <label class="control-label">Document Type</label>
                            <select name="type" class="control-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $document_type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="ordinance" <?php echo $document_type_filter === 'ordinance' ? 'selected' : ''; ?>>Ordinances</option>
                                <option value="resolution" <?php echo $document_type_filter === 'resolution' ? 'selected' : ''; ?>>Resolutions</option>
                            </select>
                        </div>
                        
                        <div class="control-group">
                            <label class="control-label">Category</label>
                            <select name="category" class="control-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" 
                                        <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="control-group">
                            <label class="control-label">Sort By</label>
                            <select name="sort" class="control-select" onchange="this.form.submit()">
                                <option value="popular" <?php echo $sort_by === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                                <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name (A-Z)</option>
                            </select>
                        </div>
                        
                        <div class="control-group search-box">
                            <label class="control-label">Search Templates</label>
                            <input type="text" name="search" class="search-input" 
                                   placeholder="Search by template name or description..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                            <i class="fas fa-search search-icon"></i>
                        </div>
                    </div>
                    
                    <div class="quick-actions">
                        <a href="?type=all&category=all&sort=popular&page=1" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                        
                        <?php if (in_array($user_role, ['super_admin', 'admin'])): ?>
                        <button type="button" class="btn btn-primary" id="createTemplateBtn">
                            <i class="fas fa-plus"></i> Create New Template
                        </button>
                        <?php endif; ?>
                        
                        <a href="draft_creation.php" class="btn btn-success">
                            <i class="fas fa-file-contract"></i> Start New Document
                        </a>
                    </div>
                </form>
            </div>

            <!-- Templates Grid -->
            <div class="templates-container fade-in">
                <?php if (empty($templates)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt empty-icon"></i>
                    <h3 class="empty-title">No Templates Found</h3>
                    <p class="empty-description">
                        No templates match your current filters. Try adjusting your search criteria or 
                        <?php if (in_array($user_role, ['super_admin', 'admin'])): ?>
                        create a new template to get started.
                        <?php else: ?>
                        contact an administrator to add new templates.
                        <?php endif; ?>
                    </p>
                    <a href="?type=all&category=all&sort=popular&page=1" class="btn btn-primary">
                        <i class="fas fa-redo"></i> Show All Templates
                    </a>
                </div>
                <?php else: ?>
                <div class="templates-grid">
                    <?php foreach ($templates as $template): 
                        $badge_class = 'badge-' . $template['template_type'];
                        $icon = $template['template_type'] === 'ordinance' ? 'fa-gavel' : 'fa-file-signature';
                        $is_favorite = $template['is_favorite'] > 0;
                        $has_pdf = !empty($template['thumbnail']);
                    ?>
                    <div class="template-card">
                        <div class="template-header">
                            <div class="template-type-badge <?php echo $badge_class; ?>">
                                <?php echo ucfirst($template['template_type']); ?>
                            </div>
                            <div class="template-icon">
                                <i class="fas <?php echo $icon; ?>"></i>
                            </div>
                            <h3 class="template-title"><?php echo htmlspecialchars($template['template_name']); ?></h3>
                            <div class="template-category"><?php echo htmlspecialchars($template['category']); ?></div>
                        </div>
                        
                        <div class="template-body">
                            <p class="template-description"><?php echo htmlspecialchars($template['description']); ?></p>
                            
                            <?php if ($has_pdf): ?>
                            <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-file-pdf" style="color: var(--red);"></i>
                                <span style="font-size: 0.85rem; color: var(--gray);">
                                    PDF available for preview
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="template-meta">
                                <div class="meta-item">
                                    <i class="fas fa-user"></i>
                                    <span><?php echo htmlspecialchars($template['creator_name'] ?? 'System'); ?></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-calendar"></i>
                                    <span><?php echo date('M d, Y', strtotime($template['created_at'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="template-stats">
                                <div class="stat-badge">
                                    <div class="stat-value"><?php echo $template['use_count']; ?></div>
                                    <div class="stat-label">Uses</div>
                                </div>
                                <div class="stat-badge">
                                    <div class="stat-value"><?php echo $template['favorite_count']; ?></div>
                                    <div class="stat-label">Favorites</div>
                                </div>
                            </div>
                            
                            <div class="template-actions">
                                <form method="POST" style="grid-column: span 2;">
                                    <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                    <button type="submit" name="use_template" class="btn btn-success btn-sm">
                                        <i class="fas fa-play"></i> Use Template
                                    </button>
                                    <input type="hidden" name="document_type" value="<?php echo $template['template_type']; ?>">
                                </form>
                                
                                <button type="button" class="btn btn-secondary btn-sm preview-template" 
                                        data-template-id="<?php echo $template['id']; ?>"
                                        data-template-name="<?php echo htmlspecialchars($template['template_name']); ?>"
                                        data-has-pdf="<?php echo $has_pdf ? '1' : '0'; ?>">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                                
                                <?php if ($is_favorite): ?>
                                <form method="POST">
                                    <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                    <button type="submit" name="remove_favorite" class="btn btn-warning btn-sm">
                                        <i class="fas fa-star"></i> Favorited
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                                    <button type="submit" name="add_to_favorites" class="btn btn-outline btn-sm">
                                        <i class="far fa-star"></i> Favorite
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <?php if (in_array($user_role, ['super_admin', 'admin'])): ?>
                                <button type="button" class="btn btn-secondary btn-sm edit-template" 
                                        data-template-id="<?php echo $template['id']; ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                
                                <button type="button" class="btn btn-danger btn-sm delete-template" 
                                        data-template-id="<?php echo $template['id']; ?>"
                                        data-template-name="<?php echo htmlspecialchars($template['template_name']); ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + count($templates), $total_templates); ?> of <?php echo $total_templates; ?> templates
                    </div>
                    
                    <ul class="pagination">
                        <!-- Previous Button -->
                        <li class="pagination-item">
                            <?php if ($current_page > 1): ?>
                            <a href="?type=<?php echo $document_type_filter; ?>&category=<?php echo $category_filter; ?>&sort=<?php echo $sort_by; ?>&search=<?php echo urlencode($search_query); ?>&page=<?php echo $current_page - 1; ?>" 
                               class="pagination-link">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php else: ?>
                            <span class="pagination-link disabled">
                                <i class="fas fa-chevron-left"></i>
                            </span>
                            <?php endif; ?>
                        </li>
                        
                        <!-- Page Numbers -->
                        <?php 
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++): 
                        ?>
                        <li class="pagination-item">
                            <a href="?type=<?php echo $document_type_filter; ?>&category=<?php echo $category_filter; ?>&sort=<?php echo $sort_by; ?>&search=<?php echo urlencode($search_query); ?>&page=<?php echo $i; ?>" 
                               class="pagination-link <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <!-- Next Button -->
                        <li class="pagination-item">
                            <?php if ($current_page < $total_pages): ?>
                            <a href="?type=<?php echo $document_type_filter; ?>&category=<?php echo $category_filter; ?>&sort=<?php echo $sort_by; ?>&search=<?php echo urlencode($search_query); ?>&page=<?php echo $current_page + 1; ?>" 
                               class="pagination-link">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php else: ?>
                            <span class="pagination-link disabled">
                                <i class="fas fa-chevron-right"></i>
                            </span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- PREVIEW MODAL -->
    <div class="modal-overlay" id="previewModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-eye"></i> Template Preview</h2>
                <button class="modal-close" id="previewModalClose">&times;</button>
            </div>
            <div class="modal-body">
                <h3 id="previewTemplateName" style="color: var(--qc-blue); margin-bottom: 20px;"></h3>
                <div id="previewContent">
                    <!-- Template preview will be loaded here -->
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="previewModalCancel">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button type="button" class="btn btn-primary" id="useFromPreview">
                        <i class="fas fa-play"></i> Use This Template
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- CREATE/EDIT TEMPLATE MODAL (Admin only) -->
    <?php if (in_array($user_role, ['super_admin', 'admin'])): ?>
    <div class="modal-overlay" id="templateModal">
        <div class="modal" style="max-width: 1200px;">
            <div class="modal-header">
                <h2><i class="fas fa-file-alt"></i> <span id="modalTitle">Create New Template</span></h2>
                <button class="modal-close" id="templateModalClose">&times;</button>
            </div>
            <div class="modal-body">
                <form id="templateForm" method="POST">
                    <input type="hidden" name="template_id" id="formTemplateId">
                    <input type="hidden" name="create_template" id="formAction">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
                        <div>
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label class="control-label required">Template Name</label>
                                <input type="text" name="template_name" id="templateName" 
                                       class="control-select" required 
                                       placeholder="Enter template name">
                            </div>
                            
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label class="control-label required">Template Type</label>
                                <select name="template_type" id="templateType" class="control-select" required>
                                    <option value="">Select Type</option>
                                    <option value="ordinance">Ordinance</option>
                                    <option value="resolution">Resolution</option>
                                </select>
                            </div>
                            
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label class="control-label required">Category</label>
                                <input type="text" name="category" id="templateCategory" 
                                       class="control-select" required 
                                       placeholder="e.g., Administrative, Environmental, Finance">
                            </div>
                            
                            <div class="form-group">
                                <label class="control-label">Description</label>
                                <textarea name="description" id="templateDescription" 
                                          class="control-select" 
                                          placeholder="Describe the purpose and usage of this template..." 
                                          rows="4"></textarea>
                            </div>
                        </div>
                        
                        <div>
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label class="control-label required">Template Content</label>
                                <div style="height: 400px; border: 1px solid var(--gray-light); border-radius: var(--border-radius); overflow: hidden;">
                                    <div id="editor-container-modal"></div>
                                </div>
                                <textarea name="content" id="templateContent" style="display: none;" required></textarea>
                            </div>
                            
                            <div class="form-group" style="margin-bottom: 20px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" name="is_active" id="templateActive" value="1" checked>
                                    <label for="templateActive" style="color: var(--gray-dark); font-weight: 500;">
                                        Active (available for use)
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <div style="background: var(--off-white); padding: 15px; border-radius: var(--border-radius); border: 1px solid var(--gray-light);">
                                    <h4 style="color: var(--qc-blue); margin-bottom: 10px; font-size: 1rem;">
                                        <i class="fas fa-lightbulb"></i> Template Tips
                                    </h4>
                                    <ul style="font-size: 0.9rem; color: var(--gray); padding-left: 20px;">
                                        <li>Follow Quezon City legislative format standards</li>
                                        <li>Include all required sections for the document type</li>
                                        <li>Test templates before making them active</li>
                                        <li>A PDF preview will be automatically generated</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="templateModalCancel">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success" id="templateModalSubmit">
                            <i class="fas fa-save"></i> Save Template
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h2><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h2>
                <button class="modal-close" id="deleteModalClose">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 20px 0;">
                    <i class="fas fa-trash-alt" style="font-size: 3rem; color: var(--red); margin-bottom: 20px;"></i>
                    <h3 style="color: var(--gray-dark); margin-bottom: 10px;">Delete Template</h3>
                    <p style="color: var(--gray); margin-bottom: 20px;">
                        Are you sure you want to deactivate this template? This action cannot be undone.
                    </p>
                    <p style="color: var(--gray); font-size: 0.9rem; margin-bottom: 30px;">
                        Note: Templates are deactivated (soft deleted) and can be restored by an administrator.
                    </p>
                </div>
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="template_id" id="deleteTemplateId">
                    <input type="hidden" name="delete_template" value="1">
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="deleteModalCancel">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Deactivate Template
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="government-footer">
        <div class="footer-content">
            <div class="footer-column">
                <div class="footer-info">
                    <h3>Template Selection Module</h3>
                    <p>
                        Browse and select from professionally crafted ordinance and resolution templates. 
                        Ensure compliance with Quezon City legislative formats and standards.
                    </p>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="creation.php"><i class="fas fa-file-contract"></i> Document Creation</a></li>
                    <li><a href="templates.php"><i class="fas fa-file-alt"></i> Template Library</a></li>
                    <li><a href="draft_creation.php"><i class="fas fa-edit"></i> Draft Creation</a></li>
                    <li><a href="help.php"><i class="fas fa-question-circle"></i> Help Center</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Template Resources</h3>
                <ul class="footer-links">
                    <li><a href="#"><i class="fas fa-book"></i> Template Guide</a></li>
                    <li><a href="#"><i class="fas fa-video"></i> Video Tutorials</a></li>
                    <li><a href="#"><i class="fas fa-file-pdf"></i> Sample Documents</a></li>
                    <li><a href="#"><i class="fas fa-comments"></i> Template Feedback</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            <p>&copy; 2026 Quezon City Government. Template Selection Module - Ordinance & Resolution Tracking System.</p>
            <p style="margin-top: 10px;">This interface is for authorized personnel only. All template usage is logged for compliance.</p>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Template Library | Active Templates: <?php echo $stats['total_templates']; ?> | User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
            </div>
        </div>
    </footer>

    <!-- Include Quill.js for rich text editor -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script>
        // Initialize Quill editor for modal
        const quillModal = new Quill('#editor-container-modal', {
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
            }
        });

        // Sync Quill content with form textarea
        quillModal.on('text-change', function() {
            document.getElementById('templateContent').value = quillModal.root.innerHTML;
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
        
        // Preview Modal Functionality
        const previewModal = document.getElementById('previewModal');
        const previewModalClose = document.getElementById('previewModalClose');
        const previewModalCancel = document.getElementById('previewModalCancel');
        let currentPreviewTemplateId = null;
        
        // Preview template buttons
        document.querySelectorAll('.preview-template').forEach(btn => {
            btn.addEventListener('click', function() {
                const templateId = this.getAttribute('data-template-id');
                const templateName = this.getAttribute('data-template-name');
                const hasPDF = this.getAttribute('data-has-pdf') === '1';
                
                currentPreviewTemplateId = templateId;
                
                // Update modal title
                document.getElementById('previewTemplateName').textContent = templateName;
                
                // Show loading state
                document.getElementById('previewContent').innerHTML = `
                    <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                        <i class="fas fa-spinner fa-spin" style="font-size: 3rem; margin-bottom: 20px;"></i>
                        <h4 style="color: var(--gray-dark); margin-bottom: 10px;">Loading Preview...</h4>
                        <p>Please wait while we load the template preview.</p>
                    </div>
                `;
                
                // Open modal immediately
                previewModal.classList.add('active');
                
                // Load template data
                fetch(`get_template.php?id=${templateId}`)
                    .then(response => response.json())
                    .then(template => {
                        if (hasPDF && template.thumbnail) {
                            // Show PDF embed
                            document.getElementById('previewContent').innerHTML = `
                                <div class="pdf-preview-container">
                                    <iframe src="${template.thumbnail}" title="Template PDF Preview"></iframe>
                                </div>
                                <div style="margin-top: 15px; text-align: center;">
                                    <a href="${template.thumbnail}" target="_blank" class="btn btn-sm btn-outline">
                                        <i class="fas fa-external-link-alt"></i> Open PDF in New Tab
                                    </a>
                                </div>
                            `;
                        } else {
                            // Show content preview
                            document.getElementById('previewContent').innerHTML = `
                                <div class="modal-preview">
                                    <div style="background: var(--off-white); padding: 20px; border-radius: var(--border-radius); margin-bottom: 20px;">
                                        <h4 style="color: var(--qc-blue); margin-bottom: 10px;">Template Information</h4>
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                                            <div><strong>Type:</strong> ${template.template_type}</div>
                                            <div><strong>Category:</strong> ${template.category}</div>
                                            <div><strong>Uses:</strong> ${template.use_count}</div>
                                            <div><strong>Created:</strong> ${new Date(template.created_at).toLocaleDateString()}</div>
                                        </div>
                                        <p><strong>Description:</strong> ${template.description}</p>
                                    </div>
                                    <div>
                                        <h4 style="color: var(--qc-blue); margin-bottom: 15px;">Template Content Preview</h4>
                                        <div>${template.content}</div>
                                    </div>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Error loading template:', error);
                        document.getElementById('previewContent').innerHTML = `
                            <div style="text-align: center; padding: 40px 20px; color: var(--gray);">
                                <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 20px;"></i>
                                <h4 style="color: var(--gray-dark); margin-bottom: 10px;">Error Loading Preview</h4>
                                <p>Unable to load template preview. Please try again.</p>
                            </div>
                        `;
                    });
            });
        });
        
        // Close preview modal
        previewModalClose.addEventListener('click', closePreviewModal);
        previewModalCancel.addEventListener('click', closePreviewModal);
        previewModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closePreviewModal();
            }
        });
        
        function closePreviewModal() {
            previewModal.classList.remove('active');
        }
        
        // Use template from preview
        document.getElementById('useFromPreview').addEventListener('click', function() {
            if (currentPreviewTemplateId) {
                // Find the form for this template and submit it
                const form = document.querySelector(`form input[value="${currentPreviewTemplateId}"]`)?.closest('form');
                if (form) {
                    form.submit();
                }
            }
        });
        
        <?php if (in_array($user_role, ['super_admin', 'admin'])): ?>
        // Template Management Functionality
        const templateModal = document.getElementById('templateModal');
        const templateModalClose = document.getElementById('templateModalClose');
        const templateModalCancel = document.getElementById('templateModalCancel');
        const createTemplateBtn = document.getElementById('createTemplateBtn');
        const templateForm = document.getElementById('templateForm');
        const deleteModal = document.getElementById('deleteModal');
        const deleteModalClose = document.getElementById('deleteModalClose');
        const deleteModalCancel = document.getElementById('deleteModalCancel');
        const deleteForm = document.getElementById('deleteForm');
        
        // Create new template
        if (createTemplateBtn) {
            createTemplateBtn.addEventListener('click', function() {
                document.getElementById('modalTitle').textContent = 'Create New Template';
                document.getElementById('formTemplateId').value = '';
                document.getElementById('formAction').value = 'create_template';
                document.getElementById('templateForm').action = '';
                document.getElementById('templateName').value = '';
                document.getElementById('templateType').value = '';
                document.getElementById('templateCategory').value = '';
                document.getElementById('templateDescription').value = '';
                quillModal.root.innerHTML = '';
                document.getElementById('templateContent').value = '';
                document.getElementById('templateActive').checked = true;
                document.getElementById('templateModalSubmit').innerHTML = '<i class="fas fa-save"></i> Create Template';
                
                templateModal.classList.add('active');
            });
        }
        
        // Edit template
        document.querySelectorAll('.edit-template').forEach(btn => {
            btn.addEventListener('click', function() {
                const templateId = this.getAttribute('data-template-id');
                
                // Load template data via AJAX
                fetch(`get_template.php?id=${templateId}`)
                    .then(response => response.json())
                    .then(template => {
                        document.getElementById('modalTitle').textContent = 'Edit Template';
                        document.getElementById('formTemplateId').value = template.id;
                        document.getElementById('formAction').value = 'update_template';
                        document.getElementById('templateForm').action = '';
                        document.getElementById('templateName').value = template.template_name;
                        document.getElementById('templateType').value = template.template_type;
                        document.getElementById('templateCategory').value = template.category;
                        document.getElementById('templateDescription').value = template.description;
                        quillModal.root.innerHTML = template.content;
                        document.getElementById('templateContent').value = template.content;
                        document.getElementById('templateActive').checked = template.is_active == 1;
                        document.getElementById('templateModalSubmit').innerHTML = '<i class="fas fa-save"></i> Update Template';
                        
                        templateModal.classList.add('active');
                    })
                    .catch(error => {
                        console.error('Error loading template:', error);
                        alert('Error loading template data. Please try again.');
                    });
            });
        });
        
        // Delete template
        document.querySelectorAll('.delete-template').forEach(btn => {
            btn.addEventListener('click', function() {
                const templateId = this.getAttribute('data-template-id');
                const templateName = this.getAttribute('data-template-name');
                document.getElementById('deleteTemplateId').value = templateId;
                deleteModal.classList.add('active');
            });
        });
        
        // Close template modal
        templateModalClose.addEventListener('click', closeTemplateModal);
        templateModalCancel.addEventListener('click', closeTemplateModal);
        templateModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeTemplateModal();
            }
        });
        
        function closeTemplateModal() {
            templateModal.classList.remove('active');
        }
        
        // Close delete modal
        deleteModalClose.addEventListener('click', closeDeleteModal);
        deleteModalCancel.addEventListener('click', closeDeleteModal);
        deleteModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });
        
        function closeDeleteModal() {
            deleteModal.classList.remove('active');
        }
        
        // Template form submission
        templateForm.addEventListener('submit', function(e) {
            // Ensure content is set
            document.getElementById('templateContent').value = quillModal.root.innerHTML;
            
            // Validate form
            const templateName = document.getElementById('templateName').value.trim();
            const templateType = document.getElementById('templateType').value;
            const templateCategory = document.getElementById('templateCategory').value.trim();
            const content = quillModal.getText().trim();
            
            if (!templateName) {
                e.preventDefault();
                alert('Please enter a template name.');
                return;
            }
            
            if (!templateType) {
                e.preventDefault();
                alert('Please select a template type.');
                return;
            }
            
            if (!templateCategory) {
                e.preventDefault();
                alert('Please enter a category.');
                return;
            }
            
            if (!content) {
                e.preventDefault();
                alert('Please enter template content.');
                return;
            }
            
            if (!confirm('Are you sure you want to save this template? A PDF preview will be automatically generated.')) {
                e.preventDefault();
            }
        });
        <?php endif; ?>
        
        // Search functionality
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('filterForm').submit();
                }
            });
        }
        
        // Initialize template cards with animation
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation to template cards
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
            
            // Observe template cards
            document.querySelectorAll('.template-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(card);
            });
        });
        
        // Template usage confirmation
        document.querySelectorAll('form[method="POST"]').forEach(form => {
            if (form.querySelector('input[name="use_template"]')) {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Use this template to create a new document?')) {
                        e.preventDefault();
                    }
                });
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('.search-input');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                closePreviewModal();
                <?php if (in_array($user_role, ['super_admin', 'admin'])): ?>
                closeTemplateModal();
                closeDeleteModal();
                <?php endif; ?>
            }
        });
    </script>
</body>
</html>