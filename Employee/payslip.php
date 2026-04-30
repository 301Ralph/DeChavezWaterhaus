<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'employee') {
    echo '<script>alert("Access denied. Employees only."); window.location = "../login.php";</script>';
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

// Fetch latest payroll
$payrollStmt = $conn->prepare("
    SELECT * FROM payroll 
    WHERE userID = ? 
    ORDER BY created_at DESC 
    LIMIT 1
");
$payrollStmt->bind_param("i", $userID);
$payrollStmt->execute();
$payroll = $payrollStmt->get_result()->fetch_assoc();
$payrollStmt->close();

if (!$payroll) {
    echo '<script>alert("No payroll record found. Please contact admin."); window.location = "employee_dashboard.php";</script>';
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
            max-width: 600px;
            margin: 0 auto;
        }
        .payslip-header { 
            background: linear-gradient(135deg, #0077B6 0%, #023E8A 100%); 
            color: white; 
            padding: 30px; 
            border-radius: 20px 20px 0 0;
            text-align: center;
        }
        .amount { font-size: 2rem; font-weight: 700; }
        
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
            <h4 class="fw-bold">My Payslip</h4>
            <p class="text-muted">View and download your latest payslip</p>
        </div>
        
        <div class="payslip-card">
            <div class="payslip-header">
                <div class="mb-3">
                    <i class="fas fa-file-invoice-dollar fa-3x"></i>
                </div>
                <h5 class="fw-bold mb-1">DE CHAVEZ WATERHAUS</h5>
                <p class="mb-0 opacity-75">Official Payslip</p>
            </div>
            
            <div class="p-4">
                <div class="row mb-4">
                    <div class="col-6">
                        <div class="text-muted small">Employee</div>
                        <div class="fw-bold"><?php echo htmlspecialchars($employee['Firstname'] . ' ' . $employee['Lastname']); ?></div>
                    </div>
                    <div class="col-6 text-end">
                        <div class="text-muted small">Pay Period</div>
                        <div class="fw-bold"><?php echo date('M d', strtotime($payroll['period_start'])); ?> - <?php echo date('M d, Y', strtotime($payroll['period_end'])); ?></div>
                    </div>
                </div>
                
                <div class="border rounded p-3 mb-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Hours</span>
                        <span class="fw-bold"><?php echo number_format($payroll['total_hours'], 1); ?> hrs</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Hourly Rate</span>
                        <span>₱<?php echo number_format($payroll['hourly_rate'], 2); ?></span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-bold">Gross Pay</span>
                        <span class="fw-bold">₱<?php echo number_format($payroll['gross_pay'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-danger">
                        <span>Deductions (10%)</span>
                        <span>-₱<?php echo number_format($payroll['deductions'], 2); ?></span>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between">
                        <span class="fw-bold fs-5">NET PAY</span>
                        <span class="fw-bold fs-5 text-success amount">₱<?php echo number_format($payroll['net_pay'], 2); ?></span>
                    </div>
                </div>
                
                <div class="d-grid gap-2">
                    <button onclick="window.print()" class="btn btn-primary btn-lg">
                        <i class="fas fa-print me-2"></i> Print Payslip
                    </button>
                    <a href="employee_dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            
            <div class="px-4 pb-4 text-center">
                <small class="text-muted">
                    Generated on <?php echo date('F d, Y'); ?><br>
                    This is a computer-generated document.
                </small>
            </div>
        </div>
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