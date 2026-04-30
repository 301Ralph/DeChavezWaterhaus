<?php
include '../includes/connection.php';
session_start();

// Security check - Employee only
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

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_photo'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_picture']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $newname = 'employee_' . $userID . '_' . time() . '.' . $ext;
            $upload_path = '../uploads/profile_pictures/' . $newname;
            
            if (!is_dir('../uploads/profile_pictures/')) {
                mkdir('../uploads/profile_pictures/', 0777, true);
            }
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                // Delete old photo if exists
                if (!empty($employee['profile_picture']) && file_exists('../' . $employee['profile_picture'])) {
                    unlink('../' . $employee['profile_picture']);
                }
                
                $update = $conn->prepare("UPDATE customers SET profile_picture = ? WHERE userID = ?");
                $db_path = 'uploads/profile_pictures/' . $newname;
                $update->bind_param("si", $db_path, $userID);
                $update->execute();
                $update->close();
                
                echo '<script>alert("Profile picture updated!"); window.location = "employee_dashboard.php";</script>';
                exit();
            }
        } else {
            echo '<script>alert("Invalid file type. Only JPG, PNG, GIF allowed.");</script>';
        }
    }
}

// Fetch assigned orders (if any)
$assignedOrders = 0;
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM orders WHERE assigned_employee = $userID AND status IN ('Pending', 'Processing', 'Out for Delivery')");
    if ($result) {
        $assignedOrders = $result->fetch_assoc()['count'] ?? 0;
    }
} catch (Exception $e) {
    // Column might not exist yet
}

// Fetch completed deliveries
$completedDeliveries = 0;
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM orders WHERE assigned_employee = $userID AND status = 'Delivered'");
    if ($result) {
        $completedDeliveries = $result->fetch_assoc()['count'] ?? 0;
    }
} catch (Exception $e) {
    // Column might not exist yet
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard • De Chavez Waterhaus</title>
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
        
        .stat-card { 
            background: white; border-radius: 20px; padding: 24px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #f0f0f0;
            transition: transform 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { 
            width: 60px; height: 60px; border-radius: 16px; 
            display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
        }
        
        .nav-menu { flex: 1; overflow-y: auto; padding-bottom: 20px; }
        .logout-section { padding: 15px 10px; border-top: 1px solid #eee; background: white; }
        
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
        
        .profile-upload {
            position: relative;
            display: inline-block;
        }
        .profile-upload input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
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
                <li class="nav-item"><a href="employee_dashboard.php" class="nav-link active"><i class="fas fa-tachometer-alt me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="attendance.php" class="nav-link"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
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
        <!-- Top Navbar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="fw-bold mb-0 d-none d-sm-block">Good morning, <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?>!</h4>
                    <h4 class="fw-bold mb-0 d-sm-none">Hi, <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?>!</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Welcome back to your employee portal</p>
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
                        <li class="dropdown-header fw-bold d-flex justify-content-between align-items-center">
                            <span>Notifications</span>
                            <?php if ($unreadCount > 0): ?>
                                <a href="notifications.php" class="text-primary small text-decoration-none">Mark all read</a>
                            <?php endif; ?>
                        </li>
                        <?php 
                        $notifs = $conn->query("SELECT * FROM notifications WHERE userID = $userID ORDER BY created_at DESC LIMIT 5");
                        if ($notifs->num_rows > 0):
                            while ($n = $notifs->fetch_assoc()):
                        ?>
                            <li>
                                <a class="dropdown-item small py-2" href="notifications.php">
                                    <div class="d-flex">
                                        <div class="me-2">
                                            <i class="fas fa-bell text-primary"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <?php echo htmlspecialchars($n['message']); ?>
                                            <div class="text-muted" style="font-size: 10px;"><?php echo date('M d, g:i A', strtotime($n['created_at'])); ?></div>
                                        </div>
                                    </div>
                                </a>
                            </li>
                        <?php endwhile; else: ?>
                            <li><span class="dropdown-item text-muted small py-3">You're all caught up!</span></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-center small text-primary py-2" href="notifications.php"><strong>View All Notifications</strong></a></li>
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

        <!-- Welcome Card -->
        <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #0077B6 0%, #023E8A 100%); color: white;">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="fw-bold mb-2">Welcome to De Chavez Waterhaus!</h5>
                        <p class="mb-0 opacity-90">You're doing great work delivering clean water to our customers. Keep it up!</p>
                    </div>
                    <div class="d-none d-md-block">
                        <i class="fas fa-tint fa-4x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary text-white me-3">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Assigned Orders</div>
                            <div class="fw-bold fs-3"><?php echo $assignedOrders; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success text-white me-3">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Completed Deliveries</div>
                            <div class="fw-bold fs-3"><?php echo $completedDeliveries; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-info text-white me-3">
                            <i class="fas fa-star"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Performance</div>
                            <div class="fw-bold fs-3">Excellent</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="fw-bold mb-0">Quick Actions</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <a href="attendance.php" class="btn btn-outline-primary w-100 py-3 rounded-3">
                            <i class="fas fa-clock fa-2x mb-2 d-block"></i>
                            <span>Clock In/Out</span>
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="my_deliveries.php" class="btn btn-outline-primary w-100 py-3 rounded-3">
                            <i class="fas fa-truck fa-2x mb-2 d-block"></i>
                            <span>View My Deliveries</span>
                        </a>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-primary w-100 py-3 rounded-3" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal">
                            <i class="fas fa-camera fa-2x mb-2 d-block"></i>
                            <span>Upload Photo</span>
                        </button>
                    </div>
                    <div class="col-md-3">
                        <a href="../logout.php" class="btn btn-outline-danger w-100 py-3 rounded-3">
                            <i class="fas fa-sign-out-alt fa-2x mb-2 d-block"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Photo Modal -->
    <div class="modal fade" id="uploadPhotoModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Upload Profile Picture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4">
                        <div class="text-center mb-3">
                            <?php if (!empty($employee['profile_picture']) && file_exists('../' . $employee['profile_picture'])): ?>
                                <img src="../<?php echo $employee['profile_picture']; ?>" alt="Current" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid #f0f0f0;">
                            <?php else: ?>
                                <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 120px; height: 120px;">
                                    <i class="fas fa-user fa-4x text-muted"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select New Photo</label>
                            <input type="file" class="form-control" name="profile_picture" accept="image/*" required>
                            <small class="text-muted">Allowed: JPG, PNG, GIF (Max 2MB)</small>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="upload_photo" class="btn btn-primary px-5">Upload Photo</button>
                    </div>
                </form>
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
        
        // Auto collapse on mobile
        if (window.innerWidth < 992 && sidebar) {
            sidebar.classList.add('collapsed');
        }
    </script>
</body>
</html>