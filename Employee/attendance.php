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

// Check if already clocked in today
$today = date('Y-m-d');
$clockCheck = $conn->prepare("SELECT * FROM attendance WHERE userID = ? AND DATE(clock_in) = ? AND clock_out IS NULL");
$clockCheck->bind_param("is", $userID, $today);
$clockCheck->execute();
$currentShift = $clockCheck->get_result()->fetch_assoc();
$clockCheck->close();

$isClockedIn = $currentShift !== null;

// Handle Clock In
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clock_in'])) {
    // Check if Sunday (rest day)
    if (date('w') == 0) {
        echo '<script>alert("Sunday is your rest day. You cannot clock in today."); window.location = "attendance.php";</script>';
        exit();
    }
    
    // Check if already clocked in AND clocked out today
    $today = date('Y-m-d');
    $checkFullDay = $conn->prepare("SELECT attendanceID FROM attendance WHERE userID = ? AND DATE(clock_in) = ? AND clock_out IS NOT NULL");
    $checkFullDay->bind_param("is", $userID, $today);
    $checkFullDay->execute();
    $fullDayResult = $checkFullDay->get_result();
    if ($fullDayResult->num_rows > 0) {
        echo '<script>alert("You have already completed your shift today. You cannot clock in again."); window.location = "attendance.php";</script>';
        $checkFullDay->close();
        exit();
    }
    $checkFullDay->close();
    
    // Check if already clocked in (but not out)
    if ($isClockedIn) {
        echo '<script>alert("You are already clocked in!"); window.location = "attendance.php";</script>';
        exit();
    }
    
    // Check if clock-in is available (opens at 5:00 AM)
    $currentHour = (int)date('H');
    if ($currentHour < 5) {
        echo '<script>alert("Clock-in opens at 5:00 AM. Please try again later."); window.location = "attendance.php";</script>';
        exit();
    }
    
    $clockInTime = date('Y-m-d H:i:s');
    $currentTime = date('H:i:s');
    
    // Determine status
    $shiftStart = '07:00:00';
    $lateDeadline = '10:00:00';
    
    if ($currentTime > $lateDeadline) {
        $status = 'Absent';
    } else {
        $status = 'On Duty';
    }
    
    $stmt = $conn->prepare("INSERT INTO attendance (userID, clock_in, status) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userID, $clockInTime, $status);
    
    if ($stmt->execute()) {
        $notifMsg = "You have successfully clocked in at " . date('g:i A');
        $conn->query("INSERT INTO notifications (userID, message) VALUES ($userID, '$notifMsg')");
        
        echo '<script>alert("Clocked in successfully! Status: ' . $status . '"); window.location = "attendance.php";</script>';
    } else {
        echo '<script>alert("Error clocking in."); window.location = "attendance.php";</script>';
    }
    $stmt->close();
    exit();
}

// Handle Clock Out
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clock_out'])) {
    if (!$isClockedIn) {
        echo '<script>alert("You are not clocked in!"); window.location = "attendance.php";</script>';
        exit();
    }
    
    $clockOutTime = date('Y-m-d H:i:s');
    $attendanceID = $currentShift['attendanceID'];
    
    // Calculate total hours
    $clockIn = new DateTime($currentShift['clock_in']);
    $clockOut = new DateTime($clockOutTime);
    $interval = $clockIn->diff($clockOut);
    $totalHours = $interval->h + ($interval->i / 60);
    
    // Cap at 10 hours (7AM-5PM shift)
    if ($totalHours > 10) $totalHours = 10;
    
    // Deduct break time: 1 hour 30 minutes (90 minutes = 1.5 hours)
    $breakHours = 1.5;
    $paidHours = $totalHours - $breakHours;
    
    // Ensure paid hours is not negative
    if ($paidHours < 0) $paidHours = 0;
    $paidHours = round($paidHours, 2);
    
    $stmt = $conn->prepare("UPDATE attendance SET clock_out = ?, total_hours = ?, status = 'Completed' WHERE attendanceID = ?");
    $stmt->bind_param("sdi", $clockOutTime, $paidHours, $attendanceID);
    
    if ($stmt->execute()) {
        $notifMsg = "Clocked out successfully! Paid hours: " . $paidHours . " (Break deducted: 1.5 hrs)";
        $conn->query("INSERT INTO notifications (userID, message) VALUES ($userID, '$notifMsg')");
        
        echo '<script>alert("Clocked out successfully! Paid Hours: ' . $paidHours . ' (Break: 1.5 hrs deducted)"); window.location = "attendance.php";</script>';
    } else {
        echo '<script>alert("Error clocking out."); window.location = "attendance.php";</script>';
    }
    $stmt->close();
    exit();
}

// Fetch attendance history (last 30 days)
$history = [];
$historyStmt = $conn->prepare("SELECT * FROM attendance WHERE userID = ? AND clock_in >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY clock_in DESC");
$historyStmt->bind_param("i", $userID);
$historyStmt->execute();
$result = $historyStmt->get_result();
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}
$historyStmt->close();

// Calculate total hours this month
$totalHoursMonth = 0;
$monthStmt = $conn->prepare("SELECT SUM(total_hours) as total FROM attendance WHERE userID = ? AND MONTH(clock_in) = MONTH(NOW()) AND YEAR(clock_in) = YEAR(NOW())");
$monthStmt->bind_param("i", $userID);
$monthStmt->execute();
$monthResult = $monthStmt->get_result()->fetch_assoc();
$totalHoursMonth = $monthResult['total'] ?? 0;
$monthStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance • Employee</title>
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
        
        .clock-card {
            background: linear-gradient(135deg, #0077B6 0%, #023E8A 100%);
            color: white;
            border-radius: 20px;
            padding: 40px;
            text-align: center;
        }
        
        .time-display {
            font-size: 3rem;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }
        
        .attendance-table {
            font-size: 0.9rem;
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
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
                <li class="nav-item"><a href="attendance.php" class="nav-link active"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
                <li class="nav-item"><a href="payslip.php" class="nav-link"><i class="fas fa-file-invoice-dollar me-3"></i> <span>My Payslip</span></a></li>
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="fw-bold mb-0">Attendance</h4>
                    <p class="text-muted mb-0">Clock in/out and track your working hours</p>
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

        <!-- Clock In/Out Card -->
        <div class="row g-4 mb-4">
            <div class="col-lg-5">
                <div class="clock-card">
                    <div class="mb-4">
                        <i class="fas fa-clock fa-4x mb-3 opacity-75"></i>
                        <h5 class="fw-bold">Current Time</h5>
                        <div class="time-display" id="currentTime"></div>
                        <p class="mb-0 opacity-75"><?php echo date('l, F j, Y'); ?></p>
                    </div>
                    
                    <?php if ($isClockedIn): ?>
                        <div class="alert alert-light bg-white bg-opacity-25 border-0 mb-4">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Clocked In:</strong> <?php echo date('g:i A', strtotime($currentShift['clock_in'])); ?>
                        </div>
                        <form method="POST">
                            <button type="submit" name="clock_out" class="btn btn-danger btn-lg px-5 rounded-pill">
                                <i class="fas fa-sign-out-alt me-2"></i> CLOCK OUT
                            </button>
                        </form>
                    <?php else: ?>
                        <?php 
                        // Check if already completed duty today
                        $today = date('Y-m-d');
                        $completedCheck = $conn->prepare("SELECT attendanceID FROM attendance WHERE userID = ? AND DATE(clock_in) = ? AND clock_out IS NOT NULL");
                        $completedCheck->bind_param("is", $userID, $today);
                        $completedCheck->execute();
                        $completedResult = $completedCheck->get_result();
                        $hasCompletedToday = $completedResult->num_rows > 0;
                        $completedCheck->close();
                        ?>
                        
                        <?php if ($hasCompletedToday): ?>
                            <button type="button" class="btn btn-secondary btn-lg px-5 rounded-pill" disabled>
                                <i class="fas fa-check-circle me-2"></i> DUTY COMPLETED
                            </button>
                            <p class="mt-3 mb-0 small text-success">
                                <i class="fas fa-info-circle me-1"></i>
                                Great work! Your shift for today is complete. See you tomorrow!
                            </p>
                        <?php else: ?>
                            <form method="POST">
                                <button type="submit" name="clock_in" class="btn btn-light btn-lg px-5 rounded-pill text-primary fw-bold">
                                    <i class="fas fa-sign-in-alt me-2"></i> CLOCK IN
                                </button>
                            </form>
                            <p class="mt-3 mb-0 small opacity-75">
                                <i class="fas fa-info-circle me-1"></i>
                                Clock-in opens at 5:00 AM (2 hours before shift)
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-4">This Month's Summary</h6>
                        
                        <div class="row g-4">
                            <div class="col-6">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary bg-opacity-10 rounded-circle p-3 me-3">
                                        <i class="fas fa-clock text-primary fa-2x"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Total Hours</div>
                                        <div class="fw-bold fs-3"><?php echo number_format($totalHoursMonth, 1); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-6">
                                <div class="d-flex align-items-center">
                                    <div class="bg-success bg-opacity-10 rounded-circle p-3 me-3">
                                        <i class="fas fa-calendar-check text-success fa-2x"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Days Worked</div>
                                        <div class="fw-bold fs-3"><?php echo count($history); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-6">
                                <div class="d-flex align-items-center">
                                    <div class="bg-warning bg-opacity-10 rounded-circle p-3 me-3">
                                        <i class="fas fa-coins text-warning fa-2x"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Est. Earnings</div>
                                        <div class="fw-bold fs-3">₱<?php echo number_format($totalHoursMonth * ($employee['hourly_rate'] ?? 100), 0); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-6">
                                <div class="d-flex align-items-center">
                                    <div class="bg-info bg-opacity-10 rounded-circle p-3 me-3">
                                        <i class="fas fa-chart-line text-info fa-2x"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Avg/Day</div>
                                        <div class="fw-bold fs-3"><?php echo count($history) > 0 ? number_format($totalHoursMonth / count($history), 1) : '0'; ?> hrs</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance History -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="fw-bold mb-0">Attendance History (Last 30 Days)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 attendance-table">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Date</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Total Hours</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($history) > 0): ?>
                                <?php foreach ($history as $record): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-semibold"><?php echo date('M j, Y', strtotime($record['clock_in'])); ?></div>
                                            <small class="text-muted"><?php echo date('l', strtotime($record['clock_in'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo date('g:i A', strtotime($record['clock_in'])); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($record['clock_out']): ?>
                                                <span class="fw-semibold"><?php echo date('g:i A', strtotime($record['clock_out'])); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">Still On Duty</span>
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
                                            <span class="status-badge bg-<?php echo $statusClass; ?> text-white">
                                                <?php echo $record['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="fas fa-clock fa-3x mb-3 opacity-50"></i>
                                        <p>No attendance records yet.<br>Clock in to start tracking your hours!</p>
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
        
        // Live Clock
        function updateTime() {
            const now = new Date();
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            
            hours = hours % 12;
            hours = hours ? hours : 12;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            
            document.getElementById('currentTime').innerHTML = 
                hours + ':' + minutes + ':' + seconds + ' <span style="font-size:1.5rem">' + ampm + '</span>';
        }
        
        setInterval(updateTime, 1000);
        updateTime();
    </script>
</body>
</html>