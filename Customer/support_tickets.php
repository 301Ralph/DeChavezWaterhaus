<?php
include '../includes/connection.php';
session_start();

// Security check
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    header("Location: ../login.php");
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
        $verifyStmt = $conn->prepare("SELECT ticketID FROM support_tickets WHERE ticketID = ? AND userID = ?");
        $verifyStmt->bind_param("ii", $ticketID, $userID);
        $verifyStmt->execute();
        $ticketExists = $verifyStmt->get_result()->num_rows > 0;
        $verifyStmt->close();

        if ($ticketExists) {
            $ticketStmt = $conn->prepare("SELECT conversation FROM support_tickets WHERE ticketID = ?");
            $ticketStmt->bind_param("i", $ticketID);
            $ticketStmt->execute();
            $ticketData = $ticketStmt->get_result()->fetch_assoc();
            $ticketStmt->close();

            $conversation = [];
            if (!empty($ticketData['conversation'])) {
                $conversation = json_decode($ticketData['conversation'], true) ?? [];
            }

            $conversation[] = [
                'sender' => 'customer',
                'message' => $messageText,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            $updateStmt = $conn->prepare("UPDATE support_tickets SET conversation = ?, last_reply_at = NOW() WHERE ticketID = ?");
            $conversationJson = json_encode($conversation);
            $updateStmt->bind_param("si", $conversationJson, $ticketID);
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
        $newTicketID = $conn->insert_id;
        
        $welcomeMsg = [
            [
                'sender' => 'system',
                'message' => 'Thank you for contacting De Chavez Waterhaus Support! Your ticket has been received and our team will respond within 24 hours. Ticket #' . $newTicketID,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ];
        
        $updateStmt = $conn->prepare("UPDATE support_tickets SET conversation = ? WHERE ticketID = ?");
        $welcomeMsgJson = json_encode($welcomeMsg);
        $updateStmt->bind_param("si", $welcomeMsgJson, $newTicketID);
        $updateStmt->execute();
        $updateStmt->close();
        
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

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName = explode(' ', $userName)[0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Center • De Chavez Waterhaus</title>
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

        /* ── SUPPORT CARDS ── */
        .support-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 28px;
        }

        .stat-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 22px;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--aqua);
        }

        .stat-label {
            font-size: 0.82rem;
            color: rgba(202,240,248,0.5);
            margin-top: 4px;
        }

        /* Messenger Styles */
        .messenger-container {
            display: flex;
            height: calc(100vh - 140px);
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
        }
        
        .ticket-list {
            width: 320px;
            border-right: 1px solid var(--glass-border);
            overflow-y: auto;
            background: rgba(4,30,53,0.4);
        }
        
        .ticket-list-header {
            padding: 20px;
            border-bottom: 1px solid var(--glass-border);
            background: rgba(4,30,53,0.6);
        }
        
        .ticket-item {
            padding: 15px 20px;
            border-bottom: 1px solid var(--glass-border);
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--white);
            text-decoration: none;
            display: block;
        }
        
        .ticket-item:hover { background: var(--glass); }
        .ticket-item.active { 
            background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12));
            border-left: 4px solid var(--aqua);
        }
        
        .ticket-subject { 
            font-size: 0.9rem; 
            color: rgba(202,240,248,0.5); 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
        }
        
        .ticket-time { 
            font-size: 0.75rem; 
            color: rgba(202,240,248,0.3); 
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: rgba(4,30,53,0.3);
        }
        
        .chat-header {
            padding: 18px 24px;
            background: rgba(4,30,53,0.6);
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .chat-messages {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
            background: rgba(2,13,24,0.4);
        }
        
        .message-bubble {
            max-width: 70%;
            padding: 14px 20px;
            border-radius: 18px;
            margin-bottom: 14px;
            position: relative;
        }
        
        .message-bubble.customer {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep);
            border-bottom-right-radius: 4px;
            margin-left: auto;
            font-weight: 500;
        }
        
        .message-bubble.admin {
            background: rgba(10,45,74,0.8);
            border-bottom-left-radius: 4px;
            margin-right: auto;
            border: 1px solid var(--glass-border);
        }
        
        .message-bubble.system {
            background: rgba(244,200,66,0.1);
            border: 1px solid rgba(244,200,66,0.3);
            color: var(--gold);
            max-width: 85%;
            margin: 0 auto 14px;
            text-align: center;
        }
        
        .message-time {
            font-size: 0.68rem;
            opacity: 0.6;
            margin-top: 6px;
        }
        
        .chat-input-area {
            padding: 18px 24px;
            background: rgba(4,30,53,0.6);
            border-top: 1px solid var(--glass-border);
        }
        
        .message-input {
            background: rgba(4,30,53,0.6);
            border: 1px solid var(--glass-border);
            color: var(--white);
            border-radius: 25px;
            padding: 14px 22px;
            resize: none;
        }
        
        .message-input:focus {
            border-color: var(--aqua);
            box-shadow: 0 0 0 0.2rem rgba(0,180,216,0.15);
        }
        
        .empty-chat {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            flex-direction: column;
            color: rgba(202,240,248,0.4);
        }
        
        .empty-chat i { 
            font-size: 4rem; 
            margin-bottom: 20px; 
            opacity: 0.3; 
        }

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
            .messenger-container { height: calc(100vh - 100px); }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .messenger-container { flex-direction: column; height: auto; }
            .ticket-list { width: 100%; height: 300px; }
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
        <a href="recurring_orders.php" class="nav-link">
            <i class="fas fa-redo"></i> Recurring Orders
        </a>

        <div class="nav-section-label">Account</div>
        <a href="support_tickets.php" class="nav-link active">
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
                <h4>Support Center</h4>
                <p>Get help from our support team</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if ($notifCount > 0): ?>
                    <span class="topbar-notif-badge"><?php echo $notifCount > 9 ? '9+' : $notifCount; ?></span>
                <?php endif; ?>
            </a>

            <button class="btn btn-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                <i class="fas fa-plus me-2"></i> New Ticket
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

    <!-- Flash Alert -->
    <?php if ($flashMessage): ?>
    <div class="alert alert-<?php echo $flashType === 'success' ? 'success' : ($flashType === 'error' ? 'danger' : 'info'); ?> alert-dismissible fade show mb-4" role="alert" style="border-radius: 12px; background: rgba(4,30,53,0.8); border: 1px solid var(--glass-border); color: var(--white);">
        <i class="fas fa-<?php echo $flashType === 'success' ? 'check-circle' : 'info-circle'; ?> me-2"></i>
        <?php echo htmlspecialchars($flashMessage); ?>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

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
                <div class="stat-number" style="color: #4ade80;"><?php echo $stats['open']; ?></div>
                <div class="stat-label">Open</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--gold);"><?php echo $stats['in_progress']; ?></div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: var(--aqua);"><?php echo $stats['resolved'] + $stats['closed']; ?></div>
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
                <small style="color: rgba(202,240,248,0.4);"><?php echo $tickets->num_rows; ?> tickets</small>
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
                <a href="?ticket=<?php echo $ticket['ticketID']; ?>" class="ticket-item <?php echo $isActive ? 'active' : ''; ?>">
                    <div class="d-flex align-items-start">
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div class="fw-semibold"><?php echo htmlspecialchars(substr($ticket['subject'], 0, 25)); ?>...</div>
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
                <div class="p-4 text-center" style="color: rgba(202,240,248,0.4);">
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
                        <small style="color: rgba(202,240,248,0.4);">Ticket #<?php echo $selectedTicket['ticketID']; ?> • <?php echo $selectedTicket['category']; ?></small>
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
                        <div class="fw-semibold small mb-1" style="color: var(--aqua);">You (Original Request)</div>
                        <?php echo nl2br(htmlspecialchars($selectedTicket['message'])); ?>
                        <div class="message-time"><?php echo date('M j, g:i A', strtotime($selectedTicket['created_at'])); ?></div>
                    </div>

                    <!-- Conversation messages -->
                    <?php foreach ($conversation as $msg): ?>
                        <div class="message-bubble <?php echo $msg['sender'] === 'customer' ? 'customer' : ($msg['sender'] === 'system' ? 'system' : 'admin'); ?>">
                            <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                            <div class="message-time"><?php echo date('M j, g:i A', strtotime($msg['timestamp'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Input Area -->
                <?php if ($selectedTicket['status'] !== 'Closed'): ?>
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
                <div class="chat-input-area">
                    <div class="alert alert-secondary py-2 px-3 mb-0 text-center" style="background: rgba(4,30,53,0.6); border: 1px solid var(--glass-border); color: var(--foam);">
                        <i class="fas fa-lock me-2"></i>
                        <strong>This conversation is closed.</strong> Thank you for contacting us!
                    </div>
                </div>
                <?php endif; ?>
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

</main>

<!-- New Ticket Modal -->
<div class="modal fade" id="newTicketModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-headset me-2"></i> Submit New Support Ticket
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_ticket" class="btn btn-primary px-5">Submit Ticket</button>
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