<?php
include '../includes/connection.php';
session_start();

// Security check
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    echo '<script>alert("Access denied. Customers only."); window.location = "../login.php";</script>';
    exit();
}

$userID   = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Fetch user data
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

    $today = new DateTime();
    $nextDelivery = clone $today;

    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $targetDay = array_search($deliveryDay, $days);
    $currentDay = (int)$today->format('w');

    $daysUntil = ($targetDay - $currentDay + 7) % 7;
    if ($daysUntil == 0) $daysUntil = 7;

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

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName = explode(' ', $userName)[0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recurring Orders • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;
            --abyss: #030f1e;
            --ocean: #041e35;
            --navy:  #0a2d4a;
            --teal:  #0077b6;
            --aqua:  #00b4d8;
            --cyan:  #48cae4;
            --glow:  #90e0ef;
            --foam:  #caf0f8;
            --white: #f0f9ff;
            --gold:  #f4c842;
            --glass: rgba(0,180,216,0.08);
            --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--deep);
            color: var(--white);
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            width: var(--sidebar-w);
            background: var(--abyss);
            border-right: 1px solid var(--glass-border);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            padding: 24px 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .sidebar-logo img {
            width: 40px; height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(0,180,216,0.35);
            box-shadow: 0 0 14px rgba(0,180,216,0.2);
        }

        .sidebar-logo span {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.05rem;
            font-weight: 500;
            color: var(--white);
            line-height: 1.2;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 16px 12px 20px;
            scrollbar-width: thin;
            scrollbar-color: rgba(72,202,228,0.15) transparent;
        }

        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }

        .nav-section-label {
            font-size: 0.62rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.25);
            padding: 16px 12px 6px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 10px;
            color: rgba(202,240,248,0.5) !important;
            text-decoration: none;
            font-size: 0.87rem;
            font-weight: 500;
            transition: all 0.25s ease;
            margin-bottom: 2px;
            position: relative;
        }

        .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 0.9rem;
            color: rgba(0,180,216,0.4);
            transition: color 0.25s;
        }

        .nav-link:hover {
            background: var(--glass);
            color: var(--foam) !important;
        }

        .nav-link:hover i { color: var(--aqua); }

        .nav-link.active {
            background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12));
            border: 1px solid rgba(0,180,216,0.2);
            color: var(--aqua) !important;
        }

        .nav-link.active i { color: var(--aqua); }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0; top: 20%; bottom: 20%;
            width: 3px;
            background: var(--aqua);
            border-radius: 0 3px 3px 0;
        }

        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }

        /* ── MAIN ── */
        .main-content {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            padding: 28px 32px;
            transition: margin-left 0.3s ease;
        }

        /* ── TOP BAR ── */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .topbar-greeting h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.7rem;
            font-weight: 400;
            color: var(--white);
            line-height: 1.1;
        }

        .topbar-greeting p {
            font-size: 0.82rem;
            color: rgba(202,240,248,0.4);
            margin-top: 2px;
        }

        .topbar-actions { display: flex; align-items: center; gap: 12px; }

        .topbar-btn {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: rgba(202,240,248,0.6);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s;
            position: relative;
        }

        .topbar-btn:hover {
            background: rgba(0,180,216,0.15);
            border-color: var(--aqua);
            color: var(--aqua);
        }

        .topbar-notif-badge {
            position: absolute;
            top: -3px; right: -3px;
            background: var(--gold);
            color: var(--deep);
            font-size: 0.58rem;
            font-weight: 700;
            min-width: 16px;
            height: 16px;
            border-radius: 50px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }

        .avatar-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 50px;
            padding: 6px 14px 6px 6px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .avatar-btn:hover {
            border-color: rgba(0,180,216,0.35);
            background: rgba(0,180,216,0.1);
        }

        .avatar-circle {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep);
            font-weight: 700;
            font-size: 0.85rem;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

        .avatar-name {
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--white);
        }

        .avatar-role {
            font-size: 0.7rem;
            color: rgba(202,240,248,0.4);
        }

        /* ── RECURRING CARDS ── */
        .recurring-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 28px;
        }

        .status-active { background: rgba(74,222,128,0.1); color: #4ade80; border: 1px solid rgba(74,222,128,0.25); }
        .status-paused { background: rgba(244,200,66,0.1); color: var(--gold); border: 1px solid rgba(244,200,66,0.25); }
        .status-cancelled { background: rgba(248,113,113,0.1); color: #fca5a5; border: 1px solid rgba(248,113,113,0.25); }

        /* Modal */
        .modal-content {
            background: var(--ocean);
            border: 1px solid var(--glass-border);
            border-radius: 18px;
        }

        .modal-header {
            border-bottom: 1px solid var(--glass-border);
            padding: 20px 24px;
        }

        .modal-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            font-weight: 500;
        }

        .modal-footer {
            border-top: 1px solid var(--glass-border);
            padding: 20px 24px;
        }

        .form-control, .form-select {
            background: rgba(4,30,53,0.6);
            border: 1px solid var(--glass-border);
            color: var(--white);
            border-radius: 10px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--aqua);
            box-shadow: 0 0 0 0.2rem rgba(0,180,216,0.15);
            background: rgba(4,30,53,0.8);
        }

        .form-label {
            color: rgba(202,240,248,0.7);
            font-weight: 500;
        }

        /* Mobile */
        @media (max-width: 991px) {
            .main-content { margin-left: 0; padding: 20px 18px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .recurring-card { padding: 18px; }
        }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="Logo">
        <span>De Chavez<br>Waterhaus</span>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="customer_dashboard.php" class="nav-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="products.php" class="nav-link">
            <i class="fas fa-droplet"></i> Products
        </a>
        <a href="order_history.php" class="nav-link">
            <i class="fas fa-history"></i> Order History
        </a>
        <a href="order_tracking.php" class="nav-link">
            <i class="fas fa-map-marker-alt"></i> Track Orders
        </a>
        <a href="recurring_orders.php" class="nav-link active">
            <i class="fas fa-redo"></i> Recurring Orders
        </a>

        <div class="nav-section-label">Account</div>
        <a href="support_tickets.php" class="nav-link">
            <i class="fas fa-headset"></i> Support
        </a>
        <a href="notifications.php" class="nav-link">
            <i class="fas fa-bell"></i> Notifications
        </a>
        <a href="profile.php" class="nav-link">
            <i class="fas fa-user"></i> Profile
        </a>

        <div class="nav-section-label" style="margin-top: 16px;"></div>
        <a href="../logout.php" class="nav-link danger">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN CONTENT ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle d-lg-none" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-greeting">
                <h4>Recurring Orders</h4>
                <p>Set up automatic water deliveries for convenience</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if ($notifCount > 0): ?>
                    <span class="topbar-notif-badge"><?php echo $notifCount > 9 ? '9+' : $notifCount; ?></span>
                <?php endif; ?>
            </a>

            <button class="btn btn-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#createRecurringModal">
                <i class="fas fa-plus me-2"></i> Set Up
            </button>

            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if (!empty($user['profile_picture']) && file_exists('../' . $user['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($userName); ?></div>
                        <div class="avatar-role">Customer</div>
                    </div>
                    <i class="fas fa-chevron-down fa-xs ms-1" style="color:rgba(202,240,248,0.3);"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Recurring Orders Content -->
    <div class="recurring-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="mb-1" style="font-family: 'Cormorant Garamond', serif; font-size: 1.5rem;">Your Recurring Deliveries</h5>
                <p class="text-muted mb-0 small">Automatic water deliveries set up for your convenience</p>
            </div>
        </div>
        
        <?php if ($recurringOrders->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table align-middle" style="color: var(--foam);">
                    <thead style="background: rgba(4,30,53,0.6);">
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
                                    <small style="color: rgba(202,240,248,0.4);">₱<?php echo number_format($rec['Price'], 2); ?> each</small>
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
                                        <a href="recurring_orders.php?action=pause&id=<?php echo $rec['recurringID']; ?>" class="btn btn-warning btn-sm px-3 rounded-pill">Pause</a>
                                        <a href="recurring_orders.php?action=cancel&id=<?php echo $rec['recurringID']; ?>" class="btn btn-danger btn-sm px-3 rounded-pill ms-1" onclick="return confirm('Cancel this recurring delivery?')">Cancel</a>
                                    <?php elseif ($rec['status'] == 'Paused'): ?>
                                        <a href="recurring_orders.php?action=resume&id=<?php echo $rec['recurringID']; ?>" class="btn btn-success btn-sm px-3 rounded-pill">Resume</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-redo fa-4x mb-4" style="color: rgba(0,180,216,0.15);"></i>
                <h5 class="fw-semibold mb-2">No Recurring Orders Yet</h5>
                <p class="text-muted mb-4">Set up automatic deliveries for convenience.</p>
                <button class="btn btn-primary px-5 rounded-pill" data-bs-toggle="modal" data-bs-target="#createRecurringModal">
                    Set Up Now
                </button>
            </div>
        <?php endif; ?>
    </div>

</main>

<!-- Create Recurring Modal -->
<div class="modal fade" id="createRecurringModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-redo me-2"></i> Set Up Recurring Delivery
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_recurring" class="btn btn-primary px-5">Set Up Recurring Delivery</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mobile Sidebar
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('mobileToggle');

    function openSidebar() { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }

    if (toggle) toggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 992) closeSidebar();
        });
    });
</script>
</body>
</html>