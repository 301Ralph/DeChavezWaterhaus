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

// ==================== CHANGE THIS IF YOU GET COLUMN ERROR ====================
$customerColumn = 'userID';   // ← Change this if needed

// Fetch orders
$order = isset($_GET['order']) && $_GET['order'] == 'asc' ? 'ASC' : 'DESC';
$ordersQuery = "
    SELECT o.orderID, o.order_date, o.status, o.payment_method, o.total_amount,
           GROUP_CONCAT(CONCAT(p.ProductName, ' x', oi.quantity) SEPARATOR ', ') AS products
    FROM orders o
    JOIN order_items oi ON o.orderID = oi.orderID
    JOIN product p ON oi.productID = p.ProductID
    WHERE o.$customerColumn = $userID
    GROUP BY o.orderID
    ORDER BY o.order_date $order";

$ordersResult = $conn->query($ordersQuery);

// Handle Review Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_review'])) {
    $orderID = intval($_POST['orderID']);
    $rating = intval($_POST['rating']);
    $comment = htmlspecialchars($_POST['comment'] ?? '');

    // Check if order is delivered and belongs to user
    $checkStmt = $conn->prepare("SELECT status FROM orders WHERE orderID = ? AND $customerColumn = ? AND status = 'Delivered'");
    $checkStmt->bind_param("ii", $orderID, $userID);
    $checkStmt->execute();
    $isDelivered = $checkStmt->get_result()->num_rows > 0;
    $checkStmt->close();

    if ($isDelivered && $rating >= 1 && $rating <= 5) {
        // Check if already reviewed
        $reviewCheck = $conn->prepare("SELECT reviewID FROM reviews WHERE orderID = ? AND userID = ?");
        $reviewCheck->bind_param("ii", $orderID, $userID);
        $reviewCheck->execute();
        $alreadyReviewed = $reviewCheck->get_result()->num_rows > 0;
        $reviewCheck->close();

        if (!$alreadyReviewed) {
            $insertStmt = $conn->prepare("INSERT INTO reviews (orderID, userID, rating, comment) VALUES (?, ?, ?, ?)");
            $insertStmt->bind_param("iiis", $orderID, $userID, $rating, $comment);
            $insertStmt->execute();
            $insertStmt->close();

            echo '<script>alert("Thank you for your review!"); window.location = "order_history.php";</script>';
            exit();
        } else {
            echo '<script>alert("You have already reviewed this order."); window.location = "order_history.php";</script>';
            exit();
        }
    } else {
        echo '<script>alert("Invalid review submission."); window.location = "order_history.php";</script>';
        exit();
    }
}

// Handle Order Cancellation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_order'])) {
    $orderID = intval($_POST['orderID']);
    $cancelReason = htmlspecialchars($_POST['cancel_reason']);
    $additionalNote = htmlspecialchars($_POST['additional_note'] ?? '');

    // Check if order belongs to user and can be cancelled
    $checkStmt = $conn->prepare("SELECT status, order_date FROM orders WHERE orderID = ? AND $customerColumn = ?");
    $checkStmt->bind_param("ii", $orderID, $userID);
    $checkStmt->execute();
    $orderData = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($orderData && in_array($orderData['status'], ['Pending', 'Processing'])) {
        // Check time limit (2 hours)
        $orderTime = strtotime($orderData['order_date']);
        $currentTime = time();
        $hoursDiff = ($currentTime - $orderTime) / 3600;

        if ($hoursDiff <= 2) {
            // Cancel the order
            $fullReason = $cancelReason;
            if (!empty($additionalNote)) {
                $fullReason .= " - " . $additionalNote;
            }

            $updateStmt = $conn->prepare("UPDATE orders SET status = 'Cancelled', cancelled_at = NOW(), cancel_reason = ? WHERE orderID = ?");
            $updateStmt->bind_param("si", $fullReason, $orderID);
            $updateStmt->execute();
            $updateStmt->close();

            // Send notification
            $message = "Your order #$orderID has been cancelled successfully.";
            $notifStmt = $conn->prepare("INSERT INTO notifications (userID, message) VALUES (?, ?)");
            $notifStmt->bind_param("is", $userID, $message);
            $notifStmt->execute();
            $notifStmt->close();

            echo '<script>alert("Order cancelled successfully!"); window.location = "order_history.php";</script>';
            exit();
        } else {
            echo '<script>alert("Sorry, you can only cancel orders within 2 hours of placing them."); window.location = "order_history.php";</script>';
            exit();
        }
    } else {
        echo '<script>alert("This order cannot be cancelled."); window.location = "order_history.php";</script>';
        exit();
    }
}
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History • De Chavez Waterhaus</title>
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
        .main-content { margin-left: 260px; padding: 30px; transition: margin-left 0.3s ease; }
        .sidebar.collapsed ~ .main-content { margin-left: 80px; }
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 25px; }
        
        .order-table { background: white; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); overflow: hidden; }
        .order-table th { background: #f8f9fa; font-weight: 600; color: #475569; }
        .order-table td { vertical-align: middle; }
        
        .status-badge { padding: 7px 16px; border-radius: 50px; font-size: 0.82rem; font-weight: 600; display: inline-block; }
        
        .star-rating { display: flex; flex-direction: row-reverse; justify-content: center; }
        .star-rating input { display: none; }
        .star-rating label { font-size: 2rem; color: #ddd; cursor: pointer; padding: 0 5px; }
        .star-rating label:hover, .star-rating label:hover ~ label, .star-rating input:checked ~ label { color: #ffc107; }

        /* Mobile Responsive */
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
        
        @media (max-width: 576px) {
            .main-content { padding: 15px; }
            .order-table { font-size: 0.9rem; }
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
                <li class="nav-item"><a href="order_history.php" class="nav-link active"><i class="fas fa-history me-3"></i> <span>Order History</span></a></li>
                <li class="nav-item"><a href="order_tracking.php" class="nav-link"><i class="fas fa-map-marker-alt me-3"></i> <span>Track Orders</span></a></li>
                <li class="nav-item"><a href="recurring_orders.php" class="nav-link"><i class="fas fa-redo me-3"></i> <span>Recurring Orders</span></a></li>
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
                    <h4 class="fw-bold mb-0">Order History</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Track all your past water deliveries</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <a href="order_history.php?order=<?php echo $order == 'ASC' ? 'desc' : 'asc'; ?>" class="btn btn-outline-primary px-4 rounded-pill d-none d-sm-inline-block">
                    <i class="fas fa-sort me-2"></i> Sort by Date
                </a>
                
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

        <!-- Orders Table -->
        <div class="order-table">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Order ID</th>
                            <th>Date</th>
                            <th>Products</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ordersResult->num_rows > 0): ?>
                            <?php while ($order = $ordersResult->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4"><strong class="text-dark">#<?php echo $order['orderID']; ?></strong></td>
                                    <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                    <td>
                                        <div class="fw-medium"><?php echo $order['products']; ?></div>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-primary">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = match($order['status']) {
                                            'Delivered' => 'bg-success',
                                            'Out for Delivery' => 'bg-warning text-dark',
                                            'Processing' => 'bg-info text-dark',
                                            'Pending' => 'bg-secondary',
                                            'Cancelled' => 'bg-danger',
                                            default => 'bg-secondary'
                                        };
                                        ?>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo $order['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?php echo $order['payment_method']; ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-flex justify-content-end gap-2">
                                            <a href="order_details.php?order_id=<?php echo $order['orderID']; ?>" class="btn btn-sm btn-outline-primary px-3 rounded-pill">
                                                View
                                            </a>
                                            
                                            <?php 
                                            // Show Cancel button only for Pending/Processing orders within 2 hours
                                            $canCancel = in_array($order['status'], ['Pending', 'Processing']);
                                            if ($canCancel):
                                                $hoursSinceOrder = (time() - strtotime($order['order_date'])) / 3600;
                                                if ($hoursSinceOrder <= 2):
                                            ?>
                                                <button class="btn btn-sm btn-outline-danger px-3 rounded-pill" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#cancelModal"
                                                        data-orderid="<?php echo $order['orderID']; ?>">
                                                    Cancel
                                                </button>
                                            <?php 
                                                endif;
                                            endif; 

                                            // Show Rate & Review button for Delivered orders
                                            if ($order['status'] == 'Delivered'):
                                                // Check if already reviewed
                                                $reviewCheck = $conn->prepare("SELECT reviewID FROM reviews WHERE orderID = ? AND userID = ?");
                                                $reviewCheck->bind_param("ii", $order['orderID'], $userID);
                                                $reviewCheck->execute();
                                                $alreadyReviewed = $reviewCheck->get_result()->num_rows > 0;
                                                $reviewCheck->close();

                                                if (!$alreadyReviewed):
                                            ?>
                                                <button class="btn btn-sm btn-warning px-3 rounded-pill text-dark" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#reviewModal"
                                                        data-orderid="<?php echo $order['orderID']; ?>">
                                                    <i class="fas fa-star me-1"></i> Rate
                                                </button>
                                            <?php 
                                                else:
                                            ?>
                                                <span class="badge bg-success px-2 py-1">Reviewed</span>
                                            <?php 
                                                endif;
                                            endif; 
                                            ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No orders found yet.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>

    <!-- Review Modal -->
    <div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title fw-bold"><i class="fas fa-star me-2"></i>Rate & Review</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="orderID" id="reviewOrderID">
                        
                        <div class="mb-4 text-center">
                            <label class="form-label fw-semibold d-block mb-2">Your Rating</label>
                            <div class="star-rating">
                                <input type="radio" id="star5" name="rating" value="5" required>
                                <label for="star5" class="fas fa-star"></label>
                                <input type="radio" id="star4" name="rating" value="4">
                                <label for="star4" class="fas fa-star"></label>
                                <input type="radio" id="star3" name="rating" value="3">
                                <label for="star3" class="fas fa-star"></label>
                                <input type="radio" id="star2" name="rating" value="2">
                                <label for="star2" class="fas fa-star"></label>
                                <input type="radio" id="star1" name="rating" value="1">
                                <label for="star1" class="fas fa-star"></label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Your Review (Optional)</label>
                            <textarea class="form-control" name="comment" rows="3" placeholder="Share your experience with our service..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_review" class="btn btn-warning px-5 text-dark fw-semibold">Submit Review</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cancel Order Modal -->
    <div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title fw-bold"><i class="fas fa-times-circle me-2"></i>Cancel Order</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="orderID" id="cancelOrderID">
                        
                        <div class="alert alert-warning py-2">
                            <strong>Warning:</strong> You can only cancel orders within 2 hours of placing them.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Reason for Cancellation</label>
                            <select class="form-select" name="cancel_reason" required>
                                <option value="">Select a reason</option>
                                <option value="Changed my mind">Changed my mind</option>
                                <option value="Found a better price">Found a better price elsewhere</option>
                                <option value="Delivery time too long">Delivery time too long</option>
                                <option value="Ordered by mistake">Ordered by mistake</option>
                                <option value="No longer needed">No longer needed</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Additional Note (Optional)</label>
                            <textarea class="form-control" name="additional_note" rows="2" placeholder="Any additional details..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Keep Order</button>
                        <button type="submit" name="cancel_order" class="btn btn-danger px-5">Yes, Cancel Order</button>
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
        
        // Cancel Modal - Set Order ID
        const cancelModal = document.getElementById('cancelModal');
        cancelModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const orderID = button.getAttribute('data-orderid');
            document.getElementById('cancelOrderID').value = orderID;
        });

        // Review Modal - Set Order ID
        const reviewModal = document.getElementById('reviewModal');
        reviewModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const orderID = button.getAttribute('data-orderid');
            document.getElementById('reviewOrderID').value = orderID;
        });
    </script>
</body>
</html>