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

// Fetch user data
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle new ticket submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ticket'])) {
    $subject = htmlspecialchars($_POST['subject']);
    $category = htmlspecialchars($_POST['category']);
    $message = htmlspecialchars($_POST['message']);
    $priority = $_POST['priority'] ?? 'Medium';

    $insertStmt = $conn->prepare("INSERT INTO support_tickets (userID, subject, category, message, priority) VALUES (?, ?, ?, ?, ?)");
    $insertStmt->bind_param("issss", $userID, $subject, $category, $message, $priority);
    
    if ($insertStmt->execute()) {
        echo '<script>alert("Ticket submitted successfully! We will respond soon."); window.location = "support_tickets.php";</script>';
        exit();
    } else {
        echo '<script>alert("Failed to submit ticket. Please try again.");</script>';
    }
    $insertStmt->close();
}

// Fetch user's tickets with statistics
$tickets = $conn->query("SELECT * FROM support_tickets WHERE userID = $userID ORDER BY created_at DESC");

// Get ticket statistics
$statsQuery = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed
    FROM support_tickets 
    WHERE userID = $userID
");
$stats = $statsQuery->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets • De Chavez Waterhaus</title>
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
        
        .ticket-card { background: white; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
        .status-open { background: #d4edda; color: #155724; }
        .status-in-progress { background: #fff3cd; color: #856404; }
        .status-resolved { background: #cce5ff; color: #004085; }
        .status-closed { background: #e2e3e5; color: #383d41; }

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
                <li class="nav-item"><a href="recurring_orders.php" class="nav-link"><i class="fas fa-redo me-3"></i> <span>Recurring Orders</span></a></li>
                <li class="nav-item"><a href="support_tickets.php" class="nav-link active"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
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
                    <h4 class="fw-bold mb-0">Support Tickets</h4>
                    <p class="text-muted mb-0 d-none d-sm-block">Get help from our support team</p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                    <i class="fas fa-plus me-2"></i> New Ticket
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

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Tickets</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-success"><?php echo $stats['open']; ?></div>
                    <div class="stat-label">Open</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-warning"><?php echo $stats['in_progress']; ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-primary"><?php echo $stats['resolved'] + $stats['closed']; ?></div>
                    <div class="stat-label">Resolved</div>
                </div>
            </div>
        </div>

        <!-- Tickets List -->
        <div class="ticket-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="fw-bold mb-0">Your Tickets</h5>
                <?php if ($tickets->num_rows > 0): ?>
                    <span class="badge bg-primary"><?php echo $tickets->num_rows; ?> Total</span>
                <?php endif; ?>
            </div>
            
            <?php if ($tickets->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket ID</th>
                                <th>Subject</th>
                                <th>Category</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Reset the result pointer
                            $tickets->data_seek(0);
                            while ($ticket = $tickets->fetch_assoc()) { 
                                $priorityClass = 'priority-medium';
                                if ($ticket['priority'] == 'Low') $priorityClass = 'priority-low';
                                if ($ticket['priority'] == 'High') $priorityClass = 'priority-high';
                                
                                $statusClass = 'status-open';
                                if ($ticket['status'] == 'In Progress') $statusClass = 'status-in-progress';
                                if ($ticket['status'] == 'Resolved') $statusClass = 'status-resolved';
                                if ($ticket['status'] == 'Closed') $statusClass = 'status-closed';
                            ?>
                                <tr>
                                    <td><strong>#<?php echo $ticket['ticketID']; ?></strong></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                                        <small class="text-muted"><?php echo substr(htmlspecialchars($ticket['message']), 0, 50) . '...'; ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo $ticket['category']; ?></span></td>
                                    <td><span class="badge <?php echo $priorityClass; ?>"><?php echo $ticket['priority']; ?></span></td>
                                    <td>
                                        <span class="badge <?php echo $statusClass; ?>">
                                            <?php echo $ticket['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></div>
                                        <small class="text-muted"><?php echo date('g:i A', strtotime($ticket['created_at'])); ?></small>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary px-3 rounded-pill" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#viewTicketModal"
                                                data-ticketid="<?php echo $ticket['ticketID']; ?>"
                                                data-subject="<?php echo htmlspecialchars($ticket['subject']); ?>"
                                                data-category="<?php echo $ticket['category']; ?>"
                                                data-priority="<?php echo $ticket['priority']; ?>"
                                                data-status="<?php echo $ticket['status']; ?>"
                                                data-message="<?php echo htmlspecialchars($ticket['message']); ?>"
                                                data-created="<?php echo date('F j, Y g:i A', strtotime($ticket['created_at'])); ?>"
                                                data-updated="<?php echo date('F j, Y g:i A', strtotime($ticket['updated_at'])); ?>">
                                            <i class="fas fa-eye me-1"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-headset fa-4x text-muted mb-4"></i>
                    <h5 class="fw-bold">No Support Tickets Yet</h5>
                    <p class="text-muted">Submit a ticket if you need help with orders, deliveries, or account issues.</p>
                    <button class="btn btn-primary px-5 rounded-pill" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                        <i class="fas fa-plus me-2"></i> Submit New Ticket
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Ticket Modal -->
    <div class="modal fade" id="newTicketModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Submit New Support Ticket</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Subject</label>
                            <input type="text" class="form-control" name="subject" placeholder="Brief description of your issue" required>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Category</label>
                                <select class="form-select" name="category" required>
                                    <option value="">Select Category</option>
                                    <option value="Order Issue">Order Issue</option>
                                    <option value="Delivery Problem">Delivery Problem</option>
                                    <option value="Payment Issue">Payment Issue</option>
                                    <option value="Product Quality">Product Quality</option>
                                    <option value="Account Problem">Account Problem</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Priority</label>
                                <select class="form-select" name="priority">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3 mt-3">
                            <label class="form-label fw-semibold">Message</label>
                            <textarea class="form-control" name="message" rows="4" placeholder="Please describe your issue in detail..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="submit_ticket" class="btn btn-primary px-5">Submit Ticket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Ticket Modal -->
    <div class="modal fade" id="viewTicketModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-ticket-alt me-2"></i> Ticket Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <div class="text-muted small">Ticket ID</div>
                                    <div class="fw-bold fs-4" id="modalTicketID"></div>
                                </div>
                                <div class="text-end">
                                    <span class="badge" id="modalStatus"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="text-muted small">Subject</div>
                            <div class="fw-semibold" id="modalSubject"></div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="text-muted small">Category</div>
                            <div><span class="badge bg-secondary" id="modalCategory"></span></div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="text-muted small">Priority</div>
                            <div><span class="badge" id="modalPriority"></span></div>
                        </div>
                        
                        <div class="col-12">
                            <div class="text-muted small">Message</div>
                            <div class="p-3 bg-light rounded" id="modalMessage" style="white-space: pre-wrap;"></div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="text-muted small">Created</div>
                            <div class="fw-semibold" id="modalCreated"></div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="text-muted small">Last Updated</div>
                            <div class="fw-semibold" id="modalUpdated"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <div class="alert alert-info py-2 px-3 mb-0 w-100">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>Our support team will respond within 24 hours. You will receive an email notification when your ticket is updated.</small>
                    </div>
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Close</button>
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
            mobileToggle.addEventListener('click', () => {
                sidebar.classList.toggle('show');
            });
            
            document.addEventListener('click', function(e) {
                if (window.innerWidth < 992 && !sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            });
        }
        
        // View Ticket Modal - Populate with data
        const viewTicketModal = document.getElementById('viewTicketModal');
        viewTicketModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            
            // Get data from button attributes
            const ticketID = button.getAttribute('data-ticketid');
            const subject = button.getAttribute('data-subject');
            const category = button.getAttribute('data-category');
            const priority = button.getAttribute('data-priority');
            const status = button.getAttribute('data-status');
            const message = button.getAttribute('data-message');
            const created = button.getAttribute('data-created');
            const updated = button.getAttribute('data-updated');
            
            // Populate modal fields
            document.getElementById('modalTicketID').innerHTML = '#' + ticketID;
            document.getElementById('modalSubject').innerHTML = subject;
            document.getElementById('modalCategory').innerHTML = category;
            document.getElementById('modalPriority').innerHTML = priority;
            document.getElementById('modalStatus').innerHTML = status;
            document.getElementById('modalMessage').innerHTML = message;
            document.getElementById('modalCreated').innerHTML = created;
            document.getElementById('modalUpdated').innerHTML = updated;
            
            // Set priority badge color
            const priorityBadge = document.getElementById('modalPriority');
            priorityBadge.className = 'badge';
            if (priority === 'Low') priorityBadge.classList.add('bg-info');
            else if (priority === 'Medium') priorityBadge.classList.add('bg-warning');
            else if (priority === 'High') priorityBadge.classList.add('bg-danger');
            
            // Set status badge color
            const statusBadge = document.getElementById('modalStatus');
            statusBadge.className = 'badge';
            if (status === 'Open') statusBadge.classList.add('bg-success');
            else if (status === 'In Progress') statusBadge.classList.add('bg-warning');
            else if (status === 'Resolved') statusBadge.classList.add('bg-primary');
            else if (status === 'Closed') statusBadge.classList.add('bg-secondary');
        });
    </script>
</body>
</html>