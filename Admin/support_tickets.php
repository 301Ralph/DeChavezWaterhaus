<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$adminID = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';

// Fetch admin data for profile picture
$admin = $conn->query("SELECT * FROM customers WHERE userID = " . $_SESSION['userID'])->fetch_assoc();

// Handle flash messages
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
if ($flashMessage) {
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// Handle sending a message (chat-style)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $ticketID = intval($_POST['ticketID']);
    $messageText = trim(htmlspecialchars($_POST['message']));
    $newStatus = $_POST['status'] ?? null;

    if (!empty($messageText)) {
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

        // Add admin message
        $conversation[] = [
            'sender' => 'admin',
            'message' => $messageText,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // Update ticket with new conversation
        $updateStmt = $conn->prepare("UPDATE support_tickets SET conversation = ?, status = COALESCE(?, status), last_reply_at = NOW() WHERE ticketID = ?");
        $statusToSet = $newStatus ?: null;
        $updateStmt->bind_param("ssi", json_encode($conversation), $statusToSet, $ticketID);
        $updateStmt->execute();
        $updateStmt->close();

        // Notify customer
        $ticket = $conn->query("SELECT userID FROM support_tickets WHERE ticketID = $ticketID")->fetch_assoc();
        if ($ticket) {
            $notifMsg = "New reply on your support ticket #$ticketID";
            $notifStmt = $conn->prepare("INSERT INTO notifications (userID, message, type) VALUES (?, ?, 'Support')");
            $notifStmt->bind_param("is", $ticket['userID'], $notifMsg);
            $notifStmt->execute();
            $notifStmt->close();
        }

        $_SESSION['flash_message'] = "Message sent successfully!";
        $_SESSION['flash_type'] = "success";
    }

    header("Location: support_tickets.php" . (isset($_GET['ticket']) ? "?ticket=" . $_GET['ticket'] : ""));
    exit();
}

// Fetch all tickets with conversation
$tickets = $conn->query("
    SELECT t.*, CONCAT(c.Firstname, ' ', c.Lastname) as customer_name, c.Email, c.profile_picture
    FROM support_tickets t
    JOIN customers c ON t.userID = c.userID
    ORDER BY COALESCE(t.last_reply_at, t.created_at) DESC
");

// Get selected ticket if viewing chat
$selectedTicket = null;
$conversation = [];
if (isset($_GET['ticket'])) {
    $selectedID = intval($_GET['ticket']);
    $selStmt = $conn->prepare("
        SELECT t.*, CONCAT(c.Firstname, ' ', c.Lastname) as customer_name, c.Email, c.profile_picture
        FROM support_tickets t
        JOIN customers c ON t.userID = c.userID
        WHERE t.ticketID = ?
    ");
    $selStmt->bind_param("i", $selectedID);
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
    <title>Support Center • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
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
        .sidebar .nav-menu { flex: 1; overflow-y: auto; padding-bottom: 20px; }
        .sidebar .logout-section { padding: 15px 10px; border-top: 1px solid #eee; background: white; }
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
        
        .ticket-item .customer-name { font-weight: 600; color: #1e293b; }
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
            background: white;
            border-bottom-left-radius: 4px;
            margin-right: auto;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        
        .message-bubble.admin {
            background: #0077B6;
            color: white;
            border-bottom-right-radius: 4px;
            margin-left: auto;
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
                <p class="text-muted mb-0 small">Chat with customers in real-time</p>
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

        <!-- Messenger Interface -->
        <div class="messenger-container">
            <!-- Ticket List -->
            <div class="ticket-list">
                <div class="ticket-list-header">
                    <h6 class="fw-bold mb-1"><i class="fas fa-inbox me-2"></i> All Tickets</h6>
                    <small class="text-muted"><?php echo $tickets->num_rows; ?> active conversations</small>
                </div>
                
                <?php if ($tickets->num_rows > 0): ?>
                    <?php 
                    // Reset pointer
                    $tickets->data_seek(0);
                    while ($ticket = $tickets->fetch_assoc()): 
                        $isActive = $selectedTicket && $selectedTicket['ticketID'] == $ticket['ticketID'];
                        $lastMsg = !empty($ticket['conversation']) ? json_decode($ticket['conversation'], true) : [];
                        $lastMsgText = !empty($lastMsg) ? end($lastMsg)['message'] : $ticket['message'];
                        $lastMsgTime = !empty($ticket['last_reply_at']) ? $ticket['last_reply_at'] : $ticket['created_at'];
                    ?>
                    <a href="?ticket=<?php echo $ticket['ticketID']; ?>" class="d-block text-decoration-none ticket-item <?php echo $isActive ? 'active' : ''; ?>">
                        <div class="d-flex align-items-start">
                            <div class="me-3">
                                <?php if (!empty($ticket['profile_picture']) && file_exists('../' . $ticket['profile_picture'])): ?>
                                    <img src="../<?php echo $ticket['profile_picture']; ?>" alt="" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                                        <span class="fw-bold"><?php echo strtoupper(substr($ticket['customer_name'], 0, 1)); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <div class="customer-name"><?php echo htmlspecialchars($ticket['customer_name']); ?></div>
                                    <div class="ticket-time"><?php echo date('g:i A', strtotime($lastMsgTime)); ?></div>
                                </div>
                                <div class="ticket-subject mb-1"><?php echo htmlspecialchars(substr($lastMsgText, 0, 50)); ?>...</div>
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
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <?php if (!empty($selectedTicket['profile_picture']) && file_exists('../' . $selectedTicket['profile_picture'])): ?>
                                    <img src="../<?php echo $selectedTicket['profile_picture']; ?>" alt="" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <div class="bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                        <span class="fw-bold fs-5"><?php echo strtoupper(substr($selectedTicket['customer_name'], 0, 1)); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($selectedTicket['customer_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($selectedTicket['Email']); ?></small>
                            </div>
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
                        <div class="message-bubble customer">
                            <div class="fw-semibold small text-muted mb-1">Original Request</div>
                            <?php echo nl2br(htmlspecialchars($selectedTicket['message'])); ?>
                            <div class="message-time"><?php echo date('M j, g:i A', strtotime($selectedTicket['created_at'])); ?></div>
                        </div>

                        <!-- Conversation messages -->
                        <?php foreach ($conversation as $msg): ?>
                            <div class="message-bubble <?php echo $msg['sender'] === 'admin' ? 'admin' : 'customer'; ?>">
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
                                <textarea name="message" class="form-control message-input" rows="1" placeholder="Type your reply..." required></textarea>
                            </div>
                            
                            <div style="width: 140px;">
                                <select name="status" class="form-select mb-2" style="font-size: 0.85rem;">
                                    <option value="">Keep <?php echo $selectedTicket['status']; ?></option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Resolved">Resolved</option>
                                    <option value="Closed">Closed</option>
                                </select>
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
                        <p class="text-muted">Choose a conversation from the list to start chatting</p>
                    </div>
                <?php endif; ?>
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