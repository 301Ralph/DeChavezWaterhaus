<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminID = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';

// Handle leave approval/rejection
if (isset($_GET['action']) && isset($_GET['leaveID'])) {
    $leaveID = intval($_GET['leaveID']);
    $action = $_GET['action'];
    $newStatus = ($action == 'approve') ? 'Approved' : 'Rejected';
    
    $stmt = $conn->prepare("UPDATE leaves SET status = ?, approved_by = ?, approved_at = NOW() WHERE leaveID = ?");
    $stmt->bind_param("sii", $newStatus, $adminID, $leaveID);
    
    if ($stmt->execute()) {
        // Add notification to employee
        $notifStmt = $conn->prepare("SELECT userID FROM leaves WHERE leaveID = ?");
        $notifStmt->bind_param("i", $leaveID);
        $notifStmt->execute();
        $userResult = $notifStmt->get_result()->fetch_assoc();
        $notifStmt->close();
        
        if ($userResult) {
            $msg = "Your leave request has been $newStatus by admin.";
            $conn->query("INSERT INTO notifications (userID, message, type) VALUES ({$userResult['userID']}, '$msg', 'Leave')");
        }
        
        echo '<script>alert("Leave request ' . strtolower($newStatus) . ' successfully!"); window.location = "leave_management.php";</script>';
    }
    $stmt->close();
    exit();
}

// Fetch all leave requests
$leaves = $conn->query("
    SELECT l.*, c.Firstname, c.Lastname, c.profile_picture 
    FROM leaves l 
    JOIN customers c ON l.userID = c.userID 
    ORDER BY l.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root { --primary: #0077B6; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: 260px; background: white; box-shadow: 2px 0 10px rgba(0,0,0,0.1); z-index: 1000; }
        .main-content { margin-left: 260px; padding: 30px; }
        .nav-link { color: #495057; padding: 12px 20px; border-radius: 10px; margin: 3px 0; }
        .nav-link:hover, .nav-link.active { background: #e8f4ff; color: #0077B6; }
        .status-badge { padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 500; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
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
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_employees.php" class="nav-link"><i class="fas fa-user-tie me-3"></i> <span>Manage Employees</span></a></li>
                <li class="nav-item"><a href="attendance_management.php" class="nav-link"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
                <li class="nav-item"><a href="payroll_management.php" class="nav-link"><i class="fas fa-money-bill me-3"></i> <span>Payroll</span></a></li>
                <li class="nav-item"><a href="generate_payslip.php" class="nav-link"><i class="fas fa-file-pdf me-3"></i> <span>Generate Payslip</span></a></li>
                <li class="nav-item"><a href="leave_management.php" class="nav-link active"><i class="fas fa-calendar-alt me-3"></i> <span>Manage Leave</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user me-3"></i> <span>My Profile</span></a></li>
            </ul>
        </div>
        
        <div class="logout-section position-absolute bottom-0 w-100 p-3 border-top">
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
                    <h4 class="fw-bold mb-0">Leave Management</h4>
                    <p class="text-muted mb-0">Review and approve employee leave requests</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Notification Bell -->
                <div class="dropdown">
                    <button class="btn btn-light position-relative" data-bs-toggle="dropdown" style="width: 42px; height: 42px; border-radius: 12px;">
                        <i class="fas fa-bell fa-lg"></i>
                        <?php 
                        $unreadCount = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE userID = $adminID AND is_read = 0")->fetch_assoc()['count'] ?? 0;
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
                        $notifs = $conn->query("SELECT * FROM notifications WHERE userID = $adminID ORDER BY created_at DESC LIMIT 5");
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
                
                <div class="badge bg-primary fs-6 px-3 py-2">
                    <?php echo $leaves->num_rows; ?> Total Requests
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if ($leaves->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Employee</th>
                                    <th>Type</th>
                                    <th>Period</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                    <th class="pe-4 text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($leave = $leaves->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <?php if (!empty($leave['profile_picture'])): ?>
                                                    <img src="../<?php echo $leave['profile_picture']; ?>" alt="" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover;" class="me-2">
                                                <?php else: ?>
                                                    <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                        <span class="fw-bold"><?php echo strtoupper(substr($leave['Firstname'], 0, 1)); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($leave['Firstname'] . ' ' . $leave['Lastname']); ?></div>
                                                    <small class="text-muted">ID: <?php echo $leave['userID']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="badge bg-info"><?php echo $leave['leave_type']; ?></span></td>
                                        <td>
                                            <small>
                                                <?php echo date('M d', strtotime($leave['start_date'])); ?> - 
                                                <?php echo date('M d, Y', strtotime($leave['end_date'])); ?>
                                            </small>
                                        </td>
                                        <td><strong><?php echo $leave['total_days']; ?></strong></td>
                                        <td>
                                            <?php 
                                            $statusClass = 'secondary';
                                            if ($leave['status'] == 'Approved') $statusClass = 'success';
                                            elseif ($leave['status'] == 'Rejected') $statusClass = 'danger';
                                            elseif ($leave['status'] == 'Pending') $statusClass = 'warning';
                                            ?>
                                            <span class="status-badge bg-<?php echo $statusClass; ?> text-white">
                                                <?php echo $leave['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted" style="max-width: 200px; display: block; overflow: hidden; text-overflow: ellipsis;">
                                                <?php echo htmlspecialchars($leave['reason']); ?>
                                            </small>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <?php if ($leave['status'] == 'Pending'): ?>
                                                <a href="leave_management.php?action=approve&leaveID=<?php echo $leave['leaveID']; ?>" 
                                                   class="btn btn-sm btn-success" onclick="return confirm('Approve this leave request?')">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                                <a href="leave_management.php?action=reject&leaveID=<?php echo $leave['leaveID']; ?>" 
                                                   class="btn btn-sm btn-danger" onclick="return confirm('Reject this leave request?')">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">Processed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No leave requests to review.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>