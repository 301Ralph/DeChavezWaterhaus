<?php
include '../includes/connection.php';
session_start();

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$adminID   = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';
$admin     = $conn->query("SELECT * FROM customers WHERE userID = $adminID")->fetch_assoc();

$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType    = $_SESSION['flash_type']    ?? 'info';
if ($flashMessage) { unset($_SESSION['flash_message'], $_SESSION['flash_type']); }

// Handle send message
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $ticketID    = intval($_POST['ticketID']);
    $messageText = trim(htmlspecialchars($_POST['message']));
    $newStatus   = $_POST['status'] ?? null;

    if (!empty($messageText)) {
        $ts = $conn->prepare("SELECT conversation FROM support_tickets WHERE ticketID=?");
        $ts->bind_param("i", $ticketID); $ts->execute();
        $td = $ts->get_result()->fetch_assoc(); $ts->close();

        $conversation = [];
        if (!empty($td['conversation'])) $conversation = json_decode($td['conversation'], true) ?? [];

        $conversation[] = ['sender'=>'admin','message'=>$messageText,'timestamp'=>date('Y-m-d H:i:s')];

        if ($newStatus === 'Closed') {
            $conversation[] = [
                'sender'=>'system',
                'message'=>'This conversation has been closed. Thank you for contacting De Chavez Waterhaus Support. If you have further questions, please open a new ticket.',
                'timestamp'=>date('Y-m-d H:i:s')
            ];
        }

        $us = $conn->prepare("UPDATE support_tickets SET conversation=?, status=COALESCE(?,status), last_reply_at=NOW() WHERE ticketID=?");
        $statusToSet   = $newStatus ?: null;
        $conversationJson = json_encode($conversation);
        $us->bind_param("ssi", $conversationJson, $statusToSet, $ticketID);
        $us->execute(); $us->close();

        $tk = $conn->query("SELECT userID FROM support_tickets WHERE ticketID=$ticketID")->fetch_assoc();
        if ($tk) {
            $nm = "New reply on your support ticket #$ticketID";
            $ns = $conn->prepare("INSERT INTO notifications (userID,message,type) VALUES (?,?,'Support')");
            $ns->bind_param("is", $tk['userID'], $nm); $ns->execute(); $ns->close();
        }

        $_SESSION['flash_message'] = "Message sent!";
        $_SESSION['flash_type']    = "success";
    }

    header("Location: support_tickets.php".(isset($_GET['ticket'])?"?ticket=".$_GET['ticket']:""));
    exit();
}

// Fetch all tickets
$tickets = $conn->query("
    SELECT t.*, CONCAT(c.Firstname,' ',c.Lastname) as customer_name, c.Email, c.profile_picture
    FROM support_tickets t
    JOIN customers c ON t.userID = c.userID
    ORDER BY COALESCE(t.last_reply_at, t.created_at) DESC
");

// Selected ticket
$selectedTicket = null;
$conversation   = [];
if (isset($_GET['ticket'])) {
    $selID  = intval($_GET['ticket']);
    $selStmt = $conn->prepare("
        SELECT t.*, CONCAT(c.Firstname,' ',c.Lastname) as customer_name, c.Email, c.profile_picture
        FROM support_tickets t JOIN customers c ON t.userID=c.userID
        WHERE t.ticketID=?
    ");
    $selStmt->bind_param("i", $selID); $selStmt->execute();
    $selectedTicket = $selStmt->get_result()->fetch_assoc();
    $selStmt->close();
    if ($selectedTicket && !empty($selectedTicket['conversation']))
        $conversation = json_decode($selectedTicket['conversation'], true) ?? [];
}

$notifCount = $conn->query("SELECT COUNT(*) as u FROM notifications WHERE userID=$adminID AND is_read=0")->fetch_assoc()['u'] ?? 0;

// Ticket counts
$openCount = 0; $inProgCount = 0; $resolvedCount = 0;
$tickets->data_seek(0);
while($t = $tickets->fetch_assoc()) {
    if($t['status']==='Open') $openCount++;
    elseif($t['status']==='In Progress') $inProgCount++;
    elseif($t['status']==='Resolved') $resolvedCount++;
}
$tickets->data_seek(0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Support Center • Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="icon" href="../images/logo.jpg" type="image/x-icon">
<style>
:root {
    --deep:#020d18; --abyss:#030f1e; --ocean:#041e35; --navy:#0a2d4a;
    --teal:#0077b6; --aqua:#00b4d8; --cyan:#48cae4;
    --foam:#caf0f8; --white:#f0f9ff; --gold:#f4c842;
    --green:#4ade80; --red:#f87171; --violet:#a78bfa;
    --glass:rgba(0,180,216,0.08); --glass-border:rgba(72,202,228,0.18);
    --sidebar-w:260px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--deep);color:var(--white);min-height:100vh;overflow:hidden;}

/* ── SIDEBAR ── */
.sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-w);background:var(--abyss);border-right:1px solid var(--glass-border);z-index:1000;display:flex;flex-direction:column;transition:transform .3s ease;}
.sidebar-logo{padding:22px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--glass-border);flex-shrink:0;}
.sidebar-logo img{width:38px;height:38px;border-radius:50%;object-fit:cover;border:1px solid rgba(0,180,216,.35);}
.sidebar-logo-text{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:500;color:var(--white);line-height:1.2;}
.sidebar-logo-sub{font-size:.65rem;color:rgba(202,240,248,.3);letter-spacing:.1em;text-transform:uppercase;}
.sidebar-nav{flex:1;overflow-y:auto;padding:12px 10px;scrollbar-width:thin;scrollbar-color:rgba(72,202,228,.15) transparent;}
.sidebar-nav::-webkit-scrollbar{width:3px;}
.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(72,202,228,.15);border-radius:2px;}
.nav-section-label{font-size:.58rem;letter-spacing:.2em;text-transform:uppercase;color:rgba(202,240,248,.22);padding:14px 10px 5px;}
.nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:9px;color:rgba(202,240,248,.48)!important;text-decoration:none;font-size:.84rem;font-weight:500;transition:all .22s ease;margin-bottom:1px;position:relative;}
.nav-link i{width:16px;text-align:center;font-size:.85rem;color:rgba(0,180,216,.38);transition:color .22s;}
.nav-link:hover{background:var(--glass);color:var(--foam)!important;}
.nav-link:hover i{color:var(--aqua);}
.nav-link.active{background:linear-gradient(135deg,rgba(0,119,182,.25),rgba(0,180,216,.12));border:1px solid rgba(0,180,216,.2);color:var(--aqua)!important;}
.nav-link.active i{color:var(--aqua);}
.nav-link.active::before{content:'';position:absolute;left:0;top:22%;bottom:22%;width:3px;background:var(--aqua);border-radius:0 3px 3px 0;}
.nav-link.danger{color:rgba(252,165,165,.6)!important;}
.nav-link.danger i{color:rgba(252,165,165,.5);}
.nav-link.danger:hover{background:rgba(248,113,113,.08);color:#fca5a5!important;}

/* ── MAIN LAYOUT ── */
.main-content{margin-left:var(--sidebar-w);height:100vh;display:flex;flex-direction:column;padding:20px 24px;}

/* ── TOPBAR ── */
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-shrink:0;}
.topbar-left h4{font-family:'Cormorant Garamond',serif;font-size:1.55rem;font-weight:400;color:var(--white);line-height:1.1;}
.topbar-left p{font-size:.78rem;color:rgba(202,240,248,.4);margin-top:2px;}
.topbar-right{display:flex;align-items:center;gap:10px;}
.topbar-btn{width:38px;height:38px;border-radius:50%;background:var(--glass);border:1px solid var(--glass-border);color:rgba(202,240,248,.6);display:flex;align-items:center;justify-content:center;font-size:.85rem;text-decoration:none;transition:all .3s;position:relative;}
.topbar-btn:hover{background:rgba(0,180,216,.15);border-color:var(--aqua);color:var(--aqua);}
.topbar-notif-badge{position:absolute;top:-3px;right:-3px;background:var(--gold);color:var(--deep);font-size:.52rem;font-weight:700;min-width:14px;height:14px;border-radius:50px;display:flex;align-items:center;justify-content:center;padding:0 2px;}
.avatar-btn{display:flex;align-items:center;gap:8px;background:var(--glass);border:1px solid var(--glass-border);border-radius:50px;padding:4px 12px 4px 4px;cursor:pointer;transition:all .3s;}
.avatar-btn:hover{border-color:rgba(0,180,216,.35);background:rgba(0,180,216,.1);}
.avatar-circle{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--teal),var(--aqua));color:var(--deep);font-weight:700;font-size:.78rem;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
.avatar-circle img{width:100%;height:100%;object-fit:cover;}
.avatar-name{font-size:.78rem;font-weight:500;color:var(--white);}
.avatar-role{font-size:.66rem;color:rgba(202,240,248,.4);}
.dropdown-menu{background:var(--ocean)!important;border:1px solid var(--glass-border)!important;border-radius:13px!important;padding:7px!important;box-shadow:0 18px 48px rgba(0,0,0,.5)!important;}
.dropdown-item{color:rgba(202,240,248,.65)!important;border-radius:7px!important;padding:8px 13px!important;font-size:.83rem!important;transition:all .2s!important;}
.dropdown-item:hover{background:var(--glass)!important;color:var(--aqua)!important;}
.dropdown-item.text-danger{color:rgba(252,165,165,.7)!important;}
.dropdown-divider{border-color:var(--glass-border)!important;margin:4px 0!important;}

/* ── FLASH ── */
.flash-bar{padding:10px 16px;border-radius:12px;font-size:.84rem;margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-shrink:0;}
.flash-success{background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);color:var(--green);}
.flash-error{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.25);color:var(--red);}

/* ── MESSENGER ── */
.messenger{display:flex;flex:1;border-radius:18px;overflow:hidden;border:1px solid var(--glass-border);min-height:0;}

/* Ticket List Panel */
.ticket-panel{width:300px;flex-shrink:0;background:var(--abyss);border-right:1px solid var(--glass-border);display:flex;flex-direction:column;}
.ticket-panel-head{padding:16px 18px;border-bottom:1px solid var(--glass-border);flex-shrink:0;}
.ticket-panel-title{font-family:'Cormorant Garamond',serif;font-size:1.05rem;font-weight:500;color:var(--white);}
.ticket-panel-sub{font-size:.72rem;color:rgba(202,240,248,.35);margin-top:2px;}

/* filter tabs */
.filter-tabs{display:flex;gap:4px;padding:10px 12px;border-bottom:1px solid rgba(72,202,228,.07);flex-shrink:0;}
.filter-tab{flex:1;text-align:center;padding:5px 4px;border-radius:8px;border:1px solid transparent;background:transparent;color:rgba(202,240,248,.35);font-family:'DM Sans',sans-serif;font-size:.7rem;font-weight:600;cursor:pointer;transition:all .22s;}
.filter-tab:hover{background:var(--glass);color:var(--foam);}
.filter-tab.active{background:linear-gradient(135deg,var(--teal),var(--aqua));color:var(--deep);border-color:transparent;}

/* search */
.ticket-search-wrap{padding:10px 12px;border-bottom:1px solid rgba(72,202,228,.07);flex-shrink:0;position:relative;}
.ticket-search{width:100%;background:rgba(4,30,53,.6);border:1px solid var(--glass-border);color:var(--white);border-radius:50px;padding:7px 12px 7px 32px;font-size:.8rem;font-family:'DM Sans',sans-serif;outline:none;transition:all .3s;}
.ticket-search::placeholder{color:rgba(202,240,248,.2);}
.ticket-search:focus{border-color:var(--aqua);background:rgba(0,180,216,.06);}
.ticket-search-icon{position:absolute;left:22px;top:50%;transform:translateY(-50%);color:rgba(0,180,216,.35);font-size:.72rem;}

.ticket-list{flex:1;overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(72,202,228,.1) transparent;}
.ticket-list::-webkit-scrollbar{width:3px;}
.ticket-list::-webkit-scrollbar-thumb{background:rgba(72,202,228,.1);border-radius:2px;}

.ticket-item{display:flex;align-items:flex-start;gap:10px;padding:13px 14px;border-bottom:1px solid rgba(72,202,228,.06);cursor:pointer;text-decoration:none;transition:all .2s;position:relative;}
.ticket-item:hover{background:rgba(0,180,216,.05);}
.ticket-item.active{background:linear-gradient(135deg,rgba(0,119,182,.2),rgba(0,180,216,.1));border-left:3px solid var(--aqua);}
.ticket-item.active .tk-name{color:var(--aqua);}

.tk-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid var(--glass-border);flex-shrink:0;}
.tk-initial{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--teal),var(--aqua));color:var(--deep);font-weight:700;font-size:.85rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.tk-body{flex:1;min-width:0;}
.tk-name{font-size:.84rem;font-weight:600;color:var(--white);margin-bottom:2px;}
.tk-preview{font-size:.73rem;color:rgba(202,240,248,.38);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:5px;}
.tk-time{font-size:.67rem;color:rgba(202,240,248,.28);}

.status-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-top:4px;}
.dot-Open{background:var(--gold);box-shadow:0 0 6px rgba(244,200,66,.5);}
.dot-In-Progress{background:var(--aqua);box-shadow:0 0 6px rgba(0,180,216,.5);}
.dot-Resolved{background:var(--green);box-shadow:0 0 6px rgba(74,222,128,.4);}
.dot-Closed{background:rgba(202,240,248,.2);}

.priority-chip{display:inline-flex;align-items:center;padding:2px 7px;border-radius:50px;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-left:4px;}
.p-High{background:rgba(248,113,113,.12);color:var(--red);border:1px solid rgba(248,113,113,.22);}
.p-Medium{background:rgba(244,200,66,.1);color:var(--gold);border:1px solid rgba(244,200,66,.22);}
.p-Low{background:rgba(0,180,216,.08);color:var(--aqua);border:1px solid rgba(0,180,216,.18);}

.ticket-empty{text-align:center;padding:40px 20px;color:rgba(202,240,248,.28);}
.ticket-empty i{font-size:2rem;display:block;margin-bottom:10px;color:rgba(0,180,216,.12);}
.ticket-empty p{font-size:.82rem;}

/* Chat Area */
.chat-area{flex:1;display:flex;flex-direction:column;background:var(--ocean);min-width:0;}

/* chat header */
.chat-header{padding:14px 20px;background:rgba(4,30,53,.8);border-bottom:1px solid var(--glass-border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.ch-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid rgba(0,180,216,.3);}
.ch-initial{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--teal),var(--aqua));color:var(--deep);font-weight:700;font-size:.95rem;display:flex;align-items:center;justify-content:center;}
.ch-name{font-weight:600;color:var(--white);font-size:.9rem;}
.ch-email{font-size:.73rem;color:rgba(202,240,248,.38);}

/* status pill in header */
.sh-pill{padding:4px 12px;border-radius:50px;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;}
.sh-Open{background:rgba(244,200,66,.12);color:var(--gold);border:1px solid rgba(244,200,66,.25);}
.sh-In-Progress{background:rgba(0,180,216,.1);color:var(--aqua);border:1px solid rgba(0,180,216,.25);}
.sh-Resolved{background:rgba(74,222,128,.1);color:var(--green);border:1px solid rgba(74,222,128,.25);}
.sh-Closed{background:rgba(148,163,184,.1);color:#94a3b8;border:1px solid rgba(148,163,184,.2);}

/* ticket meta */
.ticket-meta-bar{display:flex;align-items:center;gap:12px;padding:10px 20px;background:rgba(4,30,53,.4);border-bottom:1px solid rgba(72,202,228,.06);flex-shrink:0;font-size:.74rem;color:rgba(202,240,248,.38);}
.ticket-meta-bar span{display:flex;align-items:center;gap:5px;}
.ticket-meta-bar i{color:rgba(0,180,216,.4);font-size:.68rem;}

/* messages */
.chat-messages{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:10px;scrollbar-width:thin;scrollbar-color:rgba(72,202,228,.1) transparent;}
.chat-messages::-webkit-scrollbar{width:4px;}
.chat-messages::-webkit-scrollbar-thumb{background:rgba(72,202,228,.1);border-radius:2px;}

.msg-wrap{display:flex;align-items:flex-end;gap:8px;}
.msg-wrap.admin-wrap{flex-direction:row-reverse;}

.msg-av{width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0;}
.msg-av-init{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--teal),var(--aqua));color:var(--deep);font-weight:700;font-size:.72rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.msg-av-admin{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#1e3a5f,var(--teal));display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.msg-av-admin i{color:var(--aqua);font-size:.72rem;}

.bubble{max-width:68%;padding:11px 15px;border-radius:18px;position:relative;font-size:.85rem;line-height:1.55;}
.bubble-customer{background:rgba(10,45,74,.7);border:1px solid var(--glass-border);color:var(--foam);border-bottom-left-radius:4px;}
.bubble-admin{background:linear-gradient(135deg,#0077b6,#00b4d8);color:#fff;border-bottom-right-radius:4px;}
.bubble-system{background:rgba(167,139,250,.08);border:1px solid rgba(167,139,250,.2);color:var(--violet);border-radius:12px;font-size:.8rem;font-style:italic;text-align:center;max-width:80%;margin:0 auto;}
.msg-orig-label{font-size:.68rem;color:rgba(202,240,248,.35);margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;}
.msg-time{font-size:.67rem;opacity:.55;margin-top:5px;}
.admin-wrap .msg-time{text-align:right;}

/* input area */
.chat-input-area{padding:14px 18px;background:rgba(4,30,53,.8);border-top:1px solid var(--glass-border);flex-shrink:0;}
.chat-form{display:flex;gap:10px;align-items:flex-end;}
.msg-textarea{flex:1;background:rgba(4,30,53,.7);border:1px solid var(--glass-border);color:var(--white);font-family:'DM Sans',sans-serif;font-size:.88rem;padding:11px 16px;border-radius:14px;outline:none;resize:none;transition:all .3s;min-height:44px;max-height:100px;line-height:1.55;}
.msg-textarea::placeholder{color:rgba(202,240,248,.22);}
.msg-textarea:focus{border-color:var(--aqua);background:rgba(0,180,216,.06);box-shadow:0 0 0 3px rgba(0,180,216,.08);}
.status-select{background:rgba(4,30,53,.8);border:1px solid var(--glass-border);color:rgba(202,240,248,.65);font-family:'DM Sans',sans-serif;font-size:.78rem;padding:9px 12px;border-radius:12px;outline:none;width:130px;flex-shrink:0;}
.status-select:focus{border-color:var(--aqua);}
.status-select option{background:var(--ocean);}
.btn-send{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--teal),var(--aqua));border:none;color:var(--deep);font-size:.95rem;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .3s;flex-shrink:0;box-shadow:0 5px 14px rgba(0,180,216,.25);}
.btn-send:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(0,180,216,.4);}

/* closed bar */
.closed-bar{padding:14px 20px;background:rgba(4,30,53,.7);border-top:1px solid var(--glass-border);display:flex;align-items:center;justify-content:center;gap:8px;font-size:.84rem;color:rgba(202,240,248,.4);flex-shrink:0;}
.closed-bar i{color:rgba(148,163,184,.4);}

/* empty chat */
.chat-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:rgba(202,240,248,.25);}
.chat-empty i{font-size:3rem;margin-bottom:16px;color:rgba(0,180,216,.12);}
.chat-empty h5{font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:400;color:rgba(202,240,248,.4);margin-bottom:6px;}
.chat-empty p{font-size:.82rem;}

/* MOBILE */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(2,13,24,.7);z-index:999;backdrop-filter:blur(3px);}
.mobile-toggle{background:var(--glass);border:1px solid var(--glass-border);color:var(--aqua);width:36px;height:36px;border-radius:9px;display:none;align-items:center;justify-content:center;cursor:pointer;font-size:.85rem;}
@media(max-width:991px){
    .sidebar{transform:translateX(-100%);box-shadow:4px 0 40px rgba(0,0,0,.5);}
    .sidebar.show{transform:translateX(0);}
    .sidebar-overlay.show{display:block;}
    .main-content{margin-left:0;padding:14px 12px;}
    .mobile-toggle{display:flex;}
    .ticket-panel{width:260px;}
    body{overflow:auto;}
}
@media(max-width:640px){
    .ticket-panel{display:none;}
    .ticket-panel.show-mobile{display:flex;position:fixed;inset:0;z-index:998;width:100%;border-radius:0;}
}
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="">
        <div><div class="sidebar-logo-text">De Chavez Waterhaus</div><div class="sidebar-logo-sub">Admin Panel</div></div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="admin_dashboard.php"   class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="manage_products.php"   class="nav-link"><i class="fas fa-box"></i> Products</a>
        <a href="manage_orders.php"     class="nav-link"><i class="fas fa-shopping-cart"></i> Orders</a>
        <a href="manage_users.php"      class="nav-link"><i class="fas fa-users"></i> Users</a>
        <a href="manage_employees.php"  class="nav-link"><i class="fas fa-user-tie"></i> Employees</a>
        <div class="nav-section-label">Operations</div>
        <a href="attendance_management.php" class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payroll_management.php"    class="nav-link"><i class="fas fa-money-bill"></i> Payroll</a>
        <a href="generate_payslip.php"      class="nav-link"><i class="fas fa-file-pdf"></i> Generate Payslip</a>
        <a href="leave_management.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Manage Leave</a>
        <div class="nav-section-label">Support & Reports</div>
        <a href="support_tickets.php"   class="nav-link active"><i class="fas fa-headset"></i> Support Tickets</a>
        <a href="reports.php"           class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="nav-section-label" style="margin-top:14px;"></div>
        <a href="profile.php"           class="nav-link"><i class="fas fa-user"></i> My Profile</a>
        <a href="../logout.php"         class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN ── -->
<div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-left">
                <h4>Support Center</h4>
                <p><?php echo $tickets->num_rows;?> conversations · <?php echo $openCount;?> open · <?php echo $inProgCount;?> in progress</p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
            </a>
            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($admin['profile_picture'])&&file_exists('../'.$admin['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($admin['profile_picture']);?>" alt="">
                        <?php else: echo strtoupper(substr($adminName,0,1)); endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($adminName);?></div>
                        <div class="avatar-role">Administrator</div>
                    </div>
                    <i class="fas fa-chevron-down fa-xs ms-1" style="color:rgba(202,240,248,.3);"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Flash -->
    <?php if($flashMessage): ?>
    <div class="flash-bar flash-<?php echo $flashType==='success'?'success':'error';?>">
        <i class="fas fa-<?php echo $flashType==='success'?'check-circle':'exclamation-circle';?>"></i>
        <?php echo htmlspecialchars($flashMessage);?>
    </div>
    <?php endif; ?>

    <!-- Messenger -->
    <div class="messenger">

        <!-- Ticket List Panel -->
        <div class="ticket-panel" id="ticketPanel">
            <div class="ticket-panel-head">
                <div class="ticket-panel-title"><i class="fas fa-inbox me-2" style="color:var(--aqua);font-size:.9rem;"></i>All Tickets</div>
                <div class="ticket-panel-sub"><?php echo $tickets->num_rows;?> conversations</div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="filterTickets('all',this)">All</button>
                <button class="filter-tab" onclick="filterTickets('Open',this)">Open <span style="background:rgba(244,200,66,.15);color:var(--gold);padding:0 5px;border-radius:50px;font-size:.65rem;"><?php echo $openCount;?></span></button>
                <button class="filter-tab" onclick="filterTickets('In Progress',this)">Active</button>
                <button class="filter-tab" onclick="filterTickets('Resolved',this)">Done</button>
            </div>

            <!-- Search -->
            <div class="ticket-search-wrap">
                <i class="fas fa-search ticket-search-icon"></i>
                <input type="text" class="ticket-search" id="ticketSearch" placeholder="Search tickets…">
            </div>

            <!-- List -->
            <div class="ticket-list" id="ticketList">
                <?php if($tickets->num_rows > 0): ?>
                    <?php $tickets->data_seek(0); while($ticket = $tickets->fetch_assoc()):
                        $isActive  = $selectedTicket && $selectedTicket['ticketID'] == $ticket['ticketID'];
                        $lastConv  = !empty($ticket['conversation']) ? json_decode($ticket['conversation'], true) : [];
                        $lastText  = !empty($lastConv) ? end($lastConv)['message'] : $ticket['message'];
                        $lastTime  = !empty($ticket['last_reply_at']) ? $ticket['last_reply_at'] : $ticket['created_at'];
                        $dotClass  = 'dot-'.str_replace(' ','-',$ticket['status']);
                        $pClass    = 'p-'.($ticket['priority']??'Low');
                    ?>
                    <a href="?ticket=<?php echo $ticket['ticketID'];?>"
                       class="ticket-item <?php echo $isActive?'active':'';?>"
                       data-status="<?php echo $ticket['status'];?>"
                       data-search="<?php echo strtolower(htmlspecialchars($ticket['customer_name'].' '.$ticket['message']));?>">
                        <?php if(!empty($ticket['profile_picture'])&&file_exists('../'.$ticket['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($ticket['profile_picture']);?>" class="tk-avatar" alt="">
                        <?php else: ?>
                            <div class="tk-initial"><?php echo strtoupper(substr($ticket['customer_name'],0,1));?></div>
                        <?php endif; ?>
                        <div class="tk-body">
                            <div class="tk-name">
                                <?php echo htmlspecialchars($ticket['customer_name']);?>
                                <?php if(!empty($ticket['priority'])): ?>
                                    <span class="priority-chip <?php echo $pClass;?>"><?php echo $ticket['priority'];?></span>
                                <?php endif; ?>
                            </div>
                            <div class="tk-preview"><?php echo htmlspecialchars(substr($lastText,0,52));?>…</div>
                            <div class="tk-time"><?php echo date('M j, g:i A', strtotime($lastTime));?></div>
                        </div>
                        <div class="status-dot <?php echo $dotClass;?>"></div>
                    </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="ticket-empty">
                        <i class="fas fa-inbox"></i>
                        <p>No tickets yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Area -->
        <div class="chat-area">
            <?php if($selectedTicket):
                $sKey = str_replace(' ','-',$selectedTicket['status']);
            ?>

                <!-- Chat Header -->
                <div class="chat-header">
                    <div class="d-flex align-items-center gap-3">
                        <?php if(!empty($selectedTicket['profile_picture'])&&file_exists('../'.$selectedTicket['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($selectedTicket['profile_picture']);?>" class="ch-avatar" alt="">
                        <?php else: ?>
                            <div class="ch-initial"><?php echo strtoupper(substr($selectedTicket['customer_name'],0,1));?></div>
                        <?php endif; ?>
                        <div>
                            <div class="ch-name"><?php echo htmlspecialchars($selectedTicket['customer_name']);?></div>
                            <div class="ch-email"><?php echo htmlspecialchars($selectedTicket['Email']);?></div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-8" style="gap:8px;">
                        <span class="sh-pill sh-<?php echo $sKey;?>"><?php echo $selectedTicket['status'];?></span>
                    </div>
                </div>

                <!-- Meta Bar -->
                <div class="ticket-meta-bar">
                    <span><i class="fas fa-hashtag"></i> Ticket #<?php echo $selectedTicket['ticketID'];?></span>
                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($selectedTicket['category']??'General');?></span>
                    <?php if(!empty($selectedTicket['priority'])): ?>
                    <span><i class="fas fa-flag"></i> <?php echo $selectedTicket['priority'];?> Priority</span>
                    <?php endif; ?>
                    <span><i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($selectedTicket['created_at']));?></span>
                </div>

                <!-- Messages -->
                <div class="chat-messages" id="chatMessages">

                    <!-- Original request bubble -->
                    <div class="msg-wrap">
                        <?php if(!empty($selectedTicket['profile_picture'])&&file_exists('../'.$selectedTicket['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($selectedTicket['profile_picture']);?>" class="msg-av" alt="">
                        <?php else: ?>
                            <div class="msg-av-init"><?php echo strtoupper(substr($selectedTicket['customer_name'],0,1));?></div>
                        <?php endif; ?>
                        <div>
                            <div class="msg-orig-label">Original Request</div>
                            <div class="bubble bubble-customer">
                                <?php echo nl2br(htmlspecialchars($selectedTicket['message']));?>
                                <div class="msg-time"><?php echo date('M j, Y g:i A', strtotime($selectedTicket['created_at']));?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Conversation -->
                    <?php foreach($conversation as $msg):
                        $isAdmin  = $msg['sender'] === 'admin';
                        $isSystem = $msg['sender'] === 'system';
                    ?>
                        <?php if($isSystem): ?>
                        <div class="bubble bubble-system">
                            <i class="fas fa-lock me-1" style="font-size:.7rem;"></i>
                            <?php echo nl2br(htmlspecialchars($msg['message']));?>
                            <div class="msg-time" style="text-align:center;"><?php echo date('M j, g:i A', strtotime($msg['timestamp']));?></div>
                        </div>
                        <?php elseif($isAdmin): ?>
                        <div class="msg-wrap admin-wrap">
                            <div>
                                <div class="bubble bubble-admin">
                                    <?php echo nl2br(htmlspecialchars($msg['message']));?>
                                    <div class="msg-time"><?php echo date('M j, g:i A', strtotime($msg['timestamp']));?></div>
                                </div>
                            </div>
                            <div class="msg-av-admin"><i class="fas fa-shield-halved"></i></div>
                        </div>
                        <?php else: ?>
                        <div class="msg-wrap">
                            <?php if(!empty($selectedTicket['profile_picture'])&&file_exists('../'.$selectedTicket['profile_picture'])): ?>
                                <img src="../<?php echo htmlspecialchars($selectedTicket['profile_picture']);?>" class="msg-av" alt="">
                            <?php else: ?>
                                <div class="msg-av-init"><?php echo strtoupper(substr($selectedTicket['customer_name'],0,1));?></div>
                            <?php endif; ?>
                            <div class="bubble bubble-customer">
                                <?php echo nl2br(htmlspecialchars($msg['message']));?>
                                <div class="msg-time"><?php echo date('M j, g:i A', strtotime($msg['timestamp']));?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                </div><!-- end chat-messages -->

                <!-- Input Area -->
                <?php if($selectedTicket['status'] !== 'Closed'): ?>
                <div class="chat-input-area">
                    <form method="POST" class="chat-form">
                        <input type="hidden" name="ticketID" value="<?php echo $selectedTicket['ticketID'];?>">
                        <textarea name="message" class="msg-textarea" rows="1" placeholder="Type your reply…" required id="msgTextarea"></textarea>
                        <select name="status" class="status-select">
                            <option value="">Keep <?php echo $selectedTicket['status'];?></option>
                            <option value="In Progress">In Progress</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Closed">Close Ticket</option>
                        </select>
                        <button type="submit" name="send_message" class="btn-send" title="Send">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <div class="closed-bar">
                    <i class="fas fa-lock"></i>
                    This ticket is closed — no further messages can be sent.
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="chat-empty">
                    <i class="fas fa-comments"></i>
                    <h5>Select a Ticket</h5>
                    <p>Choose a conversation from the list to start chatting</p>
                </div>
            <?php endif; ?>
        </div><!-- end chat-area -->

    </div><!-- end messenger -->

</div><!-- end main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── SIDEBAR ──
const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('sidebarOverlay'),toggle=document.getElementById('mobileToggle');
function openSidebar(){sidebar.classList.add('show');overlay.classList.add('show');}
function closeSidebar(){sidebar.classList.remove('show');overlay.classList.remove('show');}
if(toggle)toggle.addEventListener('click',openSidebar);
if(overlay)overlay.addEventListener('click',closeSidebar);
sidebar.querySelectorAll('.nav-link').forEach(l=>l.addEventListener('click',()=>{if(window.innerWidth<992)closeSidebar();}));

// ── SCROLL TO BOTTOM ──
const chatMsgs=document.getElementById('chatMessages');
if(chatMsgs) chatMsgs.scrollTop=chatMsgs.scrollHeight;

// ── AUTO-RESIZE TEXTAREA ──
const ta=document.getElementById('msgTextarea');
if(ta){
    ta.addEventListener('input',function(){
        this.style.height='auto';
        this.style.height=Math.min(this.scrollHeight,100)+'px';
    });
    // Send on Ctrl+Enter
    ta.addEventListener('keydown',function(e){
        if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){
            e.preventDefault();
            this.closest('form').querySelector('[name="send_message"]').click();
        }
    });
}

// ── FILTER TABS ──
let currentFilter='all';
let currentSearch='';

function filterTickets(val,btn){
    document.querySelectorAll('.filter-tab').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    currentFilter=val;
    applyFilter();
}

function applyFilter(){
    document.querySelectorAll('.ticket-item').forEach(item=>{
        const matchStatus = currentFilter==='all'||item.dataset.status===currentFilter;
        const matchSearch = !currentSearch||item.dataset.search.includes(currentSearch);
        item.style.display=(matchStatus&&matchSearch)?'':'none';
    });
}

document.getElementById('ticketSearch')?.addEventListener('input',function(){
    currentSearch=this.value.toLowerCase().trim();
    applyFilter();
});
</script>
</body>
</html>