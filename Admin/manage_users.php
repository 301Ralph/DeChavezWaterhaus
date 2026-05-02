<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminName = $_SESSION['userName'] ?? 'Admin';

// Fetch admin data for profile picture
$admin = $conn->query("SELECT * FROM customers WHERE userID = " . $_SESSION['userID'])->fetch_assoc();

// Handle verification approval/rejection
if (isset($_GET['approve'])) {
    $userID = intval($_GET['approve']);
    $conn->query("UPDATE customers SET verification_status = 'approved' WHERE userID = $userID");
    echo '<script>window.location = "manage_users.php";</script>';
    exit();
}

if (isset($_GET['reject'])) {
    $userID = intval($_GET['reject']);
    $conn->query("UPDATE customers SET verification_status = 'rejected' WHERE userID = $userID");
    echo '<script>window.location = "manage_users.php";</script>';
    exit();
}

// Fetch all customers
$customers = $conn->query("SELECT * FROM customers WHERE Role = 'customer' ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        
        .sidebar { 
            position: fixed; top: 0; left: 0; height: 100vh; width: 260px; 
            background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; 
            transition: all 0.3s ease; 
            display: flex;
            flex-direction: column;
        }
        .sidebar .nav-menu {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 20px;
        }
        .sidebar .logout-section {
            padding: 15px 10px;
            border-top: 1px solid #eee;
            background: white;
        }
        .sidebar .logo { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eee; }
        .sidebar .logo img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; }
        .sidebar .nav-link { 
            color: #495057; padding: 14px 22px; display: flex; align-items: center; gap: 14px; 
            font-weight: 500; transition: all 0.3s ease; border-radius: 12px; margin: 4px 10px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { 
            background-color: #f0f7ff; color: var(--primary); 
        }
        .sidebar .nav-link i { width: 22px; font-size: 1.1rem; }
        
        .main-content { margin-left: 260px; padding: 30px; transition: margin-left 0.3s ease; }
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 20px; }
        
        .sidebar .nav-link {
            padding: 12px 18px;
            margin: 2px 8px;
            border-radius: 10px;
        }
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo p-4 d-flex align-items-center gap-3 border-bottom">
            <img src="../images/logo.jpg" alt="Logo" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover;">
            <div>
                <span class="fw-bold fs-5">De Chavez Waterhaus</span>
                <small class="d-block text-muted">Admin Panel</small>
            </div>
        </div>
        <div class="nav-menu px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="manage_products.php" class="nav-link"><i class="fas fa-box me-3"></i> <span>Manage Products</span></a></li>
                <li class="nav-item"><a href="manage_orders.php" class="nav-link"><i class="fas fa-shopping-cart me-3"></i> <span>Manage Orders</span></a></li>
                <li class="nav-item"><a href="manage_users.php" class="nav-link active"><i class="fas fa-users me-3"></i> <span>Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_employees.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Employees</span></a></li>
                <li class="nav-item"><a href="attendance_management.php" class="nav-link"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
                <li class="nav-item"><a href="payroll_management.php" class="nav-link"><i class="fas fa-money-bill me-3"></i> <span>Payroll</span></a></li>
                <li class="nav-item"><a href="generate_payslip.php" class="nav-link"><i class="fas fa-file-pdf me-3"></i> <span>Generate Payslip</span></a></li>
                <li class="nav-item"><a href="leave_management.php" class="nav-link"><i class="fas fa-calendar-alt me-3"></i> <span>Manage Leave</span></a></li>
                <li class="nav-item"><a href="support_tickets.php" class="nav-link"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
                <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar me-3"></i> <span>Reports & Analytics</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user me-3"></i> <span>My Profile</span></a></li>
            </ul>
        </div>
        
        <div class="logout-section">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt me-3"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="fw-bold mb-0">Manage Users</h4>
                    <p class="text-muted mb-0">View and manage all registered customers</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Notification Bell -->
                <div class="dropdown">
                    <button class="btn btn-light position-relative" data-bs-toggle="dropdown" style="width: 42px; height: 42px; border-radius: 12px;">
                        <i class="fas fa-bell fa-lg"></i>
                        <?php 
                        $unreadCount = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE userID = " . $_SESSION['userID'] . " AND is_read = 0")->fetch_assoc()['count'] ?? 0;
                        if ($unreadCount > 0): 
                        ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 9px; padding: 2px 6px;">
                                <?php echo min($unreadCount, 9); ?><?php echo $unreadCount > 9 ? '+' : ''; ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow" style="width: 320px; max-height: 400px; overflow-y: auto;">
                        <li class="dropdown-header fw-bold">Notifications</li>
                        <?php 
                        $notifs = $conn->query("SELECT * FROM notifications WHERE userID = " . $_SESSION['userID'] . " ORDER BY created_at DESC LIMIT 5");
                        if ($notifs->num_rows > 0):
                            while ($n = $notifs->fetch_assoc()):
                        ?>
                            <li><a class="dropdown-item small" href="notifications.php"><?php echo htmlspecialchars($n['message']); ?></a></li>
                        <?php endwhile; else: ?>
                            <li><span class="dropdown-item text-muted small">No new notifications</span></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-center small text-primary" href="notifications.php">View All</a></li>
                    </ul>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                        <?php if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])): ?>
                            <img src="../<?php echo $admin['profile_picture']; ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                <span class="fw-bold fs-6"><?php echo strtoupper(substr($adminName, 0, 1)); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="text-start d-none d-md-block">
                            <div class="fw-semibold"><?php echo htmlspecialchars($adminName); ?></div>
                            <small class="text-muted">Administrator</small>
                        </div>
                        <i class="fas fa-chevron-down fa-sm text-muted ms-1"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Customer</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Address</th>
                                <th>Account Status</th>
                                <th>Email</th>
                                <th>Joined</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($customers->num_rows > 0): ?>
                                <?php while ($cust = $customers->fetch_assoc()) { ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($cust['Firstname'] . ' ' . $cust['Lastname']); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($cust['Email']); ?></td>
                                        <td><?php echo htmlspecialchars($cust['Contact'] ?? 'N/A'); ?></td>
                                        <td class="small text-muted"><?php echo substr(htmlspecialchars($cust['Address'] ?? 'N/A'), 0, 40); ?>...</td>
                                        <td>
                                            <?php if ($cust['verification_status'] == 'approved'): ?>
                                                <span class="badge bg-success px-3 py-2"><i class="fas fa-check me-1"></i> Verified</span>
                                            <?php elseif ($cust['verification_status'] == 'pending'): ?>
                                                <span class="badge bg-warning text-dark px-3 py-2"><i class="fas fa-clock me-1"></i> Pending</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary px-3 py-2"><i class="fas fa-times me-1"></i> Not Verified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($cust['email_verified'] == 1): ?>
                                                <span class="badge bg-success px-2 py-1"><i class="fas fa-envelope me-1"></i> Verified</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark px-2 py-1"><i class="fas fa-envelope me-1"></i> Unverified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?php echo date('M j, Y', strtotime($cust['created_at'])); ?></td>
                                        <td class="text-end pe-4">
                                            <?php if ($cust['verification_status'] == 'pending'): ?>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <?php if (!empty($cust['VerificationFile'])): ?>
                                                        <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#viewProofModal<?php echo $cust['userID']; ?>" title="View ID Proof">
                                                            <i class="fas fa-file-alt"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="manage_users.php?approve=<?php echo $cust['userID']; ?>" class="btn btn-success" title="Approve" onclick="return confirm('Approve this account?')">
                                                        <i class="fas fa-check"></i>
                                                    </a>
                                                    <a href="manage_users.php?reject=<?php echo $cust['userID']; ?>" class="btn btn-danger" title="Reject" onclick="return confirm('Reject this account?')">
                                                        <i class="fas fa-times"></i>
                                                    </a>
                                                </div>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewUserModal<?php echo $cust['userID']; ?>">
                                                    <i class="fas fa-eye me-1"></i> View Details
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">No customers registered yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- View User Modals -->
    <?php 
    // Reset customers result pointer
    $customers->data_seek(0);
    while ($cust = $customers->fetch_assoc()) { 
    ?>
    <div class="modal fade" id="viewUserModal<?php echo $cust['userID']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Customer Details - <?php echo htmlspecialchars($cust['Firstname'] . ' ' . $cust['Lastname']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">FULL NAME</label>
                                <div class="fw-bold"><?php echo htmlspecialchars($cust['Firstname'] . ' ' . $cust['Lastname']); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">EMAIL ADDRESS</label>
                                <div><?php echo htmlspecialchars($cust['Email']); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">PHONE NUMBER</label>
                                <div><?php echo htmlspecialchars($cust['Contact'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">ACCOUNT STATUS</label>
                                <div>
                                    <?php if ($cust['verification_status'] == 'approved'): ?>
                                        <span class="badge bg-success px-3 py-2">Verified</span>
                                    <?php elseif ($cust['verification_status'] == 'pending'): ?>
                                        <span class="badge bg-warning text-dark px-3 py-2">Pending Verification</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary px-3 py-2">Not Verified</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">REGISTRATION DATE</label>
                                <div><?php echo date('F j, Y g:i A', strtotime($cust['created_at'])); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">DELIVERY ADDRESS</label>
                                <div class="p-2 bg-light rounded"><?php echo htmlspecialchars($cust['Address'] ?? 'No address provided'); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">2FA STATUS</label>
                                <div>
                                    <?php if ($cust['two_factor_enabled']): ?>
                                        <span class="badge bg-success">Enabled</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Disabled</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-muted small">EMAIL VERIFIED</label>
                                <div>
                                    <?php if ($cust['email_verified']): ?>
                                        <span class="badge bg-success">Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">No</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($cust['VerificationFile'])): ?>
                    <div class="mt-3 pt-3 border-top">
                        <label class="form-label fw-semibold text-muted small">VERIFICATION DOCUMENT</label>
                        <div>
                            <?php if (file_exists('../' . $cust['VerificationFile'])): ?>
                                <a href="../<?php echo htmlspecialchars($cust['VerificationFile']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-file-download me-1"></i> View Verification File
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">File not found</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Close</button>
                    <?php if ($cust['verification_status'] == 'pending'): ?>
                        <a href="manage_users.php?approve=<?php echo $cust['userID']; ?>" class="btn btn-success px-4">Approve</a>
                        <a href="manage_users.php?reject=<?php echo $cust['userID']; ?>" class="btn btn-outline-danger px-4">Reject</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>

    <!-- View Proof Modals for Pending Users -->
    <?php 
    // Reset customers result pointer for proof modals
    $customers->data_seek(0);
    while ($cust = $customers->fetch_assoc()) { 
        if ($cust['verification_status'] == 'pending' && !empty($cust['VerificationFile'])) {
    ?>
    <div class="modal fade" id="viewProofModal<?php echo $cust['userID']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-alt me-2"></i> Verification Proof - <?php echo htmlspecialchars($cust['Firstname'] . ' ' . $cust['Lastname']); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <?php 
                    $filePath = '../' . $cust['VerificationFile'];
                    $fileExt = strtolower(pathinfo($cust['VerificationFile'], PATHINFO_EXTENSION));
                    ?>
                    
                    <?php if (file_exists($filePath)): ?>
                        <?php if (in_array($fileExt, ['jpg', 'jpeg', 'png'])): ?>
                            <!-- Image Preview -->
                            <img src="<?php echo htmlspecialchars($filePath); ?>" class="img-fluid rounded shadow-sm" style="max-height: 500px;" alt="ID Proof">
                        <?php elseif ($fileExt == 'pdf'): ?>
                            <!-- PDF Preview -->
                            <div class="alert alert-info">
                                <i class="fas fa-file-pdf fa-3x mb-3"></i>
                                <p>PDF Document</p>
                                <a href="<?php echo htmlspecialchars($filePath); ?>" target="_blank" class="btn btn-primary">
                                    <i class="fas fa-external-link-alt me-1"></i> Open PDF in New Tab
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Unsupported file format
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-3">
                            <a href="<?php echo htmlspecialchars($filePath); ?>" download class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-download me-1"></i> Download File
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            File not found: <?php echo htmlspecialchars($cust['VerificationFile']); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Close</button>
                    <a href="manage_users.php?approve=<?php echo $cust['userID']; ?>" class="btn btn-success px-4" onclick="return confirm('Approve this verification?')">
                        <i class="fas fa-check me-1"></i> Approve
                    </a>
                    <a href="manage_users.php?reject=<?php echo $cust['userID']; ?>" class="btn btn-outline-danger px-4" onclick="return confirm('Reject this verification?')">
                        <i class="fas fa-times me-1"></i> Reject
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php 
        }
    } 
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.createElement('button');
        mobileToggle.className = 'btn btn-light d-lg-none position-fixed shadow-sm';
        mobileToggle.style.cssText = 'top: 22px; left: 22px; z-index: 1100; border-radius: 12px;';
        mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
        document.body.appendChild(mobileToggle);
        mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
    </script>
</body>
</html>