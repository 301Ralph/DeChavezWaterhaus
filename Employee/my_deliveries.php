<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'employee') {
    echo '<script>alert("Access denied. Employees only."); window.location = "../login.php";</script>';
    exit();
}

$userID = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Fetch employee data for profile picture
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $orderID = intval($_POST['orderID']);
    $newStatus = $_POST['status'];
    
    $update = $conn->prepare("UPDATE orders SET status = ? WHERE orderID = ? AND assigned_employee = ?");
    $update->bind_param("sii", $newStatus, $orderID, $userID);
    $update->execute();
    $update->close();
    
    echo '<script>alert("Order status updated!"); window.location = "my_deliveries.php";</script>';
    exit();
}

// Fetch assigned deliveries
$deliveries = [];
try {
    $result = $conn->query("
        SELECT o.*, 
               GROUP_CONCAT(CONCAT(p.ProductName, ' x', oi.quantity) SEPARATOR ', ') AS products,
               c.Firstname AS customer_firstname, c.Lastname AS customer_lastname
        FROM orders o
        LEFT JOIN order_items oi ON o.orderID = oi.orderID
        LEFT JOIN product p ON oi.productID = p.ProductID
        LEFT JOIN customers c ON o.userID = c.userID
        WHERE o.assigned_employee = $userID
        GROUP BY o.orderID
        ORDER BY o.order_date DESC
    ");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $deliveries[] = $row;
        }
    }
} catch (Exception $e) {
    // Table might not have assigned_employee column yet
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Deliveries • Employee</title>
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
        
        .delivery-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .delivery-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
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
                <li class="nav-item"><a href="my_deliveries.php" class="nav-link active"><i class="fas fa-truck me-3"></i> <span>My Deliveries</span></a></li>
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
                    <h4 class="fw-bold mb-0">My Deliveries</h4>
                    <p class="text-muted mb-0">Track and update your assigned deliveries</p>
                </div>
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

        <?php if (count($deliveries) > 0): ?>
            <div class="row g-4">
                <?php foreach ($deliveries as $delivery): ?>
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm delivery-card">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <span class="badge bg-<?php 
                                            echo $delivery['status'] == 'Delivered' ? 'success' : 
                                                ($delivery['status'] == 'Out for Delivery' ? 'primary' : 
                                                ($delivery['status'] == 'Processing' ? 'warning' : 'secondary')); 
                                        ?> px-3 py-2">
                                            <?php echo $delivery['status']; ?>
                                        </span>
                                    </div>
                                    <small class="text-muted">#<?php echo $delivery['orderID']; ?></small>
                                </div>
                                
                                <h6 class="fw-bold mb-2"><?php echo htmlspecialchars($delivery['products'] ?? 'Water Delivery'); ?></h6>
                                
                                <div class="mb-3">
                                    <div class="d-flex align-items-center text-muted small mb-1">
                                        <i class="fas fa-user me-2"></i>
                                        <span><?php echo htmlspecialchars(($delivery['customer_firstname'] ?? '') . ' ' . ($delivery['customer_lastname'] ?? '')); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center text-muted small mb-1">
                                        <i class="fas fa-calendar me-2"></i>
                                        <span><?php echo date('M j, Y g:i A', strtotime($delivery['order_date'])); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center text-muted small">
                                        <i class="fas fa-money-bill me-2"></i>
                                        <span>₱<?php echo number_format($delivery['total_amount'], 2); ?></span>
                                    </div>
                                </div>
                                
                                <?php if ($delivery['status'] != 'Delivered'): ?>
                                    <form method="POST" class="d-flex gap-2">
                                        <input type="hidden" name="orderID" value="<?php echo $delivery['orderID']; ?>">
                                        <select name="status" class="form-select form-select-sm" required>
                                            <option value="">Update Status...</option>
                                            <option value="Processing" <?php echo $delivery['status'] == 'Processing' ? 'selected' : ''; ?>>Processing</option>
                                            <option value="Out for Delivery" <?php echo $delivery['status'] == 'Out for Delivery' ? 'selected' : ''; ?>>Out for Delivery</option>
                                            <option value="Delivered" <?php echo $delivery['status'] == 'Delivered' ? 'selected' : ''; ?>>Delivered</option>
                                        </select>
                                        <button type="submit" name="update_status" class="btn btn-primary btn-sm px-4">Update</button>
                                    </form>
                                <?php else: ?>
                                    <div class="text-success small">
                                        <i class="fas fa-check-circle me-1"></i> Completed on <?php echo date('M j, Y', strtotime($delivery['updated_at'] ?? $delivery['order_date'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-truck fa-4x text-muted mb-4"></i>
                    <h5 class="fw-bold">No Deliveries Assigned Yet</h5>
                    <p class="text-muted">You don't have any assigned deliveries at the moment.<br>Check back later or contact your supervisor.</p>
                    <a href="employee_dashboard.php" class="btn btn-primary px-5 mt-3">Back to Dashboard</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
        }
    </script>
</body>
</html>