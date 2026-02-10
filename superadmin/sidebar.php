<?php
// sidebar.php
$role = $_SESSION['role'];
$userName = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];
$department = $_SESSION['department'] ?? 'Quezon City Government';

// Get current page
$current_page = basename($_SERVER['PHP_SELF']);
?>

    
    <!-- Navigation Menu -->
    <nav class="sidebar-navigation">
        <!-- Main Navigation Sections -->
        <div class="nav-section">
            <div class="section-header">
                <i class="fas fa-layer-group"></i>
                <span>MODULES</span>
            </div>
            
            <!-- Module 1: Ordinance & Resolution Creation -->
            <div class="nav-module">
                <div class="module-header <?php echo (in_array($current_page, ['creation.php', 'draft_creation.php', 'templates.php', 'authors.php', 'documents.php', 'registration.php'])) ? 'active' : ''; ?>" data-module="module1">
                    <i class="fas fa-file-contract"></i>
                    <span>Creation & Drafting</span>
                    <i class="fas fa-chevron-down module-toggle"></i>
                </div>
                <div class="module-content <?php echo (in_array($current_page, ['creation.php', 'draft_creation.php', 'templates.php', 'authors.php', 'documents.php', 'registration.php'])) ? 'active' : ''; ?>" id="module1">
                    <?php if (in_array($role, ['super_admin', 'admin', 'councilor'])): ?>
                    
                    <div class="submodule-links">
                        <a href="draft_creation.php" class="submodule-link <?php echo ($current_page == 'draft_creation.php') ? 'active' : ''; ?>">
                            <i class="fas fa-edit"></i>
                            <span>Draft Creation</span>
                        </a>
                        <a href="templates.php" class="submodule-link <?php echo ($current_page == 'templates.php') ? 'active' : ''; ?>">
                            <i class="fas fa-file-alt"></i>
                            <span>Template Selection</span>
                        </a>
                        <a href="authors.php" class="submodule-link <?php echo ($current_page == 'authors.php') ? 'active' : ''; ?>">
                            <i class="fas fa-user-edit"></i>
                            <span>Author Assignment</span>
                        </a>
                        <a href="documents.php" class="submodule-link <?php echo ($current_page == 'documents.php') ? 'active' : ''; ?>">
                            <i class="fas fa-paperclip"></i>
                            <span>Supporting Documents</span>
                        </a>
                        <a href="registration.php" class="submodule-link <?php echo ($current_page == 'registration.php') ? 'active' : ''; ?>">
                            <i class="fas fa-registered"></i>
                            <span>Draft Registration</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Module 2: Classification & Organization -->
            <div class="nav-module">
                <div class="module-header <?php echo (in_array($current_page, ['classification.php', 'type_identification.php', 'categorization.php', 'priority.php', 'numbering.php', 'tagging.php'])) ? 'active' : ''; ?>" data-module="module2">
                    <i class="fas fa-tags"></i>
                    <span>Classification & Organization</span>
                    <i class="fas fa-chevron-down module-toggle"></i>
                </div>
                <div class="module-content <?php echo (in_array($current_page, ['classification.php', 'type_identification.php', 'categorization.php', 'priority.php', 'numbering.php', 'tagging.php'])) ? 'active' : ''; ?>" id="module2">
                    <?php if (in_array($role, ['super_admin', 'admin'])): ?>
                    <a href="classification.php" class="nav-link <?php echo ($current_page == 'classification.php') ? 'active' : ''; ?>">
                        <i class="fas fa-sitemap"></i>
                        <span>Classification Dashboard</span>
                    </a>
                    <div class="submodule-links">
                        <a href="type_identification.php" class="submodule-link <?php echo ($current_page == 'type_identification.php') ? 'active' : ''; ?>">
                            <i class="fas fa-fingerprint"></i>
                            <span>Type Identification</span>
                        </a>
                        <a href="categorization.php" class="submodule-link <?php echo ($current_page == 'categorization.php') ? 'active' : ''; ?>">
                            <i class="fas fa-folder"></i>
                            <span>Subject Categorization</span>
                        </a>
                        <a href="priority.php" class="submodule-link <?php echo ($current_page == 'priority.php') ? 'active' : ''; ?>">
                            <i class="fas fa-flag"></i>
                            <span>Priority Setting</span>
                        </a>
                        <a href="numbering.php" class="submodule-link <?php echo ($current_page == 'numbering.php') ? 'active' : ''; ?>">
                            <i class="fas fa-hashtag"></i>
                            <span>Reference Numbering</span>
                        </a>
                        <a href="tagging.php" class="submodule-link <?php echo ($current_page == 'tagging.php') ? 'active' : ''; ?>">
                            <i class="fas fa-tag"></i>
                            <span>Keyword Tagging</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Module 3: Status Tracking -->
            <div class="nav-module">
                <div class="module-header <?php echo (in_array($current_page, ['tracking.php', 'status_updates.php', 'timeline.php', 'action_history.php', 'notifications.php', 'progress_reports.php'])) ? 'active' : ''; ?>" data-module="module3">
                    <i class="fas fa-chart-line"></i>
                    <span>Status Tracking</span>
                    <i class="fas fa-chevron-down module-toggle"></i>
                </div>
                <div class="module-content <?php echo (in_array($current_page, ['tracking.php', 'status_updates.php', 'timeline.php', 'action_history.php', 'notifications.php', 'progress_reports.php'])) ? 'active' : ''; ?>" id="module3">
                    <?php if (in_array($role, ['super_admin', 'admin', 'councilor'])): ?>
                    <a href="tracking.php" class="nav-link <?php echo ($current_page == 'tracking.php') ? 'active' : ''; ?>">
                        <i class="fas fa-binoculars"></i>
                        <span>Tracking Dashboard</span>
                    </a>
                    <div class="submodule-links">
                        <a href="status_updates.php" class="submodule-link <?php echo ($current_page == 'status_updates.php') ? 'active' : ''; ?>">
                            <i class="fas fa-sync-alt"></i>
                            <span>Status Updates</span>
                        </a>
                        <a href="timeline.php" class="submodule-link <?php echo ($current_page == 'timeline.php') ? 'active' : ''; ?>">
                            <i class="fas fa-stream"></i>
                            <span>Timeline Tracking</span>
                        </a>
                        <a href="action_history.php" class="submodule-link <?php echo ($current_page == 'action_history.php') ? 'active' : ''; ?>">
                            <i class="fas fa-history"></i>
                            <span>Action History</span>
                        </a>
                        <a href="notifications.php" class="submodule-link <?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>">
                            <i class="fas fa-bell"></i>
                            <span>Notification Alerts</span>
                        </a>
                        <a href="progress_reports.php" class="submodule-link <?php echo ($current_page == 'progress_reports.php') ? 'active' : ''; ?>">
                            <i class="fas fa-chart-pie"></i>
                            <span>Progress Summary</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Module 4: Amendment Management -->
            <div class="nav-module">
                <div class="module-header <?php echo (in_array($current_page, ['amendments.php', 'amendment_submission.php', 'comparison.php', 'approval_control.php', 'version_storage.php', 'version_recovery.php'])) ? 'active' : ''; ?>" data-module="module4">
                    <i class="fas fa-edit"></i>
                    <span>Amendment Management</span>
                    <i class="fas fa-chevron-down module-toggle"></i>
                </div>
                <div class="module-content <?php echo (in_array($current_page, ['amendments.php', 'amendment_submission.php', 'comparison.php', 'approval_control.php', 'version_storage.php', 'version_recovery.php'])) ? 'active' : ''; ?>" id="module4">
                    <?php if (in_array($role, ['super_admin', 'admin', 'councilor'])): ?>
                    <a href="amendments.php" class="nav-link <?php echo ($current_page == 'amendments.php') ? 'active' : ''; ?>">
                        <i class="fas fa-file-medical-alt"></i>
                        <span>Amendments Dashboard</span>
                    </a>
                    <div class="submodule-links">
                        <a href="amendment_submission.php" class="submodule-link <?php echo ($current_page == 'amendment_submission.php') ? 'active' : ''; ?>">
                            <i class="fas fa-upload"></i>
                            <span>Amendment Submission</span>
                        </a>
                        <a href="comparison.php" class="submodule-link <?php echo ($current_page == 'comparison.php') ? 'active' : ''; ?>">
                            <i class="fas fa-code-branch"></i>
                            <span>Change Comparison</span>
                        </a>
                        <a href="approval_control.php" class="submodule-link <?php echo ($current_page == 'approval_control.php') ? 'active' : ''; ?>">
                            <i class="fas fa-check-double"></i>
                            <span>Approval Control</span>
                        </a>
                        <a href="version_storage.php" class="submodule-link <?php echo ($current_page == 'version_storage.php') ? 'active' : ''; ?>">
                            <i class="fas fa-box-archive"></i>
                            <span>Version Storage</span>
                        </a>
                        <a href="version_recovery.php" class="submodule-link <?php echo ($current_page == 'version_recovery.php') ? 'active' : ''; ?>">
                            <i class="fas fa-undo"></i>
                            <span>Version Recovery</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Module 5: Approval & Enactment -->
            <div class="nav-module">
                <div class="module-header <?php echo (in_array($current_page, ['approval.php', 'voting_results.php', 'final_approval.php', 'effectivity_dates.php', 'archiving.php'])) ? 'active' : ''; ?>" data-module="module5">
                    <i class="fas fa-gavel"></i>
                    <span>Approval & Enactment</span>
                    <i class="fas fa-chevron-down module-toggle"></i>
                </div>
                <div class="module-content <?php echo (in_array($current_page, ['approval.php', 'voting_results.php', 'final_approval.php', 'effectivity_dates.php', 'archiving.php'])) ? 'active' : ''; ?>" id="module5">
                    <?php if (in_array($role, ['super_admin', 'admin'])): ?>
                    <a href="approval.php" class="nav-link <?php echo ($current_page == 'approval.php') ? 'active' : ''; ?>">
                        <i class="fas fa-stamp"></i>
                        <span>Approval Dashboard</span>
                    </a>
                    <div class="submodule-links">
                        <a href="voting_results.php" class="submodule-link <?php echo ($current_page == 'voting_results.php') ? 'active' : ''; ?>">
                            <i class="fas fa-vote-yea"></i>
                            <span>Voting Results</span>
                        </a>
                        <a href="final_approval.php" class="submodule-link <?php echo ($current_page == 'final_approval.php') ? 'active' : ''; ?>">
                            <i class="fas fa-check-circle"></i>
                            <span>Final Approval</span>
                        </a>
                        <a href="effectivity_dates.php" class="submodule-link <?php echo ($current_page == 'effectivity_dates.php') ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check"></i>
                            <span>Effectivity Date</span>
                        </a>
                        <a href="archiving.php" class="submodule-link <?php echo ($current_page == 'archiving.php') ? 'active' : ''; ?>">
                            <i class="fas fa-archive"></i>
                            <span>Final Archiving</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Role-specific Sections -->
        <div class="nav-section">
            <div class="section-header">
                <?php if ($role === 'super_admin'): ?>
                    <i class="fas fa-shield-alt"></i>
                    <span>SYSTEM ADMINISTRATION</span>
                <?php elseif ($role === 'admin'): ?>
                    <i class="fas fa-user-shield"></i>
                    <span>ADMINISTRATION TOOLS</span>
                <?php else: ?>
                    <i class="fas fa-user-tie"></i>
                    <span>COUNCILOR TOOLS</span>
                <?php endif; ?>
            </div>
            
            <?php if ($role === 'super_admin'): ?>
            <div class="admin-tools">
                <a href="system_settings.php" class="admin-link <?php echo ($current_page == 'system_settings.php') ? 'active' : ''; ?>">
                    <i class="fas fa-cogs"></i>
                    <span>System Settings</span>
                </a>
                <a href="user_management.php" class="admin-link <?php echo ($current_page == 'user_management.php') ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>User Management</span>
                </a>
                <a href="audit_logs.php" class="admin-link <?php echo ($current_page == 'audit_logs.php') ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Audit Logs</span>
                </a>
                <a href="backup.php" class="admin-link <?php echo ($current_page == 'backup.php') ? 'active' : ''; ?>">
                    <i class="fas fa-database"></i>
                    <span>System Backup</span>
                </a>
                <a href="security_center.php" class="admin-link <?php echo ($current_page == 'security_center.php') ? 'active' : ''; ?>">
                    <i class="fas fa-lock"></i>
                    <span>Security Center</span>
                </a>
            </div>
            <?php elseif ($role === 'admin'): ?>
            <div class="admin-tools">
                <a href="document_management.php" class="admin-link <?php echo ($current_page == 'document_management.php') ? 'active' : ''; ?>">
                    <i class="fas fa-folder-open"></i>
                    <span>Document Management</span>
                </a>
                <a href="report_generation.php" class="admin-link <?php echo ($current_page == 'report_generation.php') ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Report Generation</span>
                </a>
                <a href="calendar.php" class="admin-link <?php echo ($current_page == 'calendar.php') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Legislative Calendar</span>
                </a>
                <a href="committee_management.php" class="admin-link <?php echo ($current_page == 'committee_management.php') ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Committee Management</span>
                </a>
            </div>
            <?php elseif ($role === 'councilor'): ?>
            <div class="councilor-tools">
                <a href="my_documents.php" class="councilor-link <?php echo ($current_page == 'my_documents.php') ? 'active' : ''; ?>">
                    <i class="fas fa-file-signature"></i>
                    <span>My Documents</span>
                </a>
                <a href="voting_records.php" class="councilor-link <?php echo ($current_page == 'voting_records.php') ? 'active' : ''; ?>">
                    <i class="fas fa-vote-yea"></i>
                    <span>Voting Records</span>
                </a>
                <a href="constituent_reports.php" class="councilor-link <?php echo ($current_page == 'constituent_reports.php') ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Constituent Reports</span>
                </a>
                <a href="legislative_portfolio.php" class="councilor-link <?php echo ($current_page == 'legislative_portfolio.php') ? 'active' : ''; ?>">
                    <i class="fas fa-briefcase"></i>
                    <span>Legislative Portfolio</span>
                </a>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions & Navigation -->
        <div class="nav-section">
            <div class="section-header">
                <i class="fas fa-rocket"></i>
                <span>QUICK NAVIGATION</span>
            </div>
            
            <div class="quick-nav">
                <a href="<?php echo $role . '.php'; ?>" class="quick-nav-link dashboard <?php echo ($current_page == $role . '.php') ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard Home</span>
                </a>
                <a href="search.php" class="quick-nav-link search <?php echo ($current_page == 'search.php') ? 'active' : ''; ?>">
                    <i class="fas fa-search"></i>
                    <span>Advanced Search</span>
                </a>
                <a href="reports.php" class="quick-nav-link reports <?php echo ($current_page == 'reports.php') ? 'active' : ''; ?>">
                    <i class="fas fa-file-export"></i>
                    <span>Quick Reports</span>
                </a>
                <a href="help.php" class="quick-nav-link help <?php echo ($current_page == 'help.php') ? 'active' : ''; ?>">
                    <i class="fas fa-question-circle"></i>
                    <span>Help & Support</span>
                </a>
                <a href="../logout.php" class="quick-nav-link logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout System</span>
                </a>
            </div>
        </div>
    </nav>
</aside>

<style>
    /* Sidebar Styles */
    .government-sidebar {
        width: 320px;
        background: linear-gradient(180deg, var(--qc-blue-dark) 0%, var(--qc-blue) 100%);
        color: var(--white);
        height: 100vh;
        position: fixed;
        left: 0;
        top: 0;
        z-index: 1000;
        box-shadow: 5px 0 20px rgba(0, 0, 0, 0.2);
        display: flex;
        flex-direction: column;
        overflow-y: auto;
        border-right: 3px solid var(--qc-gold);
    }
    
    /* Sidebar Header */
    .sidebar-header {
        padding: 25px;
        background: rgba(0, 0, 0, 0.2);
        border-bottom: 1px solid rgba(212, 175, 55, 0.2);
    }
    
    /* User Profile */
    .sidebar-user-profile {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(212, 175, 55, 0.2);
        border-radius: var(--border-radius);
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .user-avatar-large {
        width: 70px;
        height: 70px;
        background: linear-gradient(135deg, var(--qc-gold) 0%, var(--qc-gold-dark) 100%);
        color: var(--qc-blue-dark);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 1.5rem;
        margin: 0 auto 15px;
        border: 3px solid var(--white);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    }
    
    .user-info-detailed {
        text-align: center;
    }
    
    .user-name {
        font-size: 1.1rem;
        font-weight: bold;
        color: var(--white);
        margin-bottom: 5px;
    }
    
    .user-department {
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.8);
        margin-bottom: 10px;
    }
    
    .user-role-badge {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        background: rgba(0, 0, 0, 0.3);
        padding: 8px 15px;
        border-radius: 20px;
        border: 1px solid rgba(212, 175, 55, 0.3);
    }
    
    .role-icon {
        font-size: 0.8rem;
        color: var(--qc-gold);
        display: flex;
        align-items: center;
        gap: 5px;
        font-weight: 600;
    }
    
    /* Navigation Menu */
    .sidebar-navigation {
        flex: 1;
        padding: 20px 25px;
        overflow-y: auto;
    }
    
    /* Navigation Sections */
    .nav-section {
        margin-bottom: 30px;
    }
    
    .section-header {
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--qc-gold);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(212, 175, 55, 0.2);
    }
    
    /* Module Navigation */
    .nav-module {
        background: rgba(255, 255, 255, 0.03);
        border-radius: 8px;
        margin-bottom: 10px;
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    .module-header {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        color: var(--white);
        font-weight: 500;
    }
    
    .module-header:hover {
        background: rgba(212, 175, 55, 0.1);
    }
    
    .module-header.active {
        background: rgba(212, 175, 55, 0.15);
        border-left: 3px solid var(--qc-gold);
    }
    
    .module-header i:first-child {
        color: var(--qc-gold);
        font-size: 1.1rem;
        width: 20px;
        text-align: center;
    }
    
    .module-toggle {
        margin-left: auto;
        transition: transform 0.3s ease;
        font-size: 0.8rem;
    }
    
    .module-header.active .module-toggle {
        transform: rotate(180deg);
    }
    
    .module-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
    }
    
    .module-content.active {
        max-height: 1000px;
    }
    
    /* Navigation Links */
    .nav-link {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 12px 15px 12px 45px;
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        border-left: 3px solid transparent;
    }
    
    .nav-link:hover {
        background: rgba(212, 175, 55, 0.1);
        color: var(--white);
        border-left-color: var(--qc-gold);
    }
    
    .nav-link.active {
        background: rgba(212, 175, 55, 0.15);
        color: var(--white);
        border-left-color: var(--qc-gold);
        font-weight: bold;
    }
    
    .nav-link i {
        color: var(--qc-gold);
        font-size: 0.9rem;
        width: 20px;
        text-align: center;
    }
    
    /* Submodule Links */
    .submodule-links {
        padding: 10px 15px 15px 45px;
    }
    
    .submodule-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 12px;
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        font-size: 0.85rem;
        border-radius: 4px;
        transition: all 0.3s ease;
        margin-bottom: 5px;
    }
    
    .submodule-link:hover {
        background: rgba(255, 255, 255, 0.05);
        color: var(--white);
        transform: translateX(5px);
    }
    
    .submodule-link.active {
        background: rgba(212, 175, 55, 0.15);
        color: var(--qc-gold);
        font-weight: bold;
        border-left: 2px solid var(--qc-gold);
    }
    
    .submodule-link i {
        color: var(--qc-gold);
        font-size: 0.8rem;
        width: 20px;
        text-align: center;
    }
    
    /* Admin/Councilor Tools */
    .admin-tools,
    .councilor-tools {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .admin-link,
    .councilor-link {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 12px 15px;
        background: rgba(255, 255, 255, 0.05);
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        border-radius: 6px;
        transition: all 0.3s ease;
        font-size: 0.9rem;
        border: 1px solid transparent;
    }
    
    .admin-link:hover,
    .councilor-link:hover {
        background: rgba(212, 175, 55, 0.15);
        color: var(--white);
        transform: translateX(5px);
        border-color: rgba(212, 175, 55, 0.3);
    }
    
    .admin-link.active,
    .councilor-link.active {
        background: rgba(212, 175, 55, 0.15);
        color: var(--white);
        border-color: rgba(212, 175, 55, 0.3);
        font-weight: bold;
    }
    
    .admin-link i,
    .councilor-link i {
        color: var(--qc-gold);
        font-size: 1rem;
        width: 20px;
    }
    
    /* Quick Navigation */
    .quick-nav {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    
    .quick-nav-link {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 15px 10px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        color: rgba(255, 255, 255, 0.9);
        text-decoration: none;
        transition: all 0.3s ease;
        text-align: center;
        border: 1px solid transparent;
    }
    
    .quick-nav-link:hover {
        transform: translateY(-3px);
        background: rgba(212, 175, 55, 0.15);
        border-color: rgba(212, 175, 55, 0.3);
    }
    
    .quick-nav-link.active {
        background: rgba(212, 175, 55, 0.15);
        border-color: rgba(212, 175, 55, 0.3);
        font-weight: bold;
    }
    
    .quick-nav-link i {
        font-size: 1.3rem;
        margin-bottom: 5px;
    }
    
    .quick-nav-link span {
        font-size: 0.8rem;
    }
    
    .dashboard i { color: #4ade80; }
    .search i { color: #60a5fa; }
    .reports i { color: #f59e0b; }
    .help i { color: #8b5cf6; }
    .logout i { color: #f87171; }
    
    /* Scrollbar Styling */
    .sidebar-navigation::-webkit-scrollbar {
        width: 6px;
    }
    
    .sidebar-navigation::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.1);
    }
    
    .sidebar-navigation::-webkit-scrollbar-thumb {
        background: var(--qc-gold);
        border-radius: 3px;
    }
    
    .sidebar-navigation::-webkit-scrollbar-thumb:hover {
        background: var(--qc-gold-dark);
    }
    
    /* Mobile Responsiveness */
    @media (max-width: 1200px) {
        .government-sidebar {
            width: 280px;
        }
    }
    
    @media (max-width: 992px) {
        .government-sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            width: 280px;
        }
        
        .government-sidebar.active {
            transform: translateX(0);
        }
    }
    
    @media (max-width: 768px) {
        .government-sidebar {
            width: 100%;
            max-width: 300px;
        }
        
        .quick-nav {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
    // Module Toggle Functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-expand active module on page load
        const activeModules = document.querySelectorAll('.module-header.active');
        activeModules.forEach(header => {
            const moduleId = header.getAttribute('data-module');
            const moduleContent = document.getElementById(moduleId);
            const toggleIcon = header.querySelector('.module-toggle');
            
            if (moduleContent) {
                moduleContent.classList.add('active');
                toggleIcon.style.transform = 'rotate(180deg)';
            }
        });
        
        // Module toggle functionality
        const moduleHeaders = document.querySelectorAll('.module-header');
        moduleHeaders.forEach(header => {
            header.addEventListener('click', function() {
                const moduleId = this.getAttribute('data-module');
                const moduleContent = document.getElementById(moduleId);
                const toggleIcon = this.querySelector('.module-toggle');
                
                this.classList.toggle('active');
                moduleContent.classList.toggle('active');
                toggleIcon.style.transform = this.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0deg)';
            });
        });
        
        // Mobile sidebar toggle
        const mobileToggle = document.createElement('button');
        mobileToggle.className = 'mobile-sidebar-toggle';
        mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
        mobileToggle.style.cssText = `
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 999;
            background: var(--qc-blue);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: none;
        `;
        
        document.body.appendChild(mobileToggle);
        
        // Show/hide mobile toggle based on screen size
        function checkMobile() {
            if (window.innerWidth <= 992) {
                mobileToggle.style.display = 'flex';
            } else {
                mobileToggle.style.display = 'none';
            }
        }
        
        mobileToggle.addEventListener('click', function() {
            document.querySelector('.government-sidebar').classList.toggle('active');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 992) {
                const sidebar = document.querySelector('.government-sidebar');
                if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
        
        // Initial check
        checkMobile();
        window.addEventListener('resize', checkMobile);
        
        // Add animation to sidebar elements
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -20px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        // Observe sidebar elements
        document.querySelectorAll('.nav-module, .admin-link, .councilor-link, .quick-nav-link').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(10px)';
            el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            observer.observe(el);
        });
    });
</script>