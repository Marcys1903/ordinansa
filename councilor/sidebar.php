<?php
// sidebar.php
$role = $_SESSION['role'];
?>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon">
                <i class="fas fa-landmark"></i>
            </div>
            <div class="logo-text">
                <h2>Quezon City</h2>
                <p>Ordinance Tracker</p>
            </div>
        </div>
        
        <div class="user-info">
            <div class="user-avatar">
                <?php echo strtoupper(substr($_SESSION['firstname'], 0, 1) . substr($_SESSION['lastname'], 0, 1)); ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></h3>
                <p><?php echo htmlspecialchars($_SESSION['department']); ?></p>
            </div>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <!-- Module 1: Ordinance & Resolution Creation -->
        <div class="nav-section">
            <div class="section-title">Module 1: Creation</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="creation.php" class="nav-link">
                        <i class="fas fa-file-contract"></i>
                        <span>Ordinance & Resolution Creation</span>
                    </a>
                    <ul class="submenu">
                        <?php if (in_array($role, ['super_admin', 'admin', 'councilor'])): ?>
                        <li class="submenu-item">
                            <a href="draft_creation.php" class="submenu-link">Draft Creation</a>
                        </li>
                        <li class="submenu-item">
                            <a href="templates.php" class="submenu-link">Template Selection</a>
                        </li>
                        <li class="submenu-item">
                            <a href="authors.php" class="submenu-link">Author Assignment</a>
                        </li>
                        <li class="submenu-item">
                            <a href="documents.php" class="submenu-link">Supporting Documents</a>
                        </li>
                        <li class="submenu-item">
                            <a href="registration.php" class="submenu-link">Draft Registration</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
            </ul>
        </div>
        
        <!-- Module 2: Classification & Organization -->
        <div class="nav-section">
            <div class="section-title">Module 2: Classification</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="classification.php" class="nav-link">
                        <i class="fas fa-tags"></i>
                        <span>Classification & Organization</span>
                    </a>
                    <ul class="submenu">
                        <?php if (in_array($role, ['super_admin', 'admin'])): ?>
                        <li class="submenu-item">
                            <a href="type_identification.php" class="submenu-link">Type Identification</a>
                        </li>
                        <li class="submenu-item">
                            <a href="categorization.php" class="submenu-link">Subject Categorization</a>
                        </li>
                        <li class="submenu-item">
                            <a href="priority.php" class="submenu-link">Priority Setting</a>
                        </li>
                        <li class="submenu-item">
                            <a href="numbering.php" class="submenu-link">Reference Numbering</a>
                        </li>
                        <li class="submenu-item">
                            <a href="tagging.php" class="submenu-link">Keyword Tagging</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
            </ul>
        </div>
        
        <!-- Module 3: Status Tracking -->
        <div class="nav-section">
            <div class="section-title">Module 3: Tracking</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="tracking.php" class="nav-link">
                        <i class="fas fa-chart-line"></i>
                        <span>Status Tracking</span>
                    </a>
                    <ul class="submenu">
                        <?php if (in_array($role, ['super_admin', 'admin', 'councilor'])): ?>
                        <li class="submenu-item">
                            <a href="status_updates.php" class="submenu-link">Status Updates</a>
                        </li>
                        <li class="submenu-item">
                            <a href="timeline.php" class="submenu-link">Timeline Tracking</a>
                        </li>
                        <li class="submenu-item">
                            <a href="action_history.php" class="submenu-link">Action History</a>
                        </li>
                        <li class="submenu-item">
                            <a href="notifications.php" class="submenu-link">Notification Alerts</a>
                        </li>
                        <li class="submenu-item">
                            <a href="progress_reports.php" class="submenu-link">Progress Summary</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
            </ul>
        </div>
        
        <!-- Module 4: Amendment Management -->
        <div class="nav-section">
            <div class="section-title">Module 4: Amendments</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="amendments.php" class="nav-link">
                        <i class="fas fa-edit"></i>
                        <span>Amendment Management</span>
                    </a>
                    <ul class="submenu">
                        <?php if (in_array($role, ['super_admin', 'admin', 'councilor'])): ?>
                        <li class="submenu-item">
                            <a href="amendment_submission.php" class="submenu-link">Amendment Submission</a>
                        </li>
                        <li class="submenu-item">
                            <a href="comparison.php" class="submenu-link">Change Comparison</a>
                        </li>
                        <li class="submenu-item">
                            <a href="approval_control.php" class="submenu-link">Approval Control</a>
                        </li>
                        <li class="submenu-item">
                            <a href="version_storage.php" class="submenu-link">Version Storage</a>
                        </li>
                        <li class="submenu-item">
                            <a href="version_recovery.php" class="submenu-link">Version Recovery</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
            </ul>
        </div>
        
        <!-- Module 5: Approval & Enactment -->
        <div class="nav-section">
            <div class="section-title">Module 5: Approval</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="approval.php" class="nav-link">
                        <i class="fas fa-gavel"></i>
                        <span>Approval & Enactment</span>
                    </a>
                    <ul class="submenu">
                        <?php if (in_array($role, ['super_admin', 'admin'])): ?>
                        <li class="submenu-item">
                            <a href="voting_results.php" class="submenu-link">Voting Results</a>
                        </li>
                        <li class="submenu-item">
                            <a href="final_approval.php" class="submenu-link">Final Approval</a>
                        </li>
                        <li class="submenu-item">
                            <a href="effectivity_dates.php" class="submenu-link">Effectivity Date</a>
                        </li>
                        <li class="submenu-item">
                            <a href="archiving.php" class="submenu-link">Final Archiving</a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
            </ul>
        </div>
        
        <!-- Role-specific modules -->
        <?php if ($role === 'super_admin'): ?>
        <div class="nav-section">
            <div class="section-title">Super Admin</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="system_settings.php" class="nav-link">
                        <i class="fas fa-cogs"></i>
                        <span>System Settings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="user_management.php" class="nav-link">
                        <i class="fas fa-users-cog"></i>
                        <span>User Management</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="audit_logs.php" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Audit Logs</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="backup.php" class="nav-link">
                        <i class="fas fa-database"></i>
                        <span>System Backup</span>
                    </a>
                </li>
            </ul>
        </div>
        <?php elseif ($role === 'admin'): ?>
        <div class="nav-section">
            <div class="section-title">Admin Tools</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="document_management.php" class="nav-link">
                        <i class="fas fa-folder-open"></i>
                        <span>Document Management</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="report_generation.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Report Generation</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="calendar.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Legislative Calendar</span>
                    </a>
                </li>
            </ul>
        </div>
        <?php elseif ($role === 'councilor'): ?>
        <div class="nav-section">
            <div class="section-title">Councilor Tools</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="my_documents.php" class="nav-link">
                        <i class="fas fa-file-signature"></i>
                        <span>My Documents</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="voting_records.php" class="nav-link">
                        <i class="fas fa-vote-yea"></i>
                        <span>Voting Records</a>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="constituent_reports.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Constituent Reports</span>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- Dashboard link -->
        <div class="nav-section">
            <div class="section-title">Navigation</div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="<?php echo $role . '.php'; ?>" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard Home</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../logout.php" class="nav-link" style="color: #ff6b6b;">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>
</div>