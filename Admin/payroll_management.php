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

// Handle mark as paid
if (isset($_GET['mark_paid'])) {
    $payrollID = intval($_GET['mark_paid']);
    $conn->query("UPDATE payroll SET status = 'Paid', payment_date = CURDATE() WHERE payrollID = $payrollID");
    echo '<script>window.location = "payroll_management.php";</script>';
    exit();
}

// Handle payroll processing
if (isset($_POST['process_payroll'])) {
    $userID = intval($_POST['userID']);
    $periodStart = $_POST['period_start'];
    $periodEnd = $_POST['period_end'];
    
    // Calculate total hours for the period
    $hoursQuery = $conn->prepare("
        SELECT SUM(total_hours) as total_hours 
        FROM attendance 
        WHERE userID = ? AND DATE(clock_in) BETWEEN ? AND ?
    ");
    $hoursQuery->bind_param("iss", $userID, $periodStart, $periodEnd);
    $hoursQuery->execute();
    $hoursResult = $hoursQuery->get_result()->fetch_assoc();
    $totalHours = $hoursResult['total_hours'] ?? 0;
    $hoursQuery->close();
    
    // Get employee rate
    $empQuery = $conn->prepare("SELECT hourly_rate, daily_rate FROM customers WHERE userID = ?");
    $empQuery->bind_param("i", $userID);
    $empQuery->execute();
    $empData = $empQuery->get_result()->fetch_assoc();
    $hourlyRate = $empData['hourly_rate'] ?? 100;
    $dailyRate = $empData['daily_rate'] ?? 800;
    $empQuery->close();
    
    // Calculate gross pay (use hourly rate)
    $grossPay = $totalHours * $hourlyRate;
    
    // Basic deductions (10% for taxes/benefits)
    $deductions = $grossPay * 0.10;
    $netPay = $grossPay - $deductions;
    $status = 'Processed';
    
    // Check if payroll already exists for this period
    $checkQuery = $conn->prepare("SELECT payrollID FROM payroll WHERE userID = ? AND period_start = ? AND period_end = ?");
    $checkQuery->bind_param("iss", $userID, $periodStart, $periodEnd);
    $checkQuery->execute();
    $exists = $checkQuery->get_result()->num_rows > 0;
    $checkQuery->close();
    
    if ($exists) {
        echo '<script>alert("Payroll already processed for this period!"); window.location = "payroll_management.php";</script>';
    } else {
        $insert = $conn->prepare("
            INSERT INTO payroll (userID, period_start, period_end, total_hours, hourly_rate, daily_rate, gross_pay, deductions, net_pay, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Processed')
        ");
        $insert->bind_param("issdddddds", $userID, $periodStart, $periodEnd, $totalHours, $hourlyRate, $dailyRate, $grossPay, $deductions, $netPay, $status);
        
        if ($insert->execute()) {
            echo '<script>alert("Payroll processed successfully! Net Pay: ₱' . number_format($netPay, 2) . '"); window.location = "payroll_management.php";</script>';
        } else {
            echo '<script>alert("Error processing payroll."); window.location = "payroll_management.php";</script>';
        }
        $insert->close();
    }
    exit();
}

// Fetch all payroll records
$payrollRecords = $conn->query("
    SELECT p.*, c.Firstname, c.Lastname, c.profile_picture
    FROM payroll p
    JOIN customers c ON p.userID = c.userID
    ORDER BY p.created_at DESC
    LIMIT 50
");

// Fetch employees for payroll processing
$employees = $conn->query("SELECT userID, Firstname, Lastname, hourly_rate, daily_rate FROM customers WHERE Role = 'employee' ORDER BY Firstname");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Management • Admin</title>
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
        
        .payroll-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            transition: transform 0.2s ease;
        }
        
        .payroll-card:hover {
            transform: translateY(-3px);
        }
        
        .amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: #023E8A;
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
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_employees.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Employees</span></a></li>
                <li class="nav-item"><a href="attendance_management.php" class="nav-link"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
                <li class="nav-item"><a href="payroll_management.php" class="nav-link active"><i class="fas fa-money-bill me-3"></i> <span>Payroll</span></a></li>
                <li class="nav-item"><a href="generate_payslip.php" class="nav-link"><i class="fas fa-file-pdf me-3"></i> <span>Generate Payslip</span></a></li>
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
                    <h4 class="fw-bold mb-0">Payroll Management</h4>
                    <p class="text-muted mb-0">Process and manage employee payroll</p>
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

        <!-- Process Payroll Form -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="fw-bold mb-0">Process New Payroll</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Employee</label>
                        <select name="userID" class="form-select" required>
                            <option value="">Select Employee...</option>
                            <?php while ($emp = $employees->fetch_assoc()): ?>
                                <option value="<?php echo $emp['userID']; ?>">
                                    <?php echo htmlspecialchars($emp['Firstname'] . ' ' . $emp['Lastname']); ?> 
                                    (₱<?php echo number_format($emp['hourly_rate'], 0); ?>/hr)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Period Start</label>
                        <input type="date" name="period_start" class="form-control" value="<?php echo date('Y-m-01'); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Period End</label>
                        <input type="date" name="period_end" class="form-control" value="<?php echo date('Y-m-t'); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" name="process_payroll" class="btn btn-primary px-5 w-100">
                            <i class="fas fa-calculator me-2"></i> Process Payroll
                        </button>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted d-block">Auto-calculates based on attendance records</small>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payroll Records -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Payroll History</h6>
                <span class="badge bg-primary"><?php echo $payrollRecords->num_rows; ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Employee</th>
                                <th>Period</th>
                                <th>Hours</th>
                                <th>Rate</th>
                                <th>Gross Pay</th>
                                <th>Deductions</th>
                                <th>Net Pay</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($payrollRecords->num_rows > 0): ?>
                                <?php while ($payroll = $payrollRecords->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <?php if (!empty($payroll['profile_picture']) && file_exists('../' . $payroll['profile_picture'])): ?>
                                                    <img src="../<?php echo $payroll['profile_picture']; ?>" alt="" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover;" class="me-2">
                                                <?php else: ?>
                                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                        <span class="fw-bold small"><?php echo strtoupper(substr($payroll['Firstname'], 0, 1)); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="fw-semibold"><?php echo htmlspecialchars($payroll['Firstname'] . ' ' . $payroll['Lastname']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <?php echo date('M j', strtotime($payroll['period_start'])); ?> - 
                                                <?php echo date('M j, Y', strtotime($payroll['period_end'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-bold"><?php echo number_format($payroll['total_hours'], 1); ?> hrs</span>
                                        </td>
                                        <td>
                                            <div class="small">₱<?php echo number_format($payroll['hourly_rate'], 0); ?>/hr</div>
                                        </td>
                                        <td>
                                            <span class="fw-semibold">₱<?php echo number_format($payroll['gross_pay'], 2); ?></span>
                                        </td>
                                        <td>
                                            <span class="text-danger">-₱<?php echo number_format($payroll['deductions'], 2); ?></span>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-success amount">₱<?php echo number_format($payroll['net_pay'], 2); ?></span>
                                        </td>
                                        <td>
                                            <?php 
                                            $statusClass = 'secondary';
                                            if ($payroll['status'] == 'Processed') $statusClass = 'primary';
                                            elseif ($payroll['status'] == 'Paid') $statusClass = 'success';
                                            ?>
                                            <span class="badge bg-<?php echo $statusClass; ?> px-3 py-2">
                                                <?php echo $payroll['status']; ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <?php if ($payroll['status'] == 'Processed'): ?>
                                                <button class="btn btn-sm btn-success" onclick="markAsPaid(<?php echo $payroll['payrollID']; ?>)">
                                                    <i class="fas fa-check me-1"></i> Mark Paid
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">Paid on <?php echo date('M j', strtotime($payroll['payment_date'] ?? $payroll['updated_at'])); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-money-bill fa-3x mb-3 opacity-50"></i>
                                        <p>No payroll records yet.<br>Process your first payroll above!</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        }
        
        function markAsPaid(payrollID) {
            if (confirm('Mark this payroll as paid?')) {
                window.location = 'payroll_management.php?mark_paid=' + payrollID;
            }
        }
    </script>
</body>
</html>