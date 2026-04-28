<?php
include '../includes/connection.php';
session_start();

// Security check
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    echo '<script>alert("Access denied. Customers only."); window.location = "../login.php";</script>';
    exit();
}

$userID = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Fetch user data for profile picture
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle new recurring order
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_recurring'])) {
    $productID = intval($_POST['productID']);
    $quantity = intval($_POST['quantity']);
    $frequency = $_POST['frequency'];
    $deliveryDay = $_POST['delivery_day'];
    $addressID = intval($_POST['addressID']);

    // Calculate next delivery date
    $today = new DateTime();
    $nextDelivery = clone $today;

    // Find the next occurrence of the selected day
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $targetDay = array_search($deliveryDay, $days);
    $currentDay = (int)$today->format('w');

    $daysUntil = ($targetDay - $currentDay + 7) % 7;
    if ($daysUntil == 0) $daysUntil = 7; // Next week if today

    $nextDelivery->modify("+$daysUntil days");

    $insertStmt = $conn->prepare("
        INSERT INTO recurring_orders (userID, productID, quantity, frequency, delivery_day, next_delivery_date, addressID) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $nextDeliveryDate = $nextDelivery->format('Y-m-d');
    $insertStmt->bind_param("iiisssi", $userID, $productID, $quantity, $frequency, $deliveryDay, $nextDeliveryDate, $addressID);
    
    if ($insertStmt->execute()) {
        echo '<script>alert("Recurring delivery set up successfully!"); window.location = "recurring_orders.php";</script>';
        exit();
    } else {
        echo '<script>alert("Failed to set up recurring delivery. Please try again.");</script>';
    }
    $insertStmt->close();
}

// Handle pause/resume/cancel
if (isset($_GET['action']) && isset($_GET['id'])) {
    $recurringID = intval($_GET['id']);
    $action = $_GET['action'];

    if ($action == 'pause') {
        $conn->query("UPDATE recurring_orders SET status = 'Paused' WHERE recurringID = $recurringID AND userID = $userID");
    } elseif ($action == 'resume') {
        $conn->query("UPDATE recurring_orders SET status = 'Active' WHERE recurringID = $recurringID AND userID = $userID");
    } elseif ($action == 'cancel') {
        $conn->query("UPDATE recurring_orders SET status = 'Cancelled' WHERE recurringID = $recurringID AND userID = $userID");
    }

    echo '<script>window.location = "recurring_orders.php";</script>';
    exit();
}

// Fetch recurring orders
$recurringOrders = $conn->query("
    SELECT r.*, p.ProductName, p.Price, a.full_address 
    FROM recurring_orders r
    JOIN product p ON r.productID = p.ProductID
    LEFT JOIN delivery_addresses a ON r.addressID = a.addressID
    WHERE r.userID = $userID
    ORDER BY r.created_at DESC
");

// Fetch products for form
$products = $conn->query("SELECT * FROM product WHERE Status = 'Active'");

// Fetch addresses
$addresses = $conn->query("SELECT * FROM delivery_addresses WHERE userID = $userID");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recurring Orders • De Chavez Waterhaus</title>
    <link rel="icon" href="../images/logo.jpg" type="image/jpeg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        
        .sidebar { 
            position: fixed; top: 0; left: 0; height: 100vh; width: 260px; 
            background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; 
            transition: all 0.3s ease; 
        }
        .sidebar.collapsed { width: 80px; }
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
        .sidebar.collapsed ~ .main-content { margin-left: 80px; }
        
        .recurring-card { background: white; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .status-active { background: #d4edda; color: #155724; }
        .status-paused { background: #fff3cd; color: #856404; }
        .status-cancelled { background: #f8d7da; color: #721c24; }

        .sidebar .nav-link {
            padding: 12px 18px;
            margin: 2px 8px;
            border-radius: 10px;
        }
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
        
        @media (max-width: 576px) {
            .main-content { padding: 15px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo p-4 d-flex align-items-center gap-3 border-bottom">
            <img src="../images/logo.jpg" alt="Logo" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover;">
            <span class="fw-bold fs-5">De Chavez Waterhaus</span>
        </div>
        
        <!-- Scrollable Menu -->
        <div class="px-3 mt-2" style="height: calc(100vh - 90px); overflow-y: auto; padding-bottom: 20px;">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-box me-3"></i> <span>Products</span></a></li>
                <li class="nav-item"><a href="orders.php" class="nav-link"><i class="fas fa-shopping-cart me-3"></i> <span>Place Order</span></a></li>
                <li class="nav-item"><a href="order_history.php" class="nav-link"><i class="fas fa-history me-3"></i> <span>Order History</span></a></li>
                <li class="nav-item"><a href="order_tracking.php" class="nav-link"><i class="fas fa-map-marker-alt me-3"></i> <span>Track Orders</span></a></li>
                <li class="nav-item"><a href="recurring_orders.php" class="nav-link active"><i class="fas fa-redo me-3"></i> <span>Recurring Orders</span></a></li>
                <li class="nav-item"><a href="support_tickets.php" class="nav-link"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
                <li class="nav-item"><a href="notifications.php" class="nav-link"><i class="fas fa-bell me-3"></i> <span>Notifications</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user me-3"></i> <span>Profile</span></a></li>
                
                <li class="nav-item mt-4"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt me-3"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- IMPROVED MOBILE NAVBAR -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <!-- Hamburger Button -->
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                
                <div>
                    <h4 class="fw-bold mb-0">Recurring Orders</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Set up automatic water deliveries</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#createRecurringModal">
                    <i class="fas fa-plus me-2"></i> Set Up
                </button>
                
                <div class="dropdown">
                    <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center overflow-hidden" style="width: 38px; height: 38px;">
                            <?php if (!empty($user['profile_picture']) && file_exists("../" . $user['profile_picture'])): ?>
                                <img src="../<?php echo $user['profile_picture']; ?>" style="width: 38px; height: 38px; object-fit: cover;">
                            <?php else: ?>
                                <span class="fw-bold fs-6"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-start d-none d-md-block">
                            <div class="fw-semibold"><?php echo htmlspecialchars($userName); ?></div>
                            <small class="text-muted">Customer</small>
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

        <!-- Active Recurring Orders -->
        <div class="recurring-card p-4 mb-4">
            <h5 class="fw-bold mb-4">Your Recurring Deliveries</h5>
            
            <?php if ($recurringOrders->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Frequency</th>
                                <th>Next Delivery</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($rec = $recurringOrders->fetch_assoc()) { ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo $rec['ProductName']; ?></div>
                                        <small class="text-muted">₱<?php echo number_format($rec['Price'], 2); ?> each</small>
                                    </td>
                                    <td><?php echo $rec['quantity']; ?> pcs</td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $rec['frequency']; ?></span><br>
                                        <small>Every <?php echo $rec['delivery_day']; ?></small>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($rec['next_delivery_date'])); ?></td>
                                    <td>
                                        <span class="badge status-<?php echo strtolower($rec['status']); ?>">
                                            <?php echo $rec['status']; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($rec['status'] == 'Active'): ?>
                                            <a href="recurring_orders.php?action=pause&id=<?php echo $rec['recurringID']; ?>" class="btn btn-sm btn-warning px-3 rounded-pill">Pause</a>
                                            <a href="recurring_orders.php?action=cancel&id=<?php echo $rec['recurringID']; ?>" class="btn btn-sm btn-danger px-3 rounded-pill" onclick="return confirm('Cancel this recurring delivery?')">Cancel</a>
                                        <?php elseif ($rec['status'] == 'Paused'): ?>
                                            <a href="recurring_orders.php?action=resume&id=<?php echo $rec['recurringID']; ?>" class="btn btn-sm btn-success px-3 rounded-pill">Resume</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-redo fa-4x text-muted mb-4"></i>
                    <h5 class="fw-bold">No Recurring Orders Yet</h5>
                    <p class="text-muted">Set up automatic deliveries for convenience.</p>
                    <button class="btn btn-primary px-5 rounded-pill" data-bs-toggle="modal" data-bs-target="#createRecurringModal">
                        Set Up Now
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Create Recurring Modal -->
    <div class="modal fade" id="createRecurringModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Set Up Recurring Delivery</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Product</label>
                            <select class="form-select" name="productID" required>
                                <option value="">Select Product</option>
                                <?php while ($prod = $products->fetch_assoc()) { ?>
                                    <option value="<?php echo $prod['ProductID']; ?>">
                                        <?php echo $prod['ProductName']; ?> - ₱<?php echo number_format($prod['Price'], 2); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Quantity</label>
                                <input type="number" class="form-control" name="quantity" min="6" max="100" value="6" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Frequency</label>
                                <select class="form-select" name="frequency" required>
                                    <option value="Weekly">Weekly</option>
                                    <option value="Bi-Weekly">Every 2 Weeks</option>
                                    <option value="Monthly">Monthly</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3 mt-3">
                            <label class="form-label fw-semibold">Preferred Delivery Day</label>
                            <select class="form-select" name="delivery_day" required>
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                                <option value="Sunday">Sunday</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Delivery Address</label>
                            <select class="form-select" name="addressID" required>
                                <?php while ($addr = $addresses->fetch_assoc()) { ?>
                                    <option value="<?php echo $addr['addressID']; ?>">
                                        <?php echo $addr['label']; ?> - <?php echo substr($addr['full_address'], 0, 50); ?>...
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_recurring" class="btn btn-primary px-5">Set Up Recurring Delivery</button>
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
            mobileToggle.addEventListener('click', () => {
                sidebar.classList.toggle('show');
            });
            
            document.addEventListener('click', function(e) {
                if (window.innerWidth < 992 && !sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>