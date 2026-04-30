<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'employee') {
    echo '<script>alert("Access denied. Employees only."); window.location = "../login.php";</script>';
    exit();
}

$userID = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Calculate leave balances
$currentYear = date('Y');

// Get total approved vacation days this year
$vacationQuery = $conn->prepare("
    SELECT SUM(total_days) as total 
    FROM leaves 
    WHERE userID = ? AND leave_type = 'Vacation' AND status = 'Approved' 
    AND YEAR(start_date) = ?
");
$vacationQuery->bind_param("ii", $userID, $currentYear);
$vacationQuery->execute();
$vacationUsed = $vacationQuery->get_result()->fetch_assoc()['total'] ?? 0;
$vacationQuery->close();
$vacationRemaining = max(0, 15 - $vacationUsed); // 15 days annual vacation

// Get total approved sick days this year
$sickQuery = $conn->prepare("
    SELECT SUM(total_days) as total 
    FROM leaves 
    WHERE userID = ? AND leave_type = 'Sick' AND status = 'Approved' 
    AND YEAR(start_date) = ?
");
$sickQuery->bind_param("ii", $userID, $currentYear);
$sickQuery->execute();
$sickUsed = $sickQuery->get_result()->fetch_assoc()['total'] ?? 0;
$sickQuery->close();
$sickRemaining = max(0, 10 - $sickUsed); // 10 days annual sick leave

// Get total approved emergency days this year
$emergencyQuery = $conn->prepare("
    SELECT SUM(total_days) as total 
    FROM leaves 
    WHERE userID = ? AND leave_type = 'Emergency' AND status = 'Approved' 
    AND YEAR(start_date) = ?
");
$emergencyQuery->bind_param("ii", $userID, $currentYear);
$emergencyQuery->execute();
$emergencyUsed = $emergencyQuery->get_result()->fetch_assoc()['total'] ?? 0;
$emergencyQuery->close();
$emergencyRemaining = max(0, 5 - $emergencyUsed); // 5 days annual emergency leave

// Handle leave request submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_leave'])) {
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = htmlspecialchars($_POST['reason']);
    
    // Calculate total days
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $total_days = $end->diff($start)->days + 1;
    
    // Check if employee has enough leave balance
    $canProceed = true;
    $errorMsg = '';
    
    if ($leave_type == 'Vacation' && $total_days > $vacationRemaining) {
        $canProceed = false;
        $errorMsg = "You only have $vacationRemaining vacation days remaining!";
    } elseif ($leave_type == 'Sick' && $total_days > $sickRemaining) {
        $canProceed = false;
        $errorMsg = "You only have $sickRemaining sick days remaining!";
    } elseif ($leave_type == 'Emergency' && $total_days > $emergencyRemaining) {
        $canProceed = false;
        $errorMsg = "You only have $emergencyRemaining emergency days remaining!";
    }
    
    if ($canProceed) {
        $stmt = $conn->prepare("INSERT INTO leaves (userID, leave_type, start_date, end_date, total_days, reason) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssis", $userID, $leave_type, $start_date, $end_date, $total_days, $reason);
        
        if ($stmt->execute()) {
            echo '<script>alert("Leave request submitted successfully! Admin will review it soon."); window.location = "leave_request.php";</script>';
        } else {
            echo '<script>alert("Error submitting leave request. Please try again.");</script>';
        }
        $stmt->close();
    } else {
        echo '<script>alert("' . $errorMsg . '"); window.location = "leave_request.php";</script>';
    }
}

// Fetch user's leave requests
$leaves = $conn->prepare("SELECT * FROM leaves WHERE userID = ? ORDER BY created_at DESC");
$leaves->bind_param("i", $userID);
$leaves->execute();
$leaveResult = $leaves->get_result();
$leaves->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Request • Employee</title>
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
        .leave-card { background: white; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
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
                <small class="d-block text-muted">Employee Portal</small>
            </div>
        </div>
        
        <div class="nav-menu px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="employee_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="attendance.php" class="nav-link"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
                <li class="nav-item"><a href="payslip.php" class="nav-link"><i class="fas fa-file-invoice-dollar me-3"></i> <span>My Payslip</span></a></li>
                <li class="nav-item"><a href="leave_request.php" class="nav-link active"><i class="fas fa-calendar-alt me-3"></i> <span>Leave Requests</span></a></li>
                <li class="nav-item"><a href="my_deliveries.php" class="nav-link"><i class="fas fa-truck me-3"></i> <span>My Deliveries</span></a></li>
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
                    <h4 class="fw-bold mb-0">Leave Requests</h4>
                    <p class="text-muted mb-0">Request time off and track your leave status</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <!-- Notification Bell -->
                <div class="dropdown">
                    <button class="btn btn-light position-relative" data-bs-toggle="dropdown" style="width: 42px; height: 42px; border-radius: 12px;">
                        <i class="fas fa-bell fa-lg"></i>
                        <?php 
                        $unreadCount = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['count'] ?? 0;
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
                        $notifs = $conn->query("SELECT * FROM notifications WHERE userID = $userID ORDER BY created_at DESC LIMIT 5");
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
                
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestLeaveModal">
                    <i class="fas fa-plus me-2"></i> Request Leave
                </button>
            </div>
        </div>

        <!-- Leave Balance Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #0077B6 0%, #023E8A 100%); color: white;">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-white-50 small">VACATION LEAVE</div>
                                <div class="fw-bold fs-2"><?php echo $vacationRemaining; ?></div>
                                <div class="small">days remaining (<?php echo $vacationUsed; ?> used)</div>
                            </div>
                            <i class="fas fa-umbrella-beach fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white;">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-white-50 small">SICK LEAVE</div>
                                <div class="fw-bold fs-2"><?php echo $sickRemaining; ?></div>
                                <div class="small">days remaining (<?php echo $sickUsed; ?> used)</div>
                            </div>
                            <i class="fas fa-head-side-cough fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); color: white;">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-white-50 small">EMERGENCY LEAVE</div>
                                <div class="fw-bold fs-2"><?php echo $emergencyRemaining; ?></div>
                                <div class="small">days remaining (<?php echo $emergencyUsed; ?> used)</div>
                            </div>
                            <i class="fas fa-exclamation-triangle fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Leave History -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0"><i class="fas fa-history me-2"></i> My Leave History</h6>
            </div>
            <div class="card-body p-0">
                <?php if ($leaveResult->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Type</th>
                                    <th>Period</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                    <th class="pe-4">Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($leave = $leaveResult->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="badge bg-info"><?php echo $leave['leave_type']; ?></span>
                                        </td>
                                        <td>
                                            <?php echo date('M d', strtotime($leave['start_date'])); ?> - 
                                            <?php echo date('M d, Y', strtotime($leave['end_date'])); ?>
                                        </td>
                                        <td><strong><?php echo $leave['total_days']; ?> day(s)</strong></td>
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
                                        <td class="pe-4">
                                            <small class="text-muted"><?php echo htmlspecialchars($leave['reason']); ?></small>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No leave requests yet. Click the button above to request time off.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Request Leave Modal -->
    <div class="modal fade" id="requestLeaveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i> Request Leave</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Leave Type</label>
                            <select name="leave_type" class="form-select" required>
                                <option value="Sick">Sick Leave</option>
                                <option value="Vacation">Vacation Leave</option>
                                <option value="Emergency">Emergency Leave</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-semibold">End Date</label>
                                <input type="date" name="end_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Reason</label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Please provide a brief reason for your leave request..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_leave" class="btn btn-primary">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>