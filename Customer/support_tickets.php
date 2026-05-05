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

// Flash messages
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType    = $_SESSION['flash_type'] ?? 'info';
if ($flashMessage) { unset($_SESSION['flash_message'], $_SESSION['flash_type']); }

// Handle follow-up message
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $ticketID    = intval($_POST['ticketID']);
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
                'sender'    => 'customer',
                'message'   => $messageText,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            $updateStmt = $conn->prepare("UPDATE support_tickets SET conversation = ?, last_reply_at = NOW() WHERE ticketID = ?");
            $conversationJson = json_encode($conversation);
            $updateStmt->bind_param("si", $conversationJson, $ticketID);
            $updateStmt->execute();
            $updateStmt->close();

            $_SESSION['flash_message'] = "Message sent!";
            $_SESSION['flash_type']    = "success";
        }
    }

    header("Location: support_tickets.php" . (isset($_GET['ticket']) ? "?ticket=" . intval($_GET['ticket']) : ""));
    exit();
}

// Handle new ticket
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_ticket'])) {
    $subject  = htmlspecialchars($_POST['subject']);
    $category = htmlspecialchars($_POST['category']);
    $message  = htmlspecialchars($_POST['message']);
    $priority = $_POST['priority'] ?? 'Medium';

    $insertStmt = $conn->prepare("INSERT INTO support_tickets (userID, subject, category, message, priority) VALUES (?, ?, ?, ?, ?)");
    $insertStmt->bind_param("issss", $userID, $subject, $category, $message, $priority);

    if ($insertStmt->execute()) {
        $newTicketID = $conn->insert_id;
        $welcomeMsg = [[
            'sender'    => 'system',
            'message'   => 'Thank you for contacting De Chavez Waterhaus Support! Your ticket has been received and our team will respond within 24 hours. Ticket #' . $newTicketID,
            'timestamp' => date('Y-m-d H:i:s')
        ]];
        $updateStmt = $conn->prepare("UPDATE support_tickets SET conversation = ? WHERE ticketID = ?");
        $welcomeMsgJson = json_encode($welcomeMsg);
        $updateStmt->bind_param("si", $welcomeMsgJson, $newTicketID);
        $updateStmt->execute();
        $updateStmt->close();

        $_SESSION['flash_message'] = "Ticket #$newTicketID submitted! We'll respond within 24 hours.";
        $_SESSION['flash_type']    = "success";
    } else {
        $_SESSION['flash_message'] = "Failed to submit ticket. Please try again.";
        $_SESSION['flash_type']    = "error";
    }
    $insertStmt->close();
    header("Location: support_tickets.php");
    exit();
}

// Fetch tickets
$tickets = $conn->query("SELECT * FROM support_tickets WHERE userID = $userID ORDER BY COALESCE(last_reply_at, created_at) DESC");

// Stats
$statsQuery = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='Open' THEN 1 ELSE 0 END) as open, SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='Resolved' THEN 1 ELSE 0 END) as resolved, SUM(CASE WHEN status='Closed' THEN 1 ELSE 0 END) as closed FROM support_tickets WHERE userID = $userID");
$stats = $statsQuery->fetch_assoc();

// Selected ticket
$selectedTicket = null;
$conversation   = [];
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
$firstName  = explode(' ', $userName)[0];
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
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;  --glow:  #90e0ef;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 24px 22px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); box-shadow: 0 0 14px rgba(0,180,216,0.2); }
        .sidebar-logo span { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 16px 12px 20px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }
        .nav-section-label { font-size: 0.62rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.25); padding: 16px 12px 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 10px; color: rgba(202,240,248,0.5) !important; text-decoration: none; font-size: 0.87rem; font-weight: 500; transition: all 0.25s ease; margin-bottom: 2px; position: relative; }
        .nav-link i { width: 18px; text-align: center; font-size: 0.9rem; color: rgba(0,180,216,0.4); transition: color 0.25s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }
        .nav-link.active { background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important; }
        .nav-link.active i { color: var(--aqua); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0; }
        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }
        .notif-dot { margin-left: auto; background: var(--gold); color: var(--deep); font-size: 0.62rem; font-weight: 700; padding: 1px 6px; border-radius: 50px; min-width: 18px; text-align: center; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 28px 32px; display: flex; flex-direction: column; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .topbar-greeting h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-greeting p { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-actions { display: flex; align-items: center; gap: 12px; }
        .topbar-btn { width: 42px; height: 42px; border-radius: 50%; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.6); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; text-decoration: none; transition: all 0.3s; position: relative; }
        .topbar-btn:hover { background: rgba(0,180,216,0.15); border-color: var(--aqua); color: var(--aqua); }
        .topbar-notif-badge { position: absolute; top: -3px; right: -3px; background: var(--gold); color: var(--deep); font-size: 0.58rem; font-weight: 700; min-width: 16px; height: 16px; border-radius: 50px; display: flex; align-items: center; justify-content: center; padding: 0 4px; }
        .avatar-btn { display: flex; align-items: center; gap: 10px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 6px 14px 6px 6px; cursor: pointer; transition: all 0.3s; }
        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }
        .avatar-circle { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.82rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.7rem; color: rgba(202,240,248,0.4); }
        .dropdown-menu { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 14px !important; padding: 8px !important; box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important; }
        .dropdown-item { color: rgba(202,240,248,0.65) !important; border-radius: 8px !important; padding: 9px 14px !important; font-size: 0.84rem !important; transition: all 0.2s !important; }
        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── NEW TICKET BTN ── */
        .btn-new-ticket { background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; color: var(--deep); padding: 10px 22px; border-radius: 50px; font-weight: 700; font-size: 0.82rem; letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); display: inline-flex; align-items: center; gap: 8px; }
        .btn-new-ticket:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); color: var(--deep); }

        /* ── FLASH ALERT ── */
        .flash-alert { background: rgba(4,30,53,0.85); border: 1px solid; border-radius: 12px; padding: 12px 18px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 0.88rem; }
        .flash-alert.success { border-color: rgba(74,222,128,0.3); color: #4ade80; }
        .flash-alert.error   { border-color: rgba(248,113,113,0.3); color: #fca5a5; }
        .flash-alert.info    { border-color: var(--glass-border); color: var(--aqua); }

        /* ── STAT CARDS ── */
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 22px; }
        .stat-card { background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8)); border: 1px solid var(--glass-border); border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; }
        .stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .stat-icon.total  { background: rgba(0,180,216,0.12);  color: var(--aqua); }
        .stat-icon.open   { background: rgba(74,222,128,0.1);  color: #4ade80; }
        .stat-icon.prog   { background: rgba(244,200,66,0.1);  color: var(--gold); }
        .stat-icon.done   { background: rgba(167,139,250,0.1); color: #a78bfa; }
        .stat-num { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; font-weight: 600; line-height: 1; }
        .stat-lbl { font-size: 0.72rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 2px; }

        /* ── MESSENGER ── */
        .messenger {
            display: flex;
            flex: 1;
            min-height: 0;
            height: calc(100vh - 260px);
            background: linear-gradient(145deg, rgba(10,45,74,0.5), rgba(3,15,30,0.75));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            overflow: hidden;
        }

        /* ── TICKET LIST PANEL ── */
        .tl-panel { width: 310px; flex-shrink: 0; display: flex; flex-direction: column; border-right: 1px solid var(--glass-border); background: rgba(2,13,24,0.4); }

        .tl-header { padding: 18px 20px; border-bottom: 1px solid var(--glass-border); background: rgba(4,30,53,0.5); display: flex; justify-content: space-between; align-items: center; }
        .tl-header-title { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 500; color: var(--white); }
        .tl-header-count { font-size: 0.72rem; color: rgba(202,240,248,0.35); }

        .tl-search { padding: 12px 16px; border-bottom: 1px solid rgba(72,202,228,0.06); }
        .tl-search input { width: 100%; background: rgba(4,30,53,0.6); border: 1px solid var(--glass-border); color: var(--white); border-radius: 50px; padding: 8px 14px 8px 36px; font-size: 0.82rem; outline: none; transition: all 0.3s; font-family: 'DM Sans', sans-serif; }
        .tl-search input::placeholder { color: rgba(202,240,248,0.2); }
        .tl-search input:focus { border-color: rgba(0,180,216,0.4); background: rgba(0,180,216,0.07); }
        .tl-search-icon { position: absolute; left: 28px; top: 50%; transform: translateY(-50%); color: rgba(0,180,216,0.35); font-size: 0.75rem; }

        .tl-list { flex: 1; overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.1) transparent; }
        .tl-list::-webkit-scrollbar { width: 3px; }
        .tl-list::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.1); border-radius: 2px; }

        .tl-item { display: block; padding: 14px 18px; border-bottom: 1px solid rgba(72,202,228,0.06); text-decoration: none; transition: all 0.2s; position: relative; }
        .tl-item:hover { background: rgba(0,180,216,0.05); }
        .tl-item.selected { background: linear-gradient(135deg, rgba(0,119,182,0.2), rgba(0,180,216,0.08)); border-left: 3px solid var(--aqua); }

        .tl-item-subject { font-size: 0.87rem; font-weight: 500; color: var(--white); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px; }
        .tl-item.selected .tl-item-subject { color: var(--aqua); }
        .tl-item-preview { font-size: 0.75rem; color: rgba(202,240,248,0.38); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 6px; }
        .tl-item-meta { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
        .tl-item-time { font-size: 0.68rem; color: rgba(202,240,248,0.25); }

        /* inline status/priority badges */
        .mini-badge { padding: 2px 8px; border-radius: 50px; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.05em; }
        .mb-open { background: rgba(74,222,128,0.1);  color: #4ade80; }
        .mb-prog  { background: rgba(0,180,216,0.1);   color: var(--aqua); }
        .mb-res   { background: rgba(167,139,250,0.1); color: #a78bfa; }
        .mb-cls   { background: rgba(148,163,184,0.1); color: #94a3b8; }
        .mb-low   { background: rgba(148,163,184,0.1); color: #94a3b8; }
        .mb-med   { background: rgba(244,200,66,0.1);  color: var(--gold); }
        .mb-high  { background: rgba(248,113,113,0.1); color: #fca5a5; }

        .tl-empty { padding: 40px 20px; text-align: center; color: rgba(202,240,248,0.3); font-size: 0.85rem; }
        .tl-empty i { font-size: 2rem; display: block; margin-bottom: 12px; color: rgba(0,180,216,0.15); }

        /* ── CHAT PANEL ── */
        .chat-panel { flex: 1; min-width: 0; display: flex; flex-direction: column; background: rgba(2,13,24,0.35); }

        .chat-head { padding: 16px 22px; border-bottom: 1px solid var(--glass-border); background: rgba(4,30,53,0.5); display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-shrink: 0; }
        .chat-head-title { font-family: 'Cormorant Garamond', serif; font-size: 1.15rem; font-weight: 500; color: var(--white); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .chat-head-sub { font-size: 0.75rem; color: rgba(202,240,248,0.38); margin-top: 2px; }
        .chat-head-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        /* status pills (full size) */
        .s-pill { padding: 5px 14px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; }
        .s-Open       { background: rgba(74,222,128,0.1);  color: #4ade80;  border: 1px solid rgba(74,222,128,0.25); }
        .s-In-Progress{ background: rgba(0,180,216,0.1);   color: var(--aqua); border: 1px solid rgba(0,180,216,0.25); }
        .s-Resolved   { background: rgba(167,139,250,0.1); color: #a78bfa;  border: 1px solid rgba(167,139,250,0.25); }
        .s-Closed     { background: rgba(148,163,184,0.1); color: #94a3b8;  border: 1px solid rgba(148,163,184,0.2); }

        .chat-body { flex: 1; overflow-y: auto; padding: 24px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.1) transparent; }
        .chat-body::-webkit-scrollbar { width: 4px; }
        .chat-body::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.1); border-radius: 2px; }

        /* bubbles */
        .bubble-wrap { display: flex; margin-bottom: 14px; }
        .bubble-wrap.me { justify-content: flex-end; }
        .bubble-wrap.them { justify-content: flex-start; }
        .bubble-wrap.sys { justify-content: center; }

        .bubble { max-width: 72%; padding: 13px 18px; border-radius: 18px; position: relative; }
        .bubble.me { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); border-bottom-right-radius: 4px; font-weight: 500; }
        .bubble.them { background: rgba(10,45,74,0.8); border: 1px solid var(--glass-border); color: var(--foam); border-bottom-left-radius: 4px; }
        .bubble.sys { background: rgba(244,200,66,0.07); border: 1px solid rgba(244,200,66,0.2); color: rgba(244,200,66,0.75); font-size: 0.82rem; max-width: 80%; text-align: center; border-radius: 10px; }

        .bubble-sender { font-size: 0.7rem; font-weight: 600; margin-bottom: 5px; opacity: 0.7; }
        .bubble.me .bubble-sender { text-align: right; color: rgba(2,13,24,0.7); }
        .bubble.them .bubble-sender { color: var(--aqua); }
        .bubble-time { font-size: 0.67rem; margin-top: 6px; opacity: 0.55; }
        .bubble.me .bubble-time { text-align: right; }

        /* date divider */
        .date-divider { display: flex; align-items: center; gap: 12px; margin: 20px 0 14px; }
        .date-divider::before, .date-divider::after { content: ''; flex: 1; height: 1px; background: rgba(72,202,228,0.08); }
        .date-divider span { font-size: 0.68rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.25); white-space: nowrap; }

        /* input */
        .chat-input { padding: 16px 22px; border-top: 1px solid var(--glass-border); background: rgba(4,30,53,0.5); flex-shrink: 0; }
        .chat-input form { display: flex; gap: 10px; align-items: flex-end; }
        .chat-textarea { flex: 1; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); border-radius: 14px; padding: 12px 16px; font-family: 'DM Sans', sans-serif; font-size: 0.9rem; resize: none; outline: none; min-height: 46px; max-height: 120px; transition: border-color 0.3s; line-height: 1.5; }
        .chat-textarea::placeholder { color: rgba(202,240,248,0.22); }
        .chat-textarea:focus { border-color: var(--aqua); background: rgba(0,180,216,0.06); }

        .btn-send { width: 46px; height: 46px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; color: var(--deep); font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.3s; box-shadow: 0 4px 14px rgba(0,180,216,0.3); }
        .btn-send:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,180,216,0.5); }

        .closed-notice { padding: 14px 22px; background: rgba(4,30,53,0.5); border-top: 1px solid var(--glass-border); text-align: center; font-size: 0.84rem; color: rgba(202,240,248,0.4); display: flex; align-items: center; justify-content: center; gap: 8px; flex-shrink: 0; }

        /* empty chat */
        .chat-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; padding: 40px; text-align: center; }
        .chat-empty-icon { width: 80px; height: 80px; border-radius: 50%; background: rgba(0,180,216,0.06); border: 1px solid rgba(0,180,216,0.1); display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: rgba(0,180,216,0.2); margin: 0 auto 20px; }
        .chat-empty h5 { font-family: 'Cormorant Garamond', serif; font-size: 1.3rem; font-weight: 400; color: var(--white); margin-bottom: 8px; }
        .chat-empty p { font-size: 0.84rem; color: rgba(202,240,248,0.35); margin-bottom: 20px; }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 20px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 22px 26px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 18px 26px !important; }
        .modal-body { padding: 26px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.4rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        .field-group { margin-bottom: 18px; }
        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 8px; }
        .field-input, .field-select, .field-textarea { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 12px 15px; border-radius: 12px; outline: none; transition: all 0.3s; }
        .field-input::placeholder, .field-textarea::placeholder { color: rgba(202,240,248,0.2); }
        .field-input:focus, .field-select:focus, .field-textarea:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-select option { background: var(--ocean); }
        .field-textarea { resize: vertical; min-height: 100px; line-height: 1.6; }

        .btn-glass { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 10px 20px; border-radius: 50px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-glass:hover { background: rgba(0,180,216,0.15); color: var(--foam); }

        .btn-submit-modal { width: 100%; padding: 14px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.87rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 6px 22px rgba(0,180,216,0.3); display: flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-submit-modal:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(0,180,216,0.5); }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 40px; height: 40px; border-radius: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.9rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
            .messenger { height: calc(100vh - 220px); }
            .tl-panel { width: 260px; }
        }

        @media (max-width: 680px) {
            .messenger { flex-direction: column; height: auto; min-height: 600px; }
            .tl-panel { width: 100%; height: 260px; border-right: none; border-bottom: 1px solid var(--glass-border); }
            .chat-panel { height: 400px; }
        }

        @media (max-width: 480px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
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
        <a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="products.php"           class="nav-link"><i class="fas fa-droplet"></i> Products</a>
        <a href="order_history.php"      class="nav-link"><i class="fas fa-history"></i> Order History</a>
        <a href="order_tracking.php"     class="nav-link"><i class="fas fa-map-marker-alt"></i> Track Orders</a>
        <a href="recurring_orders.php"   class="nav-link"><i class="fas fa-redo"></i> Recurring Orders</a>
        <div class="nav-section-label">Account</div>
        <a href="support_tickets.php"    class="nav-link active"><i class="fas fa-headset"></i> Support</a>
        <a href="notifications.php"      class="nav-link">
            <i class="fas fa-bell"></i> Notifications
            <?php if($notifCount>0): ?><span class="notif-dot"><?php echo $notifCount>9?'9+':$notifCount;?></span><?php endif; ?>
        </a>
        <a href="profile.php"            class="nav-link"><i class="fas fa-user"></i> Profile</a>
        <div class="nav-section-label" style="margin-top:16px;"></div>
        <a href="../logout.php"          class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-greeting">
                <h4>Support Center</h4>
                <p>Get help from our support team</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo $notifCount>9?'9+':$notifCount;?></span><?php endif; ?>
            </a>

            <button class="btn-new-ticket" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                <i class="fas fa-plus"></i> New Ticket
            </button>

            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($user['profile_picture'])&&file_exists('../'.$user['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['profile_picture']);?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName,0,1));?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($userName);?></div>
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
    <?php if($flashMessage): ?>
    <div class="flash-alert <?php echo $flashType;?>">
        <i class="fas fa-<?php echo $flashType==='success'?'check-circle':'info-circle';?>"></i>
        <?php echo htmlspecialchars($flashMessage);?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon total"><i class="fas fa-ticket"></i></div>
            <div>
                <div class="stat-num"><?php echo $stats['total'];?></div>
                <div class="stat-lbl">Total</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon open"><i class="fas fa-circle-dot"></i></div>
            <div>
                <div class="stat-num" style="color:#4ade80;"><?php echo $stats['open'];?></div>
                <div class="stat-lbl">Open</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon prog"><i class="fas fa-spinner"></i></div>
            <div>
                <div class="stat-num" style="color:var(--gold);"><?php echo $stats['in_progress'];?></div>
                <div class="stat-lbl">In Progress</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon done"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="stat-num" style="color:#a78bfa;"><?php echo intval($stats['resolved'])+intval($stats['closed']);?></div>
                <div class="stat-lbl">Resolved</div>
            </div>
        </div>
    </div>

    <!-- Messenger -->
    <div class="messenger">

        <!-- Ticket List -->
        <div class="tl-panel">
            <div class="tl-header">
                <div class="tl-header-title">My Tickets</div>
                <div class="tl-header-count"><?php echo $tickets->num_rows;?> total</div>
            </div>

            <div class="tl-search" style="position:relative;">
                <i class="fas fa-search tl-search-icon"></i>
                <input type="text" id="ticketSearch" placeholder="Search tickets…">
            </div>

            <div class="tl-list" id="ticketList">
                <?php if($tickets->num_rows > 0):
                    $tickets->data_seek(0);
                    while($ticket = $tickets->fetch_assoc()):
                        $isSelected  = $selectedTicket && $selectedTicket['ticketID'] == $ticket['ticketID'];
                        $lastMsgArr  = !empty($ticket['conversation']) ? json_decode($ticket['conversation'], true) : [];
                        $lastMsgText = !empty($lastMsgArr) ? end($lastMsgArr)['message'] : $ticket['message'];
                        $lastMsgTime = !empty($ticket['last_reply_at']) ? $ticket['last_reply_at'] : $ticket['created_at'];

                        $statusBadge = match($ticket['status']) {
                            'Open'        => 'mb-open',
                            'In Progress' => 'mb-prog',
                            'Resolved'    => 'mb-res',
                            default       => 'mb-cls'
                        };
                        $prioBadge = match($ticket['priority']) {
                            'High'   => 'mb-high',
                            'Medium' => 'mb-med',
                            default  => 'mb-low'
                        };
                ?>
                <a href="?ticket=<?php echo $ticket['ticketID'];?>" class="tl-item <?php echo $isSelected?'selected':'';?>"
                   data-search="<?php echo strtolower(htmlspecialchars($ticket['subject'].' '.$ticket['category'].' '.$ticket['status']));?>">
                    <div class="tl-item-subject"><?php echo htmlspecialchars($ticket['subject']);?></div>
                    <div class="tl-item-preview"><?php echo htmlspecialchars(mb_strimwidth(strip_tags($lastMsgText), 0, 55, '…'));?></div>
                    <div class="tl-item-meta">
                        <div style="display:flex;gap:4px;">
                            <span class="mini-badge <?php echo $statusBadge;?>"><?php echo $ticket['status'];?></span>
                            <span class="mini-badge <?php echo $prioBadge;?>"><?php echo $ticket['priority'];?></span>
                        </div>
                        <div class="tl-item-time"><?php echo date('M j', strtotime($lastMsgTime));?></div>
                    </div>
                </a>
                <?php endwhile; else: ?>
                <div class="tl-empty">
                    <i class="fas fa-inbox"></i>
                    No tickets yet
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Panel -->
        <div class="chat-panel">
            <?php if($selectedTicket):
                $selStatusClass = str_replace(' ', '-', $selectedTicket['status']);
            ?>
                <!-- Chat Head -->
                <div class="chat-head">
                    <div style="min-width:0;">
                        <div class="chat-head-title"><?php echo htmlspecialchars($selectedTicket['subject']);?></div>
                        <div class="chat-head-sub">
                            Ticket #<?php echo $selectedTicket['ticketID'];?> ·
                            <?php echo htmlspecialchars($selectedTicket['category']);?> ·
                            <?php
                            $prioBadge2 = match($selectedTicket['priority']) { 'High'=>'mb-high','Medium'=>'mb-med',default=>'mb-low' };
                            echo '<span class="mini-badge '.$prioBadge2.'">'.$selectedTicket['priority'].' Priority</span>';
                            ?>
                        </div>
                    </div>
                    <div class="chat-head-right">
                        <span class="s-pill s-<?php echo $selStatusClass;?>"><?php echo $selectedTicket['status'];?></span>
                    </div>
                </div>

                <!-- Messages -->
                <div class="chat-body" id="chatBody">
                    <!-- Date: ticket creation -->
                    <div class="date-divider">
                        <span><?php echo date('F j, Y', strtotime($selectedTicket['created_at']));?></span>
                    </div>

                    <!-- Original message -->
                    <div class="bubble-wrap them">
                        <div class="bubble them">
                            <div class="bubble-sender">You (original request)</div>
                            <?php echo nl2br(htmlspecialchars($selectedTicket['message']));?>
                            <div class="bubble-time"><?php echo date('g:i A', strtotime($selectedTicket['created_at']));?></div>
                        </div>
                    </div>

                    <!-- Conversation -->
                    <?php
                    $prevDate = date('Y-m-d', strtotime($selectedTicket['created_at']));
                    foreach($conversation as $msg):
                        $msgDate = date('Y-m-d', strtotime($msg['timestamp']));
                        if($msgDate !== $prevDate):
                    ?>
                    <div class="date-divider">
                        <span><?php echo date('F j, Y', strtotime($msg['timestamp']));?></span>
                    </div>
                    <?php
                        $prevDate = $msgDate;
                        endif;
                        $bClass = match($msg['sender']) { 'customer'=>'me', 'system'=>'sys', default=>'them' };
                        $wClass = match($bClass) { 'me'=>'me', 'sys'=>'sys', default=>'them' };
                    ?>
                    <div class="bubble-wrap <?php echo $wClass;?>">
                        <div class="bubble <?php echo $bClass;?>">
                            <?php if($bClass==='them'): ?>
                                <div class="bubble-sender">Support Team</div>
                            <?php endif; ?>
                            <?php echo nl2br(htmlspecialchars($msg['message']));?>
                            <div class="bubble-time"><?php echo date('g:i A', strtotime($msg['timestamp']));?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Input -->
                <?php if($selectedTicket['status'] !== 'Closed'): ?>
                <div class="chat-input">
                    <form method="POST">
                        <input type="hidden" name="ticketID" value="<?php echo $selectedTicket['ticketID'];?>">
                        <textarea class="chat-textarea" name="message" placeholder="Type your message…" rows="1" required id="msgTextarea"></textarea>
                        <button type="submit" name="send_message" class="btn-send">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <div class="closed-notice">
                    <i class="fas fa-lock"></i>
                    This conversation is closed. Thank you for contacting us!
                </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Empty State -->
                <div class="chat-empty">
                    <div class="chat-empty-icon"><i class="fas fa-comments"></i></div>
                    <h5>Select a Ticket</h5>
                    <p>Choose a ticket from the list to view the conversation, or create a new one.</p>
                    <button class="btn-new-ticket" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                        <i class="fas fa-plus"></i> Create New Ticket
                    </button>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /.messenger -->

</main>

<!-- ── NEW TICKET MODAL ── -->
<div class="modal fade" id="newTicketModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-headset me-2" style="color:var(--aqua);"></i>Submit Support Ticket
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="field-group">
                        <label class="field-label">Subject</label>
                        <input type="text" class="field-input" name="subject" placeholder="Brief description of your issue" required>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Category</label>
                                <select class="field-select" name="category" required>
                                    <option value="">Select…</option>
                                    <option value="Order Issue">Order Issue</option>
                                    <option value="Delivery Problem">Delivery Problem</option>
                                    <option value="Payment Issue">Payment Issue</option>
                                    <option value="Product Quality">Product Quality</option>
                                    <option value="Account Problem">Account Problem</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Priority</label>
                                <select class="field-select" name="priority">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="field-group mb-0">
                        <label class="field-label">Describe Your Issue</label>
                        <textarea class="field-textarea" name="message" placeholder="Please provide as much detail as possible…" required></textarea>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_ticket" class="btn-submit-modal" style="width:auto;padding:11px 28px;">
                        <i class="fas fa-paper-plane"></i> Submit Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── SIDEBAR ──
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle  = document.getElementById('mobileToggle');
    function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }
    if(toggle)  toggle.addEventListener('click', openSidebar);
    if(overlay) overlay.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth < 992) closeSidebar(); }));

    // ── AUTO SCROLL CHAT ──
    const chatBody = document.getElementById('chatBody');
    if(chatBody) chatBody.scrollTop = chatBody.scrollHeight;

    // ── TEXTAREA AUTO RESIZE ──
    const msgTextarea = document.getElementById('msgTextarea');
    if(msgTextarea) {
        msgTextarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        // Send on Ctrl/Cmd+Enter
        msgTextarea.addEventListener('keydown', function(e) {
            if((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                this.closest('form').querySelector('button[type="submit"]').click();
            }
        });
    }

    // ── TICKET SEARCH ──
    const ticketSearch = document.getElementById('ticketSearch');
    if(ticketSearch) {
        ticketSearch.addEventListener('input', function() {
            const term = this.value.toLowerCase().trim();
            document.querySelectorAll('.tl-item').forEach(item => {
                const match = !term || item.dataset.search.includes(term);
                item.style.display = match ? 'block' : 'none';
            });
        });
    }
</script>
</body>
</html>