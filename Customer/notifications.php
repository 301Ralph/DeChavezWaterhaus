<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    echo '<script>alert("Access denied."); window.location = "../login.php";</script>';
    exit();
}

$userID = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Mark all as read when visiting the page
$conn->query("UPDATE notifications SET is_read = 1 WHERE userID = $userID");

// Fetch notifications
$notifs = $conn->query("SELECT * FROM notifications WHERE userID = $userID ORDER BY created_at DESC LIMIT 50");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root { --primary: #0077B6; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        .sidebar { 
            position: fixed; top: 0; left: 0; height: 100vh; width: 260px; 
            background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; 
            transition: all 0.3s ease; 
        }
        .sidebar.collapsed { width: 80px; }
        .sidebar .logo { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eee; }
        .sidebar .logo img { width: 42px; height: 42px; border-radius: 50%; }
        .sidebar .nav-link { 
            color: #495057; padding: 14px 22px; display: flex; align-items: center; gap: 14px; 
            font-weight: 500; transition: all 0.3s ease; border-radius: 12px; margin: 4px 10px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { 
            background-color: #f0f7ff; color: var(--primary); 
        }
        .sidebar .nav-link i { width: 22px; font-size: 1.1rem; }
        
        .main-content { margin-left: 260px; padding: 30px; transition: margin-left 0.3s ease; }
        .sidebar.collapsed ~ .main-content { margin-left: 80px; }
        .notification-item { background: white; border-radius: 12px; padding: 18px 20px; margin-bottom: 12px; border-left: 4px solid #0077B6; transition: all 0.2s ease; }
        .notification-item:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.08); transform: translateX(4px); }
        .notification-item.unread { border-left-color: #dc3545; background: #fff5f5; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo p-4 d-flex align-items-center gap-3 border-bottom">
            <img src="../images/logo.jpg" alt="Logo" style="width: 45px; height: 45px; border-radius: 50%;">
            <span class="fw-bold fs-5">De Chavez Waterhaus</span>
        </div>
        
        <div class="px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home "></i>Dashboard</a></li>
                <li class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-box "></i> Products</a></li>
                <li class="nav-item">
                    <a href="orders.php" class="nav-link">
                        <i class="fas fa-shopping-cart"></i> <span>Place Order</span>
                    </a>
                </li>
                <li class="nav-item"><a href="order_history.php" class="nav-link"><i class="fas fa-history "></i> Order History</a></li>
                <li class="nav-item"><a href="order_tracking.php" class="nav-link"><i class="fas fa-map-marker-alt "></i> Track Orders</a></li>
                <li class="nav-item"><a href="notifications.php" class="nav-link active"><i class="fas fa-bell "></i> Notifications</a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user "></i> Profile</a></li>
                <li class="nav-item mt-4"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt "></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Notifications</h4>
                <p class="text-muted mb-0">Stay updated with your orders</p>
            </div>
            <a href="customer_dashboard.php" class="btn btn-outline-secondary px-4 rounded-pill">Back to Dashboard</a>
        </div>

        <?php if ($notifs->num_rows > 0): ?>
            <?php while ($notif = $notifs->fetch_assoc()) { ?>
                <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                    <div class="d-flex">
                        <div class="">
                            <i class="fas fa-bell fa-2x text-primary"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?php echo htmlspecialchars($notif['message']); ?></div>
                            <small class="text-muted"><?php echo date('F j, Y g:i A', strtotime($notif['created_at'])); ?></small>
                        </div>
                    </div>
                </div>
            <?php } ?>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-bell-slash fa-4x text-muted mb-4"></i>
                <h5 class="fw-bold">No Notifications Yet</h5>
                <p class="text-muted">You're all caught up!</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>