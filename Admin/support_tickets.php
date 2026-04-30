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

// Handle ticket response
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['respond_ticket'])) {
    $ticketID = intval($_POST['ticketID']);
    $response = htmlspecialchars($_POST['response']);
    $newStatus = $_POST['status'];

    // Update ticket
    $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, admin_response = ?, responded_at = NOW() WHERE ticketID = ?");
    $stmt->bind_param("ssi", $newStatus, $response, $ticketID);
    $stmt->execute();
    $stmt->close();

    // Notify customer
    $ticket = $conn->query("SELECT userID FROM support_tickets WHERE ticketID = $ticketID")->fetch_assoc();
    if ($ticket) {
        $message = "Your support ticket #$ticketID has been responded to. Status: $newStatus";
        $stmt = $conn->prepare("INSERT INTO notifications (userID, message) VALUES (?, ?)");
        $stmt->bind_param("is", $ticket['userID'], $message);
        $stmt->execute();
        $stmt->close();
    }

    echo '<script>alert("Response sent!"); window.location = "support_tickets.php";</script>';
    exit();
}

// Fetch all tickets
$tickets = $conn->query("
    SELECT t.*, CONCAT(c.Firstname, ' ', c.Lastname) as customer_name, c.Email
    FROM support_tickets t
    JOIN customers c ON t.userID = c.userID
    ORDER BY t.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets • Admin</title>
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
        .sidebar .nav-menu {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 20px;
        }
        .sidebar .logout-section {
            padding: 15px 10px;
            border-top: 1px solid #eee;
            background: white;
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
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 20px; }
        
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
                <li class="nav-item"><a href="payroll_management.php" class="nav-link"><i class="fas fa-money-bill me-3"></i> <span>Payroll</span></a></li>
                <li class="nav-item"><a href="support_tickets.php" class="nav-link active"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
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
        <!-- Top Navbar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-lg-none me-3 shadow-sm" id="mobileToggle" style="width: 42px; height: 42px; border-radius: 12px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4 class="fw-bold mb-0">Support Tickets</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Respond to customer support requests</p>
                </div>
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

        <!-- Tickets Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Ticket ID</th>
                                <th>Customer</th>
                                <th>Subject</th>
                                <th>Category</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($tickets->num_rows > 0): ?>
                                <?php while ($ticket = $tickets->fetch_assoc()) { ?>
                                    <tr>
                                        <td class="ps-4"><strong>#<?php echo $ticket['ticketID']; ?></strong></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($ticket['customer_name']); ?></div>
                                            <small class="text-muted"><?php echo $ticket['Email']; ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($ticket['subject']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo $ticket['category']; ?></span></td>
                                        <td>
                                            <span class="badge bg-<?php echo $ticket['priority'] == 'High' ? 'danger' : ($ticket['priority'] == 'Medium' ? 'warning' : 'info'); ?>">
                                                <?php echo $ticket['priority']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $ticket['status'] == 'Open' ? 'warning' : ($ticket['status'] == 'In Progress' ? 'info' : ($ticket['status'] == 'Resolved' ? 'success' : 'secondary')); ?>">
                                                <?php echo $ticket['status']; ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted"><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
                                        <td class="text-end pe-4">
                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#respondModal<?php echo $ticket['ticketID']; ?>">
                                                <i class="fas fa-reply me-1"></i> Respond
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Respond Modal -->
                                    <div class="modal fade" id="respondModal<?php echo $ticket['ticketID']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title fw-bold">Respond to Ticket #<?php echo $ticket['ticketID']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <input type="hidden" name="ticketID" value="<?php echo $ticket['ticketID']; ?>">
                                                    <div class="modal-body p-4">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Customer Message</label>
                                                            <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($ticket['message'])); ?></div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Your Response</label>
                                                            <textarea class="form-control" name="response" rows="5" required placeholder="Type your response here..."></textarea>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Update Status</label>
                                                            <select class="form-select" name="status" required>
                                                                <option value="In Progress" <?php echo $ticket['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                                                <option value="Resolved" <?php echo $ticket['status'] == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                                                <option value="Closed" <?php echo $ticket['status'] == 'Closed' ? 'selected' : ''; ?>>Closed</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer border-0 p-4 pt-0">
                                                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="respond_ticket" class="btn btn-primary px-5">Send Response</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">No support tickets yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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