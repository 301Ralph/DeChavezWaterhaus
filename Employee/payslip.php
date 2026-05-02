<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../login.php");
    exit();
}

$userID = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Fetch employee data
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch ALL payslips (history)
$payslipsStmt = $conn->prepare("
    SELECT * FROM payroll 
    WHERE userID = ? 
    ORDER BY created_at DESC
");
$payslipsStmt->bind_param("i", $userID);
$payslipsStmt->execute();
$payslipsResult = $payslipsStmt->get_result();
$payslips = [];
while ($row = $payslipsResult->fetch_assoc()) {
    $payslips[] = $row;
}
$payslipsStmt->close();

// Get the latest payslip for display
$payroll = !empty($payslips) ? $payslips[0] : null;

// Handle flash messages
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
if ($flashMessage) {
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

if (!$payroll) {
    $_SESSION['flash_message'] = "No payroll record found. Please contact admin.";
    $_SESSION['flash_type'] = "error";
    header("Location: employee_dashboard.php");
    exit();
}

// Handle Print Request
if (isset($_GET['print']) && $_GET['print'] == '1') {
    // Page will auto-trigger print via JavaScript
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payslip • Employee</title>
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
        
        .nav-menu { flex: 1; overflow-y: auto; padding-bottom: 20px; }
        .logout-section { padding: 15px 10px; border-top: 1px solid #eee; background: white; }
        
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
        
        .payslip-card { 
            background: white; 
            border-radius: 20px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .payslip-header { 
            background: linear-gradient(135deg, #0077B6 0%, #023E8A 100%); 
            color: white; 
            padding: 25px; 
            border-radius: 20px 20px 0 0;
            text-align: center;
        }
        .amount { font-size: 1.8rem; font-weight: 700; }
        
        .payslip-history-item {
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
        }
        .payslip-history-item:hover {
            background-color: #f8f9fa;
            border-left-color: #0077B6;
        }
        .payslip-history-item.active {
            background-color: #e8f4ff;
            border-left-color: #0077B6;
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .btn, .d-grid, a[href] { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            body { background: white !important; }
            .payslip-card { 
                box-shadow: none !important; 
                border: 2px solid #0077B6 !important;
                max-width: 100% !important;
                margin: 0 !important;
            }
            .payslip-header { 
                background: #0077B6 !important; 
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
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
                <small class="d-block text-muted">Employee Portal</small>
            </div>
        </div>
        
        <div class="nav-menu px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="employee_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="attendance.php" class="nav-link"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
                <li class="nav-item"><a href="payslip.php" class="nav-link active"><i class="fas fa-file-invoice-dollar me-3"></i> <span>My Payslip</span></a></li>
                <li class="nav-item"><a href="leave_request.php" class="nav-link"><i class="fas fa-calendar-alt me-3"></i> <span>Leave Requests</span></a></li>
                <li class="nav-item"><a href="my_deliveries.php" class="nav-link"><i class="fas fa-truck me-3"></i> <span>My Deliveries</span></a></li>
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
        <!-- Flash Alert -->
        <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo $flashType === 'success' ? 'success' : ($flashType === 'error' ? 'danger' : ($flashType === 'warning' ? 'warning' : 'info')); ?> alert-dismissible fade show mb-4" role="alert" style="border-radius: 12px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
            <div class="d-flex align-items-center">
                <i class="fas fa-<?php echo $flashType === 'success' ? 'check-circle' : ($flashType === 'error' ? 'exclamation-circle' : ($flashType === 'warning' ? 'exclamation-triangle' : 'info-circle')); ?> me-3 fa-lg"></i>
                <div><?php echo htmlspecialchars($flashMessage); ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        
        <!-- Top Navbar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="fw-bold mb-0">My Payslip</h4>
                    <p class="text-muted mb-0">View and download your latest payslip</p>
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
                
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                        <?php if (!empty($employee['profile_picture']) && file_exists('../' . $employee['profile_picture'])): ?>
                            <img src="../<?php echo $employee['profile_picture']; ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                <span class="fw-bold fs-6"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="text-start d-none d-md-block">
                            <div class="fw-semibold"><?php echo htmlspecialchars($userName); ?></div>
                            <small class="text-muted">Employee</small>
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

        <div class="text-center mb-4">
            <h4 class="fw-bold">My Payslips</h4>
            <p class="text-muted">View your payslip history and print anytime</p>
        </div>
        
        <?php if (empty($payslips)): ?>
        <div class="card border-0 shadow-sm text-center py-5">
            <div class="card-body">
                <i class="fas fa-file-invoice-dollar fa-4x text-muted mb-3"></i>
                <h5 class="fw-bold">No Payslips Yet</h5>
                <p class="text-muted">Your payslips will appear here once payroll is processed.</p>
                <a href="employee_dashboard.php" class="btn btn-primary">Back to Dashboard</a>
            </div>
        </div>
        <?php else: ?>
        
        <div class="row g-4">
            <!-- Payslip History Sidebar -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="fw-bold mb-0"><i class="fas fa-history me-2 text-primary"></i> Payslip History</h6>
                        <small class="text-muted"><?php echo count($payslips); ?> record(s)</small>
                    </div>
                    <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                        <?php foreach ($payslips as $index => $p): ?>
                        <a href="?view=<?php echo $p['payrollID']; ?>" class="d-block text-decoration-none payslip-history-item p-3 border-bottom <?php echo (isset($_GET['view']) && $_GET['view'] == $p['payrollID']) || (!isset($_GET['view']) && $index === 0) ? 'active' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold text-dark"><?php echo date('M Y', strtotime($p['period_end'])); ?></div>
                                    <small class="text-muted"><?php echo date('M d', strtotime($p['period_start'])); ?> - <?php echo date('M d, Y', strtotime($p['period_end'])); ?></small>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-success">₱<?php echo number_format($p['net_pay'], 0); ?></div>
                                    <span class="badge bg-<?php echo $p['status'] === 'Paid' ? 'success' : 'warning'; ?> small"><?php echo $p['status']; ?></span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Payslip Detail View -->
            <div class="col-lg-8">
                <?php 
                // Get the payslip to display (from URL or latest)
                $displayPayroll = $payroll;
                if (isset($_GET['view'])) {
                    foreach ($payslips as $p) {
                        if ($p['payrollID'] == $_GET['view']) {
                            $displayPayroll = $p;
                            break;
                        }
                    }
                }
                ?>
                
                <div class="payslip-card">
                    <div class="payslip-header">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <i class="fas fa-file-invoice-dollar fa-2x"></i>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-light text-dark fs-6">₱<?php echo number_format($displayPayroll['net_pay'], 2); ?></span>
                            </div>
                        </div>
                        <h5 class="fw-bold mb-1">DE CHAVEZ WATERHAUS</h5>
                        <p class="mb-0 opacity-75">Official Payslip • <?php echo date('F Y', strtotime($displayPayroll['period_end'])); ?></p>
                    </div>
                    
                    <div class="p-4">
                        <div class="row mb-4">
                            <div class="col-6">
                                <div class="text-muted small">Employee</div>
                                <div class="fw-bold"><?php echo htmlspecialchars($employee['Firstname'] . ' ' . $employee['Lastname']); ?></div>
                            </div>
                            <div class="col-6 text-end">
                                <div class="text-muted small">Pay Period</div>
                                <div class="fw-bold"><?php echo date('M d, Y', strtotime($displayPayroll['period_start'])); ?> - <?php echo date('M d, Y', strtotime($displayPayroll['period_end'])); ?></div>
                            </div>
                        </div>
                        
                        <div class="border rounded p-3 mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Hours Worked</span>
                                <span class="fw-bold"><?php echo number_format($displayPayroll['total_hours'], 1); ?> hrs</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Hourly Rate</span>
                                <span>₱<?php echo number_format($displayPayroll['hourly_rate'], 2); ?></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="fw-bold">Gross Pay</span>
                                <span class="fw-bold">₱<?php echo number_format($displayPayroll['gross_pay'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between text-danger">
                                <span>Deductions (10%)</span>
                                <span>-₱<?php echo number_format($displayPayroll['deductions'], 2); ?></span>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold fs-5">NET PAY</span>
                                <span class="fw-bold fs-5 text-success amount">₱<?php echo number_format($displayPayroll['net_pay'], 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button onclick="window.print()" class="btn btn-primary btn-lg">
                                <i class="fas fa-print me-2"></i> Print This Payslip
                            </button>
                            <a href="employee_dashboard.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                    
                    <div class="px-4 pb-4 text-center border-top pt-3">
                        <small class="text-muted">
                            Generated on <?php echo date('F d, Y', strtotime($displayPayroll['created_at'])); ?><br>
                            This is a computer-generated document.
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <style>
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: 260px; background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; }
        .main-content { margin-left: 260px; padding: 30px; }
        .sidebar .logo { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eee; }
        .sidebar .nav-link { color: #495057; padding: 14px 22px; display: flex; align-items: center; gap: 14px; font-weight: 500; border-radius: 12px; margin: 4px 10px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: #f0f7ff; color: #0077B6; }
        @media (max-width: 991.98px) { .main-content { margin-left: 0; } .sidebar { transform: translateX(-100%); } .sidebar.show { transform: translateX(0); } }
    </style>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        }
        
        // Auto-print if ?print=1 parameter is present
        <?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
        window.onload = function() {
            window.print();
        }
        <?php endif; ?>
    </script>
</body>
</html>