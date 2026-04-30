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

// Handle status update
if (isset($_GET['update_status'])) {
    $attendanceID = intval($_GET['update_status']);
    $newStatus = $_GET['status'];
    $conn->query("UPDATE attendance SET status = '$newStatus' WHERE attendanceID = $attendanceID");
    echo '<script>window.location = "attendance_management.php";</script>';
    exit();
}

// Handle edit attendance (Admin can edit clock-in and clock-out)
if (isset($_POST['edit_attendance'])) {
    $attendanceID = intval($_POST['attendanceID']);
    $newClockIn = $_POST['clock_in'];
    $newClockOut = $_POST['clock_out'] ?: null;
    
    // Fetch old values for audit log
    $oldStmt = $conn->prepare("SELECT clock_in, clock_out, total_hours FROM attendance WHERE attendanceID = ?");
    $oldStmt->bind_param("i", $attendanceID);
    $oldStmt->execute();
    $oldData = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();
    
    // Recalculate total hours if clock_out exists
    $totalHours = null;
    if ($newClockOut) {
        $clockIn = new DateTime($newClockIn);
        $clockOut = new DateTime($newClockOut);
        $interval = $clockIn->diff($clockOut);
        $totalHours = $interval->h + ($interval->i / 60);
        if ($totalHours > 10) $totalHours = 10;
        $totalHours = round($totalHours - 1.5, 2); // Deduct 1.5 hr break
        if ($totalHours < 0) $totalHours = 0;
    }
    
    if ($newClockOut) {
        $stmt = $conn->prepare("UPDATE attendance SET clock_in = ?, clock_out = ?, total_hours = ? WHERE attendanceID = ?");
        $stmt->bind_param("ssdi", $newClockIn, $newClockOut, $totalHours, $attendanceID);
    } else {
        $stmt = $conn->prepare("UPDATE attendance SET clock_in = ?, clock_out = NULL, total_hours = NULL WHERE attendanceID = ?");
        $stmt->bind_param("si", $newClockIn, $attendanceID);
    }
    
    if ($stmt->execute()) {
        // Log the change to audit table
        $logStmt = $conn->prepare("INSERT INTO attendance_audit_log 
            (attendanceID, changed_by, old_clock_in, new_clock_in, old_clock_out, new_clock_out, old_total_hours, new_total_hours, change_reason) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $changeReason = "Admin edit via attendance_management.php";
        $logStmt->bind_param("iissssdds", 
            $attendanceID, $adminID, 
            $oldData['clock_in'], $newClockIn,
            $oldData['clock_out'], $newClockOut,
            $oldData['total_hours'], $totalHours,
            $changeReason
        );
        $logStmt->execute();
        $logStmt->close();
        
        echo '<script>alert("Attendance updated successfully! Change logged."); window.location = "attendance_management.php";</script>';
    } else {
        echo '<script>alert("Error updating attendance."); window.location = "attendance_management.php";</script>';
    }
    $stmt->close();
    exit();
}

// Fetch all employees
$employees = $conn->query("SELECT userID, Firstname, Lastname, Email, profile_picture, hourly_rate, daily_rate, shift_start_time FROM customers WHERE Role = 'employee' ORDER BY Firstname");

// Fetch today's attendance
$today = date('Y-m-d');
$todayAttendance = $conn->query("
    SELECT a.*, c.Firstname, c.Lastname, c.profile_picture, c.shift_start_time
    FROM attendance a
    JOIN customers c ON a.userID = c.userID
    WHERE DATE(a.clock_in) = '$today'
    ORDER BY a.clock_in DESC
");

// Fetch attendance summary
$summary = $conn->query("
    SELECT 
        COUNT(DISTINCT userID) as total_employees,
        SUM(CASE WHEN DATE(clock_in) = CURDATE() AND clock_out IS NULL THEN 1 ELSE 0 END) as on_duty,
        SUM(CASE WHEN DATE(clock_in) = CURDATE() AND status = 'Late' THEN 1 ELSE 0 END) as late_today,
        SUM(CASE WHEN DATE(clock_in) = CURDATE() AND status = 'Completed' THEN 1 ELSE 0 END) as completed_today
    FROM attendance
    WHERE DATE(clock_in) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management • Admin</title>
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
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }
        
        .employee-row {
            transition: background-color 0.2s ease;
        }
        
        .employee-row:hover {
            background-color: #f8f9fa;
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
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
                <li class="nav-item"><a href="attendance_management.php" class="nav-link active"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
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
                    <h4 class="fw-bold mb-0">Attendance Management</h4>
                    <p class="text-muted mb-0">Monitor employee attendance and working hours</p>
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

        <!-- Summary Stats -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="fas fa-users text-primary fa-2x"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Employees</div>
                            <div class="fw-bold fs-2"><?php echo $summary['total_employees'] ?? 0; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="bg-success bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="fas fa-check-circle text-success fa-2x"></i>
                        </div>
                        <div>
                            <div class="text-muted small">On Duty Today</div>
                            <div class="fw-bold fs-2 text-success"><?php echo $summary['on_duty'] ?? 0; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="bg-warning bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="fas fa-clock text-warning fa-2x"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Late Today</div>
                            <div class="fw-bold fs-2 text-warning"><?php echo $summary['late_today'] ?? 0; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="bg-info bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="fas fa-calendar-check text-info fa-2x"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Completed Today</div>
                            <div class="fw-bold fs-2 text-info"><?php echo $summary['completed_today'] ?? 0; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Attendance -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Today's Attendance (<?php echo date('F j, Y'); ?>)</h6>
                <span class="badge bg-primary"><?php echo $todayAttendance->num_rows; ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Employee</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Hours</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($todayAttendance->num_rows > 0): ?>
                                <?php while ($record = $todayAttendance->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <?php if (!empty($record['profile_picture']) && file_exists('../' . $record['profile_picture'])): ?>
                                                    <img src="../<?php echo $record['profile_picture']; ?>" alt="" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" class="me-3">
                                                <?php else: ?>
                                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                        <span class="fw-bold"><?php echo strtoupper(substr($record['Firstname'], 0, 1)); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($record['Firstname'] . ' ' . $record['Lastname']); ?></div>
                                                    <small class="text-muted">Shift: <?php echo date('g:i A', strtotime($record['shift_start_time'] ?? '08:00:00')); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo date('g:i A', strtotime($record['clock_in'])); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($record['clock_out']): ?>
                                                <span class="fw-semibold"><?php echo date('g:i A', strtotime($record['clock_out'])); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">Still Working</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($record['total_hours']): ?>
                                                <span class="fw-bold text-success"><?php echo number_format($record['total_hours'], 1); ?> hrs</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $statusClass = 'secondary';
                                            if ($record['status'] == 'On Duty') $statusClass = 'primary';
                                            elseif ($record['status'] == 'Completed') $statusClass = 'success';
                                            elseif ($record['status'] == 'Late') $statusClass = 'warning';
                                            ?>
                                            <span class="badge bg-<?php echo $statusClass; ?> px-3 py-2">
                                                <?php echo $record['status']; ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <button class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $record['attendanceID']; ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <?php if ($record['clock_out']): ?>
                                                <select class="form-select form-select-sm d-inline-block w-auto" onchange="updateStatus(<?php echo $record['attendanceID']; ?>, this.value)">
                                                    <option value="Completed" <?php echo $record['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                    <option value="Late" <?php echo $record['status'] == 'Late' ? 'selected' : ''; ?>>Late</option>
                                                    <option value="Absent" <?php echo $record['status'] == 'Absent' ? 'selected' : ''; ?>>Absent</option>
                                                </select>
                                            <?php else: ?>
                                                <span class="text-muted small">Active</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="fas fa-clock fa-3x mb-3 opacity-50"></i>
                                        <p>No attendance records for today yet.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- All Employees Status -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="fw-bold mb-0">All Employees - Current Status</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Employee</th>
                                <th>Shift Schedule</th>
                                <th>Rate</th>
                                <th>Today's Status</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($emp = $employees->fetch_assoc()): ?>
                                <?php
                                // Check today's attendance for this employee
                                $empToday = $conn->query("SELECT * FROM attendance WHERE userID = {$emp['userID']} AND DATE(clock_in) = '$today' ORDER BY clock_in DESC LIMIT 1")->fetch_assoc();
                                ?>
                                <tr class="employee-row">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($emp['profile_picture']) && file_exists('../' . $emp['profile_picture'])): ?>
                                                <img src="../<?php echo $emp['profile_picture']; ?>" alt="" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" class="me-3">
                                            <?php else: ?>
                                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                    <span class="fw-bold"><?php echo strtoupper(substr($emp['Firstname'], 0, 1)); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($emp['Firstname'] . ' ' . $emp['Lastname']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($emp['Email']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?php echo date('g:i A', strtotime($emp['shift_start_time'] ?? '08:00:00')); ?></span>
                                        <span class="text-muted">- 5:00 PM</span>
                                    </td>
                                    <td>
                                        <div>₱<?php echo number_format($emp['hourly_rate'] ?? 100, 0); ?>/hr</div>
                                        <small class="text-muted">₱<?php echo number_format($emp['daily_rate'] ?? 800, 0); ?>/day</small>
                                    </td>
                                    <td>
                                        <?php if ($empToday): ?>
                                            <?php 
                                            $statusClass = 'secondary';
                                            if ($empToday['status'] == 'On Duty') $statusClass = 'primary';
                                            elseif ($empToday['status'] == 'Completed') $statusClass = 'success';
                                            elseif ($empToday['status'] == 'Late') $statusClass = 'warning';
                                            ?>
                                            <span class="badge bg-<?php echo $statusClass; ?> px-3 py-2">
                                                <?php echo $empToday['status']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary px-3 py-2">Not Clocked In</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="attendance_management.php?view_employee=<?php echo $emp['userID']; ?>" class="btn btn-sm btn-outline-primary">View History</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Attendance Modals -->
    <?php 
    // Reset the result pointer for modals
    $todayAttendance->data_seek(0);
    while ($record = $todayAttendance->fetch_assoc()): 
    ?>
    <div class="modal fade" id="editModal<?php echo $record['attendanceID']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Attendance - <?php echo htmlspecialchars($record['Firstname'] . ' ' . $record['Lastname']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="attendanceID" value="<?php echo $record['attendanceID']; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Clock-In Time</label>
                            <input type="datetime-local" class="form-control" name="clock_in" 
                                   value="<?php echo date('Y-m-d\TH:i', strtotime($record['clock_in'])); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Clock-Out Time (optional)</label>
                            <input type="datetime-local" class="form-control" name="clock_out" 
                                   value="<?php echo $record['clock_out'] ? date('Y-m-d\TH:i', strtotime($record['clock_out'])) : ''; ?>">
                            <small class="text-muted">Leave empty if still working</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_attendance" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endwhile; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        }
        
        function updateStatus(attendanceID, status) {
            window.location = 'attendance_management.php?update_status=' + attendanceID + '&status=' + status;
        }
    </script>
</body>
</html>