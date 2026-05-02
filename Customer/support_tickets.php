<?php
include '../includes/connection.php';
session_start();

// Security check
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    header("Location: ../login.php");
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

// Handle flash messages
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
if ($flashMessage) {
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// Handle sending a follow-up message
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $ticketID = intval($_POST['ticketID']);
    $messageText = trim(htmlspecialchars($_POST['message']));

    if (!empty($messageText)) {
        // Verify ticket belongs to user
        $verifyStmt = $conn->prepare("SELECT ticketID FROM support_tickets WHERE ticketID = ? AND userID = ?");
        $verifyStmt->bind_param("ii", $ticketID, $userID);
        $verifyStmt->execute();
        $ticketExists = $verifyStmt->get_result()->num_rows > 0;
        $verifyStmt->close();

        if ($ticketExists) {
            // Get current conversation
            $ticketStmt = $conn->prepare("SELECT conversation FROM support_tickets WHERE ticketID = ?");
            $ticketStmt->bind_param("i", $ticketID);
            $ticketStmt->execute();
            $ticketData = $ticketStmt->get_result()->fetch_assoc();
            $ticketStmt->close();

            $conversation = [];
            if (!empty($ticketData['conversation'])) {
                $conversation = json_decode($ticketData['conversation'], true) ?? [];
            }

            // Add customer message
            $conversation[] = [
                'sender' => 'customer',
                'message' => $messageText,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Update ticket
            $updateStmt = $conn->prepare("UPDATE support_tickets SET conversation = ?, last_reply_at = NOW() WHERE ticketID = ?");
            $updateStmt->bind_param("si", json_encode($conversation), $ticketID);
            $updateStmt->execute();
            $updateStmt->close();

            $_SESSION['flash_message'] = "Message sent successfully!";
            $_SESSION['flash_type'] = "success";
        }
    }

    header("Location: support_tickets.php" . (isset($_GET['ticket']) ? "?ticket=" . $_GET['ticket'] : ""));
    exit();
}

// Handle new ticket submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ticket'])) {
    $subject = htmlspecialchars($_POST['subject']);
    $category = htmlspecialchars($_POST['category']);
    $message = htmlspecialchars($_POST['message']);
    $priority = $_POST['priority'] ?? 'Medium';

    $insertStmt = $conn->prepare("INSERT INTO support_tickets (userID, subject, category, message, priority) VALUES (?, ?, ?, ?, ?)");
    $insertStmt->bind_param("issss", $userID, $subject, $category, $message, $priority);
    
    if ($insertStmt->execute()) {
        $_SESSION['flash_message'] = "Ticket submitted successfully! We will respond soon.";
        $_SESSION['flash_type'] = "success";
    } else {
        $_SESSION['flash_message'] = "Failed to submit ticket. Please try again.";
        $_SESSION['flash_type'] = "error";
    }
    $insertStmt->close();

    header("Location: support_tickets.php");
    exit();
}

// Fetch user's tickets
$tickets = $conn->query("SELECT * FROM support_tickets WHERE userID = $userID ORDER BY COALESCE(last_reply_at, created_at) DESC");

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

// Get selected ticket if viewing chat
$selectedTicket = null;
$conversation = [];
if (isset($_GET['ticket'])) {
    $selectedID = intval($_GET['ticket']);
    $selStmt = $conn->prepare("SELECT * FROM support_tickets WHERE ticketID = ? AND userID = ?");
    $selStmt->bind_param("ii", $selectedID, $userID);
    $selStmt->execute();
    $selectedTicket = $selStmt->get_result()->fetch_assoc();
    $selStmt->close();

    if ($selectedTicket && !empty($selectedTicket['conversation'])) {
        $conversation = json_decode($selectedTicket['conversation'], true) ?? [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Center • De Chavez Waterhaus</title>
    <link rel="icon" href="../images/logo.jpg" type="image/jpeg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background-color: #f0f2f5; }
        
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
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: #f0f7ff; color: var(--primary); }
        .sidebar .nav-link i { width: 22px; font-size: 1.1rem; }
        
        .main-content { margin-left: 260px; padding: 20px; transition: margin-left 0.3s ease; }
        
        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 15px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }

        /* Messenger Styles */
        .messenger-container {
            display: flex;
            height: calc(100vh - 100px);
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .ticket-list {
            width: 320px;
            border-right: 1px solid #e9ecef;
            overflow-y: auto;
        }
        
        .ticket-list-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .ticket-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .ticket-item:hover { background: #f8f9fa; }
        .ticket-item.active { background: #e8f4ff; border-left: 4px solid #0077B6; }
        
        .ticket-item .ticket-subject { font-size: 0.9rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ticket-item .ticket-time { font-size: 0.75rem; color: #94a3b8; }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f8f9fa;
        }
        
        .chat-header {
            padding: 15px 20px;
            background: white;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #f0f2f5;
        }
        
        .message-bubble {
            max-width: 70%;
            padding: 12px 18px;
            border-radius: 18px;
            margin-bottom: 12px;
            position: relative;
        }
        
        .message-bubble.customer {
            background: #0077B6;
            color: white;
            border-bottom-right-radius: 4px;
            margin-left: auto;
        }
        
        .message-bubble.admin {
            background: white;
            border-bottom-left-radius: 4px;
            margin-right: auto;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        
        .message-time {
            font-size: 0.7rem;
            opacity: 0.7;
            margin-top: 4px;
        }
        
        .chat-input-area {
            padding: 15px 20px;
            background: white;
            border-top: 1px solid #e9ecef;
        }
        
        .message-input {
            border-radius: 25px;
            border: 1px solid #dee2e6;
            padding: 12px 20px;
            resize: none;
        }
        
        .empty-chat {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            flex-direction: column;
            color: #94a3b8;
        }
        
        .empty-chat i { font-size: 4rem; margin-bottom: 20px; opacity: 0.5; }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #0077B6;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 5px;
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
                <small class="d-block text-muted">Customer Portal</small>
            </div>
        </div>
        
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
        <!-- Flash Alert -->
        <?php if ($flashMessage): ?>
        <div class="alert alert-<?php echo $flashType === 'success' ? 'success' : ($flashType === 'error' ? 'danger' : 'info'); ?> alert-dismissible fade show mb-3" role="alert" style="border-radius: 12px;">
            <i class="fas fa-<?php echo $flashType === 'success' ? 'check-circle' : 'info-circle'; ?> me-2"></i>
            <?php echo htmlspecialchars($flashMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Top Navbar -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="fw-bold mb-0">Support Center</h4>
                <p class="text-muted mb-0 small">Get help from our support team</p>
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
        <div class="row g-3 mb-3">
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

        <!-- Messenger Interface -->
        <div class="messenger-container">
            <!-- Ticket List -->
            <div class="ticket-list">
                <div class="ticket-list-header">
                    <h6 class="fw-bold mb-1"><i class="fas fa-inbox me-2"></i> My Tickets</h6>
                    <small class="text-muted"><?php echo $tickets->num_rows; ?> tickets</small>
                </div>
                
                <?php if ($tickets->num_rows > 0): ?>
                    <?php 
                    $tickets->data_seek(0);
                    while ($ticket = $tickets->fetch_assoc()): 
                        $isActive = $selectedTicket && $selectedTicket['ticketID'] == $ticket['ticketID'];
                        $lastMsg = !empty($ticket['conversation']) ? json_decode($ticket['conversation'], true) : [];
                        $lastMsgText = !empty($lastMsg) ? end($lastMsg)['message'] : $ticket['message'];
                        $lastMsgTime = !empty($ticket['last_reply_at']) ? $ticket['last_reply_at'] : $ticket['created_at'];
                    ?>
                    <a href="?ticket=<?php echo $ticket['ticketID']; ?>" class="d-block text-decoration-none ticket-item <?php echo $isActive ? 'active' : ''; ?>">
                        <div class="d-flex align-items-start">
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars(substr($ticket['subject'], 0, 25)); ?>...</div>
                                    <div class="ticket-time"><?php echo date('g:i A', strtotime($lastMsgTime)); ?></div>
                                </div>
                                <div class="ticket-subject mb-1"><?php echo htmlspecialchars(substr($lastMsgText, 0, 40)); ?>...</div>
                                <div>
                                    <span class="badge bg-<?php echo $ticket['status'] == 'Open' ? 'warning' : ($ticket['status'] == 'In Progress' ? 'info' : ($ticket['status'] == 'Resolved' ? 'success' : 'secondary')); ?> small">
                                        <?php echo $ticket['status']; ?>
                                    </span>
                                    <span class="badge bg-<?php echo $ticket['priority'] == 'High' ? 'danger' : ($ticket['priority'] == 'Medium' ? 'warning' : 'info'); ?> small ms-1">
                                        <?php echo $ticket['priority']; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-inbox fa-3x mb-3 opacity-50"></i>
                        <p>No tickets yet</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Chat Area -->
            <div class="chat-area">
                <?php if ($selectedTicket): ?>
                    <!-- Chat Header -->
                    <div class="chat-header">
                        <div>
                            <div class="fw-bold"><?php echo htmlspecialchars($selectedTicket['subject']); ?></div>
                            <small class="text-muted">Ticket #<?php echo $selectedTicket['ticketID']; ?> • <?php echo $selectedTicket['category']; ?></small>
                        </div>
                        <div>
                            <span class="badge bg-<?php echo $selectedTicket['status'] == 'Open' ? 'warning' : ($selectedTicket['status'] == 'In Progress' ? 'info' : ($selectedTicket['status'] == 'Resolved' ? 'success' : 'secondary')); ?> px-3 py-2">
                                <?php echo $selectedTicket['status']; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div class="chat-messages" id="chatMessages">
                        <!-- Original ticket message -->
                        <div class="message-bubble admin">
                            <div class="fw-semibold small mb-1">You (Original Request)</div>
                            <?php echo nl2br(htmlspecialchars($selectedTicket['message'])); ?>
                            <div class="message-time"><?php echo date('M j, g:i A', strtotime($selectedTicket['created_at'])); ?></div>
                        </div>

                        <!-- Conversation messages -->
                        <?php foreach ($conversation as $msg): ?>
                            <div class="message-bubble <?php echo $msg['sender'] === 'customer' ? 'customer' : 'admin'; ?>">
                                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                <div class="message-time"><?php echo date('M j, g:i A', strtotime($msg['timestamp'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Input Area -->
                    <div class="chat-input-area">
                        <form method="POST" class="d-flex gap-2">
                            <input type="hidden" name="ticketID" value="<?php echo $selectedTicket['ticketID']; ?>">
                            
                            <div class="flex-grow-1">
                                <textarea name="message" class="form-control message-input" rows="1" placeholder="Type your message..." required></textarea>
                            </div>
                            
                            <button type="submit" name="send_message" class="btn btn-primary px-4">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="empty-chat">
                        <i class="fas fa-comments"></i>
                        <h5 class="fw-bold mt-3">Select a Ticket</h5>
                        <p class="text-muted">Choose a ticket from the list to view the conversation</p>
                        <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                            <i class="fas fa-plus me-2"></i> Create New Ticket
                        </button>
                    </div>
                <?php endif; ?>
            </div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));
            
            document.addEventListener('click', function(e) {
                if (window.innerWidth < 992 && !sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            });
        }

        // Auto-scroll chat to bottom
        const chatMessages = document.getElementById('chatMessages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Auto-resize textarea
        const textarea = document.querySelector('.message-input');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 100) + 'px';
            });
        }
    </script>
</body>
</html>