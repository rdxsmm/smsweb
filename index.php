<?php
// ============================================================
// AUTO DATABASE SETUP
// ============================================================

require_once 'config/database.php';

// ---- AUTO CREATE TABLES ----
function autoCreateTables($conn) {
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            profile_pic VARCHAR(255) DEFAULT NULL,
            is_admin TINYINT(1) DEFAULT 0,
            is_online TINYINT(1) DEFAULT 0,
            remember_token VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_email (email)
        )",
        "CREATE TABLE IF NOT EXISTS contacts (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            contact_id INT(11) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_contact (user_id, contact_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS messages (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            sender_id INT(11) NOT NULL,
            receiver_id INT(11) NOT NULL,
            message TEXT,
            msg_type ENUM('text','image','video','audio','file') DEFAULT 'text',
            file_path VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            is_deleted TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_sender (sender_id),
            INDEX idx_receiver (receiver_id),
            INDEX idx_created (created_at)
        )",
        "CREATE TABLE IF NOT EXISTS call_logs (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            caller_id INT(11) NOT NULL,
            receiver_id INT(11) NOT NULL,
            call_type ENUM('audio','video') DEFAULT 'audio',
            status ENUM('missed','answered','declined','ongoing','ended') DEFAULT 'missed',
            duration INT(11) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS blocked_users (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            blocked_user_id INT(11) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_block (user_id, blocked_user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    ];

    foreach ($tables as $sql) {
        if (!$conn->query($sql)) {
            error_log("Table creation error: " . $conn->error);
        }
    }
}

// ---- CHECK AND CREATE DEFAULT USERS ----
function createDefaultUsers($conn) {
    // Check if admin exists
    $check = $conn->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
    if ($check->num_rows == 0) {
        $username = 'admin';
        $email = 'admin@chatapp.com';
        $full_name = 'Admin User';
        $password = password_hash('password', PASSWORD_DEFAULT);
        $phone = '03000000000';
        $is_admin = 1;

        $stmt = $conn->prepare("INSERT INTO users (username, email, full_name, password, phone, is_admin) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $username, $email, $full_name, $password, $phone, $is_admin);
        $stmt->execute();
        $stmt->close();
    }

    // Check if demo user exists
    $check = $conn->query("SELECT id FROM users WHERE username = 'demo' LIMIT 1");
    if ($check->num_rows == 0) {
        $username = 'demo';
        $email = 'demo@chatapp.com';
        $full_name = 'Demo User';
        $password = password_hash('demo123', PASSWORD_DEFAULT);
        $phone = '03000000001';
        $is_admin = 0;

        $stmt = $conn->prepare("INSERT INTO users (username, email, full_name, password, phone, is_admin) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $username, $email, $full_name, $password, $phone, $is_admin);
        $stmt->execute();
        $stmt->close();

        $admin_id = $conn->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetch_assoc()['id'];
        $demo_id = $conn->query("SELECT id FROM users WHERE username = 'demo' LIMIT 1")->fetch_assoc()['id'];

        $conn->query("INSERT INTO contacts (user_id, contact_id) VALUES ($demo_id, $admin_id)");
        $conn->query("INSERT INTO contacts (user_id, contact_id) VALUES ($admin_id, $demo_id)");

        $conn->query("INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES 
            ($admin_id, $demo_id, 'Welcome to ChatApp! 😊', 1),
            ($admin_id, $demo_id, 'This is a demo message.', 1),
            ($demo_id, $admin_id, 'Thanks! This looks great!', 1)
        ");
    }
}

// ---- RUN SETUP ----
autoCreateTables($conn);
createDefaultUsers($conn);

// ============================================================
// MAIN DASHBOARD LOGIC
// ============================================================

if (!isLoggedIn()) redirect('login.php');

$uid = $_SESSION['uid'];
setOnline($conn, $uid, 1);

$me = $conn->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();

// Get contacts with last message
$contacts = $conn->query("
    SELECT u.id, u.username, u.full_name, u.is_online, u.phone, u.profile_pic,
        (SELECT message FROM messages
         WHERE (sender_id=u.id AND receiver_id=$uid)
            OR (sender_id=$uid AND receiver_id=u.id)
         ORDER BY created_at DESC LIMIT 1) AS last_msg,
        (SELECT created_at FROM messages
         WHERE (sender_id=u.id AND receiver_id=$uid)
            OR (sender_id=$uid AND receiver_id=u.id)
         ORDER BY created_at DESC LIMIT 1) AS last_time,
        (SELECT COUNT(*) FROM messages
         WHERE sender_id=u.id AND receiver_id=$uid AND is_read=0) AS unread
    FROM users u
    JOIN contacts c ON c.contact_id=u.id AND c.user_id=$uid
    WHERE u.id != $uid
    ORDER BY last_time DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>ChatApp - Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ============================================================
   THEME VARIABLES
   ============================================================ */
:root {
    --bg: #0a0a1a;
    --c1: #12122a;
    --c2: #1a1a3e;
    --p: #6C63FF;
    --p2: #5a52d5;
    --wht: #ffffff;
    --gr: #8892b0;
    --gl: #ccd6f6;
    --brd: rgba(255,255,255,0.08);
    --grn: #28a745;
    --red: #dc3545;
    --orange: #ff9800;
    --radius: 14px;
    --shadow: 0 8px 32px rgba(0,0,0,0.4);
    --transition: all 0.3s ease;
    --header-height: 60px;
    --footer-height: 60px;
    --sb: 15px;
}

/* Light Theme */
body.light-theme {
    --bg: #f0f2f5;
    --c1: #ffffff;
    --c2: #e4e6eb;
    --wht: #1a1a2e;
    --gr: #65676b;
    --gl: #1a1a2e;
    --brd: rgba(0,0,0,0.08);
    --shadow: 0 8px 32px rgba(0,0,0,0.1);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: -apple-system, 'Segoe UI', Tahoma, sans-serif;
    background: var(--bg);
    color: var(--wht);
    height: 100vh;
    overflow: hidden;
    -webkit-tap-highlight-color: transparent;
    transition: var(--transition);
}

/* ============================================================
   APP LAYOUT
   ============================================================ */
.app { display: flex; height: 100vh; background: var(--bg); }

/* ============================================================
   SCROLLBAR
   ============================================================ */
::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--p); border-radius: 4px; }

/* ============================================================
   SIDEBAR
   ============================================================ */
.sidebar {
    width: 340px;
    min-width: 340px;
    background: var(--c1);
    border-right: 1px solid var(--brd);
    display: flex;
    flex-direction: column;
    height: 100vh;
    position: relative;
    transition: transform 0.3s ease, background 0.3s ease;
    z-index: 10;
}

.sidebar.hide {
    transform: translateX(-100%);
}

/* Sidebar Top */
.sb-top {
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--brd);
    flex-shrink: 0;
    min-height: var(--header-height);
}

.sb-user {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    flex: 1;
    min-width: 0;
}

.av {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--p), #e94560);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    color: var(--wht);
    flex-shrink: 0;
    overflow: hidden;
    object-fit: cover;
    position: relative;
}

.av img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.av-lg { width: 48px; height: 48px; font-size: 20px; }
.av-sm { width: 32px; height: 32px; font-size: 14px; }
.av-wrap { position: relative; }

.sb-user b {
    display: block;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sb-user .online-txt {
    font-size: 11px;
    color: var(--grn);
}

.sb-btns {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

.sb-btns button,
.sb-btns a {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: none;
    background: rgba(255,255,255,0.06);
    color: var(--gr);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: var(--transition);
    text-decoration: none;
}

.sb-btns button:hover,
.sb-btns a:hover {
    background: rgba(108,99,255,0.2);
    color: var(--p);
}

/* Search Bar */
.sb-search {
    padding: 8px 16px;
    border-bottom: 1px solid var(--brd);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.sb-search i { color: var(--gr); font-size: 13px; }

.sb-search input {
    flex: 1;
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--brd);
    border-radius: 8px;
    padding: 7px 12px;
    color: var(--wht);
    font-size: 13px;
    outline: none;
    transition: var(--transition);
}

.sb-search input:focus {
    border-color: var(--p);
    background: rgba(255,255,255,0.08);
}

.sb-search input::placeholder { color: var(--gr); }

/* Contact List */
.contact-list {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 4px 0;
    -webkit-overflow-scrolling: touch;
}

.c-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    cursor: pointer;
    transition: var(--transition);
    border-left: 3px solid transparent;
    -webkit-tap-highlight-color: transparent;
}

.c-item:active {
    background: rgba(108,99,255,0.15);
}

.c-item:hover { background: rgba(255,255,255,0.04); }
.c-item.active {
    background: rgba(108,99,255,0.12);
    border-left-color: var(--p);
}

.c-item .av-wrap { flex-shrink: 0; }
.c-item .av { width: 44px; height: 44px; font-size: 18px; }

.c-info { flex: 1; min-width: 0; }

.c-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.c-row b {
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.c-time {
    font-size: 10px;
    color: var(--gr);
    flex-shrink: 0;
}

.c-row2 {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 2px;
}

.c-row2 span:first-child {
    font-size: 12px;
    color: var(--gr);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.badge {
    background: var(--p);
    color: var(--wht);
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 12px;
    flex-shrink: 0;
    font-weight: 600;
    min-width: 18px;
    text-align: center;
}

/* Dot Online */
.dot {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 12px;
    height: 12px;
    background: var(--grn);
    border-radius: 50%;
    border: 2px solid var(--c1);
}

.on-dot { color: var(--grn); font-size: 10px; }
.off-dot { color: var(--gr); font-size: 10px; }

/* Sidebar Footer */
.sb-footer {
    padding: 8px 16px;
    border-top: 1px solid var(--brd);
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: var(--gr);
    flex-shrink: 0;
}

.online-count {
    background: rgba(40,167,69,0.15);
    color: var(--grn);
    border-radius: 20px;
    font-size: 10px;
    padding: 1px 8px;
    border: 1px solid rgba(40,167,69,0.2);
    margin-left: 4px;
}

/* ============================================================
   MAIN AREA
   ============================================================ */
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: var(--bg);
    position: relative;
    min-width: 0;
    height: 100vh;
}

/* ── WELCOME SCREEN ── */
.welcome {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    text-align: center;
}

.welcome .welcome-icon {
    font-size: 72px;
    animation: float 3s ease-in-out infinite;
    display: inline-block;
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}

.welcome h3 { font-size: 22px; margin-top: 12px; }
.welcome p { color: var(--gr); font-size: 13px; margin-top: 6px; }

/* ── CHAT WINDOW ── */
.chat-win {
    flex: 1;
    display: none;
    flex-direction: column;
    background: var(--bg);
    position: relative;
    height: 100vh;
}

/* Chat Header */
.chat-top {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: var(--c1);
    border-bottom: 1px solid var(--brd);
    flex-shrink: 0;
    min-height: var(--header-height);
}

.back-btn {
    display: none;
    background: none;
    border: none;
    color: var(--gr);
    font-size: 20px;
    cursor: pointer;
    padding: 4px 8px;
}

.chat-top .av { cursor: pointer; width: 36px; height: 36px; font-size: 14px; }
.chat-top b { font-size: 14px; display: block; }
.chat-top #chatSt { font-size: 11px; color: var(--gr); }

.chat-top-info {
    flex: 1;
    cursor: pointer;
    min-width: 0;
}

.chat-top-info b {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-top-btns {
    display: flex;
    gap: 2px;
    flex-shrink: 0;
}

.chat-top-btns button {
    background: none;
    border: none;
    color: var(--gr);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    transition: var(--transition);
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chat-top-btns button:hover {
    background: rgba(255,255,255,0.06);
    color: var(--p);
}

/* Messages Area */
.msgs {
    flex: 1;
    overflow-y: auto;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    background: var(--bg);
    -webkit-overflow-scrolling: touch;
}

.no-msg {
    text-align: center;
    padding: 40px 20px;
    color: var(--gr);
}

.no-msg i {
    font-size: 40px;
    opacity: 0.2;
    display: block;
    margin-bottom: 10px;
}

/* Date Divider */
.dt-div {
    text-align: center;
    padding: 10px 0;
}

.dt-div span {
    background: var(--c1);
    padding: 4px 14px;
    border-radius: 12px;
    font-size: 11px;
    color: var(--gr);
}

/* Message Wrapper */
.mw {
    display: flex;
    flex-direction: column;
    max-width: 75%;
    margin-bottom: 4px;
}

.mw.mine { align-self: flex-end; }
.mw.their { align-self: flex-start; }

.mb {
    padding: 8px 12px;
    border-radius: 14px;
    position: relative;
    word-wrap: break-word;
}

.mb.sent {
    background: var(--p);
    border-bottom-right-radius: 4px;
}

.mb.recv {
    background: var(--c2);
    border-bottom-left-radius: 4px;
}

.mb p {
    font-size: 13px;
    line-height: 1.5;
    margin: 0;
    word-break: break-word;
}

.mt {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    font-size: 9px;
    color: rgba(255,255,255,0.5);
    margin-top: 4px;
}

.mw.mine .mt { color: rgba(255,255,255,0.6); }
.mw.their .mt { color: rgba(255,255,255,0.4); }
.mt .rd { color: var(--grn); }

/* Media in Messages */
.cimg {
    max-width: 220px;
    max-height: 300px;
    border-radius: 8px;
    cursor: pointer;
    display: block;
    margin: 4px 0;
}

.cvid { max-width: 220px; max-height: 200px; border-radius: 8px; }
.caud { width: 180px; height: 32px; }

/* Message Input Area */
.msg-input {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    padding: 8px 12px;
    background: var(--c1);
    border-top: 1px solid var(--brd);
    flex-shrink: 0;
    position: relative;
    min-height: var(--footer-height);
}

.msg-input button {
    background: none;
    border: none;
    color: var(--gr);
    cursor: pointer;
    font-size: 18px;
    padding: 8px;
    border-radius: 50%;
    transition: var(--transition);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
}

.msg-input button:active {
    background: rgba(108,99,255,0.2);
}

.msg-input button:hover {
    color: var(--p);
    background: rgba(108,99,255,0.1);
}

.msg-input textarea {
    flex: 1;
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--brd);
    border-radius: 20px;
    padding: 10px 14px;
    color: var(--wht);
    font-size: 14px;
    outline: none;
    resize: none;
    font-family: inherit;
    min-height: 40px;
    max-height: 100px;
    transition: var(--transition);
    line-height: 1.4;
}

.msg-input textarea:focus {
    border-color: var(--p);
    background: rgba(255,255,255,0.08);
}

.msg-input textarea::placeholder { color: var(--gr); }

.send-btn {
    background: var(--p) !important;
    color: var(--wht) !important;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.send-btn:active {
    transform: scale(0.9);
}

.send-btn:hover {
    background: var(--p2) !important;
}

/* Image Upload Preview */
.image-preview {
    display: none;
    position: relative;
    padding: 8px 12px;
    background: var(--c2);
    border-top: 1px solid var(--brd);
}

.image-preview.show {
    display: flex;
    align-items: center;
    gap: 10px;
}

.image-preview img {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 8px;
}

.image-preview .preview-info {
    flex: 1;
    font-size: 12px;
    color: var(--gr);
}

.image-preview .remove-btn {
    background: none;
    border: none;
    color: var(--red);
    font-size: 18px;
    cursor: pointer;
}

/* ============================================================
   MODALS
   ============================================================ */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

.modal-box {
    background: var(--c1);
    border: 1px solid var(--brd);
    border-radius: var(--radius);
    max-width: 460px;
    width: 92%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow);
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--brd);
    position: sticky;
    top: 0;
    background: var(--c1);
    z-index: 2;
}

.modal-head h3 {
    font-size: 16px;
    font-weight: 600;
}

.modal-head h3 i {
    color: var(--p);
    margin-right: 8px;
}

.modal-head button {
    background: none;
    border: none;
    color: var(--gr);
    font-size: 20px;
    cursor: pointer;
    padding: 4px;
}

.modal-head button:hover { color: var(--wht); }
.modal-body { padding: 18px; }

/* Profile Modal */
.profile-modal-body { padding: 0; }

.profile-cover {
    height: 80px;
    background: linear-gradient(135deg, var(--p), #e94560);
    border-radius: var(--radius) var(--radius) 0 0;
    position: relative;
}

.profile-pic-edit {
    position: absolute;
    bottom: -25px;
    left: 50%;
    transform: translateX(-50%);
    cursor: pointer;
}

.profile-pic-edit .av-lg {
    border: 4px solid var(--c1);
    cursor: pointer;
}

.profile-pic-edit .edit-icon {
    position: absolute;
    bottom: 0;
    right: 0;
    background: var(--p);
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    border: 2px solid var(--c1);
}

.profile-info-wrap { padding: 30px 18px 18px; }

.profile-av-big {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--p), #e94560);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 700;
    color: var(--wht);
    border: 4px solid var(--c1);
    margin: 0 auto 10px;
    overflow: hidden;
}

.profile-av-big img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-name { font-size: 18px; font-weight: 700; text-align: center; }
.profile-uname { color: var(--p); font-size: 13px; text-align: center; }

.profile-details {
    border-top: 1px solid var(--brd);
    padding-top: 12px;
    margin-top: 12px;
}

.profile-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
    font-size: 13px;
    color: var(--gl);
}

.profile-row i { color: var(--p); width: 16px; }
.profile-row span { color: var(--gr); }

/* Call Modal */
.call-modal {
    text-align: center;
    padding: 30px;
}

.call-av {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--p), #e94560);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 700;
    margin: 0 auto 12px;
    overflow: hidden;
}

.call-av img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.call-btns {
    display: flex;
    justify-content: center;
    gap: 16px;
    margin-top: 20px;
}

.call-btns button {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    border: none;
    font-size: 20px;
    cursor: pointer;
    transition: var(--transition);
}

.call-btns button:hover {
    transform: scale(1.1);
}

.call-end-btn {
    background: var(--red);
    color: var(--wht);
}

.call-accept-btn {
    background: var(--grn);
    color: var(--wht);
}

.call-btns button:not(.call-end-btn):not(.call-accept-btn) {
    background: rgba(255,255,255,0.1);
    color: var(--wht);
}

/* Toast */
.toast {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--c1);
    color: var(--wht);
    padding: 10px 20px;
    border-radius: 10px;
    border: 1px solid var(--brd);
    box-shadow: var(--shadow);
    font-size: 13px;
    z-index: 9999;
    animation: toastAnim 0.3s ease;
    max-width: 90%;
    text-align: center;
}

@keyframes toastAnim {
    from { opacity: 0; transform: translateX(-50%) translateY(20px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
}

/* Notification Popup */
.notification-popup {
    position: fixed;
    top: 10px;
    right: 10px;
    left: 10px;
    background: var(--c1);
    border: 1px solid var(--p);
    border-radius: 12px;
    padding: 12px 16px;
    z-index: 9998;
    box-shadow: var(--shadow);
    animation: slideInRight 0.3s ease;
    cursor: pointer;
    max-width: 380px;
    margin: 0 auto;
}

@keyframes slideInRight {
    from { opacity: 0; transform: translateX(50px); }
    to { opacity: 1; transform: translateX(0); }
}

.notification-popup .notif-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--p);
}

.notification-popup .notif-msg {
    font-size: 12px;
    color: var(--gr);
    margin-top: 4px;
}

/* Call Notification */
.call-notification {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: var(--c1);
    border: 2px solid var(--p);
    border-radius: 16px;
    padding: 30px;
    z-index: 9997;
    text-align: center;
    min-width: 280px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.8);
    animation: slideUp 0.3s ease;
}

.call-notification .call-av {
    width: 70px;
    height: 70px;
    margin: 0 auto 12px;
}

.call-notification h3 { font-size: 18px; }
.call-notification p { color: var(--gr); font-size: 13px; margin-top: 4px; }

.call-notification .call-btns {
    justify-content: center;
    gap: 20px;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */

@media (max-width: 480px) {
    .sidebar {
        width: 100%;
        min-width: 100%;
        position: absolute;
        left: 0;
        top: 0;
        height: 100vh;
        z-index: 20;
        transform: translateX(0);
    }

    .sidebar.hide {
        transform: translateX(-100%);
    }

    .back-btn { display: block !important; }
    .mw { max-width: 85%; }
    .cimg, .cvid { max-width: 160px; max-height: 160px; }
    
    .msg-input { padding: 6px 8px; gap: 4px; min-height: 50px; }
    .msg-input textarea { font-size: 14px; padding: 8px 12px; min-height: 36px; }
    .msg-input button { width: 36px; height: 36px; font-size: 16px; padding: 6px; }
    .send-btn { width: 36px; height: 36px; }
    
    .modal-box { width: 95%; margin: 10px; }
    .call-notification { min-width: 90%; padding: 20px; }
}

@media (min-width: 481px) and (max-width: 768px) {
    .sidebar {
        width: 280px;
        min-width: 280px;
        position: absolute;
        left: 0;
        top: 0;
        height: 100vh;
        z-index: 20;
    }
    
    .sidebar.hide {
        transform: translateX(-100%);
    }
    
    .back-btn { display: block !important; }
    .mw { max-width: 80%; }
}

@media (min-width: 769px) {
    .sidebar { position: relative; }
    .sidebar.hide { transform: none; margin-left: -340px; min-width: 0; }
    .back-btn { display: none !important; }
}

/* Touch-friendly */
button, .c-item, .sr-item button, .modal-btn, .send-btn, .sb-btns button, .sb-btns a {
    touch-action: manipulation;
}

/* Safe area */
@supports (padding: max(0px)) {
    .sb-top, .chat-top, .sb-footer, .msg-input {
        padding-left: max(12px, env(safe-area-inset-left));
        padding-right: max(12px, env(safe-area-inset-right));
    }
    .sb-top {
        padding-top: max(12px, env(safe-area-inset-top));
    }
    .sb-footer, .msg-input {
        padding-bottom: max(8px, env(safe-area-inset-bottom));
    }
}
</style>
</head>
<body>

<!-- ============================================================
     SIDEBAR
============================================================ -->
<div class="sidebar" id="sidebar">

    <div class="sb-top">
        <div class="sb-user" onclick="openModal('profileModal')">
            <div class="av av-lg" id="myProfilePic">
                <?php if (!empty($me['profile_pic'])): ?>
                    <img src="<?= htmlspecialchars($me['profile_pic']) ?>" alt="Profile">
                <?php else: ?>
                    <?= strtoupper(substr($me['full_name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div>
                <b><?= htmlspecialchars($me['full_name']) ?></b>
                <span class="online-txt">
                    ● Online
                    <span class="online-count" id="onlineCount">0</span>
                </span>
            </div>
        </div>
        <div class="sb-btns">
            <button onclick="toggleTheme()" title="Toggle Theme">
                <i class="fas fa-moon" id="themeIcon"></i>
            </button>
            <button onclick="openModal('addModal')" title="Add Contact">
                <i class="fas fa-user-plus"></i>
            </button>
            <button onclick="openModal('profileModal')" title="My Profile">
                <i class="fas fa-user-circle"></i>
            </button>
            <a href="logout.php" title="Logout" onclick="return confirm('Are you sure?')">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>

    <div class="sb-search">
        <i class="fas fa-search"></i>
        <input type="text" id="srch" placeholder="Search contacts..." oninput="filterContacts(this.value)">
    </div>

    <div class="contact-list" id="contactList">
        <?php if ($contacts && $contacts->num_rows > 0): ?>
            <?php while ($c = $contacts->fetch_assoc()): ?>
            <?php
            $tStr = '';
            if ($c['last_time']) {
                $ts   = strtotime($c['last_time']);
                $diff = time() - $ts;
                if ($diff < 86400) $tStr = date('h:i A', $ts);
                elseif ($diff < 604800) $tStr = date('D', $ts);
                else $tStr = date('d/m/Y', $ts);
            }
            ?>
            <div class="c-item" id="ci_<?= $c['id'] ?>" onclick="openChat(<?= $c['id'] ?>, '<?= htmlspecialchars($c['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($c['full_name'], ENT_QUOTES) ?>', <?= $c['is_online'] ?>, '<?= htmlspecialchars($c['profile_pic'] ?? '') ?>')">
                <div class="av-wrap">
                    <div class="av">
                        <?php if (!empty($c['profile_pic'])): ?>
                            <img src="<?= htmlspecialchars($c['profile_pic']) ?>" alt="Profile">
                        <?php else: ?>
                            <?= strtoupper(substr($c['full_name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($c['is_online']): ?>
                        <span class="dot" id="dot_<?= $c['id'] ?>"></span>
                    <?php endif; ?>
                </div>
                <div class="c-info">
                    <div class="c-row">
                        <b><?= htmlspecialchars($c['full_name']) ?></b>
                        <span class="c-time" id="ct_<?= $c['id'] ?>"><?= $tStr ?></span>
                    </div>
                    <div class="c-row2">
                        <span id="lm_<?= $c['id'] ?>">
                            <?= $c['last_msg'] ? htmlspecialchars(substr($c['last_msg'], 0, 32)) . '...' : '@' . $c['username'] ?>
                        </span>
                        <?php if ($c['unread'] > 0): ?>
                            <span class="badge" id="ub_<?= $c['id'] ?>"><?= $c['unread'] ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-contacts" style="text-align:center;padding:40px 20px">
                <i class="fas fa-user-friends" style="font-size:48px;color:var(--p);opacity:0.3"></i>
                <p style="color:var(--gr);font-size:13px;margin:12px 0">No contacts yet<br>Share your username with friends!</p>
                <button onclick="openModal('shareModal')" style="background:var(--p);border:none;color:var(--wht);padding:8px 18px;border-radius:8px;cursor:pointer;font-size:13px">
                    <i class="fas fa-share-alt"></i> Share Now
                </button>
            </div>
        <?php endif; ?>
    </div>

    <div class="sb-footer">
        <span><i class="fas fa-shield-alt" style="color:var(--p)"></i> End-to-end</span>
        <span id="connStatus"><i class="fas fa-circle" style="color:var(--grn);font-size:8px"></i> Connected</span>
    </div>
</div>


<!-- ============================================================
     MAIN AREA
============================================================ -->
<div class="main" id="main">

    <div class="welcome" id="welcome">
        <span class="welcome-icon">💬</span>
        <h3>Welcome!</h3>
        <p style="color:var(--gr);font-size:13px;margin-top:6px">
            <?= htmlspecialchars($me['full_name']) ?>, select a contact to start chatting
        </p>
    </div>

    <div class="chat-win" id="chatWin">

        <div class="chat-top" id="chatTop">
            <button class="back-btn" onclick="closeChat()"><i class="fas fa-arrow-left"></i></button>
            <div class="av" id="chatAv" onclick="openChatUserProfile()"></div>
            <div class="chat-top-info" onclick="openChatUserProfile()">
                <b id="chatName"></b>
                <span id="chatSt"></span>
            </div>
            <div class="chat-top-btns">
                <button onclick="startCall('audio')" title="Audio Call"><i class="fas fa-phone"></i></button>
                <button onclick="startCall('video')" title="Video Call"><i class="fas fa-video"></i></button>
                <button onclick="shareUser()" title="Share User"><i class="fas fa-share-alt"></i></button>
            </div>
        </div>

        <div class="msgs" id="msgs">
            <div class="no-msg"><i class="fas fa-spinner fa-spin"></i><p>Loading messages...</p></div>
        </div>

        <!-- Image Preview -->
        <div class="image-preview" id="imagePreview">
            <img id="previewImg" src="" alt="Preview">
            <div class="preview-info">
                <p>Image ready to send</p>
                <span id="previewSize"></span>
            </div>
            <button class="remove-btn" onclick="cancelImage()"><i class="fas fa-times"></i></button>
            <button onclick="sendImage()" style="background:var(--p);border:none;color:var(--wht);padding:6px 14px;border-radius:8px;cursor:pointer;font-size:12px">
                <i class="fas fa-paper-plane"></i> Send
            </button>
        </div>

        <div class="msg-input">
            <button onclick="document.getElementById('fileInput').click()" title="Attach Image">
                <i class="fas fa-image"></i>
            </button>
            <input type="file" id="fileInput" style="display:none" accept="image/*" onchange="previewImage(this)">
            
            <textarea id="msgBox" placeholder="Type a message..." rows="1" onkeydown="handleKey(event)" oninput="handleInput(this)" enterkeyhint="send"></textarea>

            <button class="send-btn" onclick="sendMsg()" id="sendBtn"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>
</div>


<!-- ============================================================
     MODALS
============================================================ -->

<!-- Add Contact Modal -->
<div class="modal" id="addModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="fas fa-user-plus"></i> Find Contacts</h3>
            <button onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="srch-row">
                <input type="text" id="srchUname" placeholder="Search by username..." oninput="searchUser(this.value)">
            </div>
            <div id="srchRes">
                <div style="text-align:center;color:var(--gr);padding:20px;font-size:13px">
                    <i class="fas fa-search" style="font-size:28px;opacity:0.2;display:block;margin-bottom:8px"></i>
                    Type username to search
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Profile Modal -->
<div class="modal" id="profileModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="fas fa-user-circle"></i> My Profile</h3>
            <button onclick="closeModal('profileModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="profile-modal-body">
            <div class="profile-cover">
                <div class="profile-pic-edit">
                    <div class="av av-lg" id="profilePicDisplay" onclick="document.getElementById('profilePicInput').click()">
                        <?php if (!empty($me['profile_pic'])): ?>
                            <img src="<?= htmlspecialchars($me['profile_pic']) ?>" alt="Profile">
                        <?php else: ?>
                            <?= strtoupper(substr($me['full_name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="edit-icon"><i class="fas fa-camera"></i></div>
                    <input type="file" id="profilePicInput" style="display:none" accept="image/*" onchange="uploadProfilePic(this)">
                </div>
            </div>
            <div class="profile-info-wrap">
                <div class="profile-name" id="profileNameDisplay"><?= htmlspecialchars($me['full_name']) ?></div>
                <div class="profile-uname">@<?= htmlspecialchars($me['username']) ?></div>
                <div class="profile-details">
                    <div class="profile-row"><i class="fas fa-envelope"></i><span><?= htmlspecialchars($me['email']) ?></span></div>
                    <div class="profile-row"><i class="fas fa-phone"></i><span id="profilePhoneDisplay"><?= htmlspecialchars($me['phone'] ?? 'Not set') ?></span></div>
                </div>
                <div style="margin-top:14px;border-top:1px solid var(--brd);padding-top:14px">
                    <label style="font-size:12px;color:var(--gr);display:block;margin-bottom:6px"><i class="fas fa-user-edit"></i> Change Name</label>
                    <div style="display:flex;gap:8px">
                        <input type="text" id="editName" value="<?= htmlspecialchars($me['full_name']) ?>" class="modal-input" style="flex:1">
                        <button onclick="updateName()" class="modal-btn"><i class="fas fa-save"></i> Update</button>
                    </div>
                </div>
                <div style="margin-top:12px">
                    <label style="font-size:12px;color:var(--gr);display:block;margin-bottom:6px"><i class="fas fa-phone"></i> Change Phone</label>
                    <div style="display:flex;gap:8px">
                        <input type="tel" id="editPhone" value="<?= htmlspecialchars($me['phone'] ?? '') ?>" class="modal-input" style="flex:1">
                        <button onclick="updatePhone()" class="modal-btn"><i class="fas fa-save"></i> Update</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chat User Profile Modal -->
<div class="modal" id="chatUserModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="fas fa-user"></i> User Profile</h3>
            <button onclick="closeModal('chatUserModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="profile-modal-body">
            <div class="profile-cover" style="background:linear-gradient(135deg,#e94560,#6C63FF)"></div>
            <div class="profile-info-wrap">
                <div class="profile-av-big" id="cupAvatar"></div>
                <div class="profile-name" id="cupName"></div>
                <div class="profile-uname" id="cupUname"></div>
                <div class="profile-details">
                    <div class="profile-row"><i class="fas fa-circle"></i><span id="cupStatus"></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Call Modal -->
<div class="modal" id="callModal">
    <div class="modal-box call-modal">
        <div class="call-av" id="callAv"></div>
        <h3 id="callName"></h3>
        <p id="callStatus" style="color:var(--gr);font-size:13px">Calling...</p>
        <div class="call-btns">
            <button onclick="endCall()" class="call-end-btn"><i class="fas fa-phone-slash"></i></button>
        </div>
    </div>
</div>

<!-- Incoming Call Notification -->
<div class="call-notification" id="incomingCall" style="display:none">
    <div class="call-av" id="incomingAv"></div>
    <h3 id="incomingName"></h3>
    <p id="incomingType">Incoming Call...</p>
    <div class="call-btns">
        <button onclick="acceptCall()" class="call-accept-btn"><i class="fas fa-phone"></i></button>
        <button onclick="declineCall()" class="call-end-btn"><i class="fas fa-phone-slash"></i></button>
    </div>
</div>

<!-- ============================================================
     JAVASCRIPT
============================================================ -->
<script>
// ── GLOBAL STATE ──
var uid = <?= $uid ?>;
var myName = '<?= addslashes($me['full_name']) ?>';
var myUname = '<?= addslashes($me['username']) ?>';
var toId = null;
var toName = '';
var toUname = '';
var toPic = '';
var interval = null;
var lastMsgCount = 0;
var isDarkTheme = true;
var pendingImage = null;
var isCalling = false;

// ── THEME TOGGLE ──
function toggleTheme() {
    isDarkTheme = !isDarkTheme;
    document.body.classList.toggle('light-theme', !isDarkTheme);
    document.getElementById('themeIcon').className = isDarkTheme ? 'fas fa-moon' : 'fas fa-sun';
    localStorage.setItem('theme', isDarkTheme ? 'dark' : 'light');
}

// Load saved theme
if (localStorage.getItem('theme') === 'light') {
    document.body.classList.add('light-theme');
    document.getElementById('themeIcon').className = 'fas fa-sun';
    isDarkTheme = false;
}

// ── OPEN CHAT ──
function openChat(id, uname, name, online, pic) {
    toId = id;
    toName = name;
    toUname = uname;
    toPic = pic;

    var chatAv = document.getElementById('chatAv');
    if (pic) {
        chatAv.innerHTML = '<img src="' + pic + '" alt="Profile">';
    } else {
        chatAv.textContent = name[0].toUpperCase();
    }
    document.getElementById('chatName').textContent = name;
    updateChatStatus(online);

    document.getElementById('welcome').style.display = 'none';
    document.getElementById('chatWin').style.display = 'flex';

    if (window.innerWidth < 768) {
        document.getElementById('sidebar').classList.add('hide');
    }

    document.querySelectorAll('.c-item').forEach(el => el.classList.remove('active'));
    var ci = document.getElementById('ci_' + id);
    if (ci) ci.classList.add('active');

    document.getElementById('msgs').innerHTML = '<div class="no-msg"><i class="fas fa-spinner fa-spin"></i><p>Loading messages...</p></div>';
    document.getElementById('msgBox').value = '';
    document.getElementById('msgBox').style.height = 'auto';
    cancelImage();

    loadMsgs();
    clearInterval(interval);
    interval = setInterval(() => {
        loadMsgs();
        updateSidebarStatus();
    }, 3000);

    fetch('api/get_messages.php?to=' + id + '&read=1');

    var ub = document.getElementById('ub_' + id);
    if (ub) {
        ub.style.display = 'none';
        ub.textContent = '0';
    }
    updateUnreadCount();

    setTimeout(() => document.getElementById('msgBox').focus(), 300);
}

function updateChatStatus(online) {
    var st = document.getElementById('chatSt');
    st.innerHTML = online ? '<span class="on-dot">●</span> Online' : '<span class="off-dot">●</span> Offline';
}

function closeChat() {
    clearInterval(interval);
    document.getElementById('welcome').style.display = 'flex';
    document.getElementById('chatWin').style.display = 'none';
    document.getElementById('sidebar').classList.remove('hide');
    document.querySelectorAll('.c-item').forEach(el => el.classList.remove('active'));
    toId = null;
    document.title = 'ChatApp';
}

// ── LOAD MESSAGES ──
function loadMsgs() {
    if (!toId) return;

    fetch('api/get_messages.php?to=' + toId)
        .then(r => r.json())
        .then(data => {
            var box = document.getElementById('msgs');
            var atBt = box.scrollHeight - box.clientHeight <= box.scrollTop + 100;

            if (data.length > lastMsgCount) {
                var newMsgs = data.slice(lastMsgCount);
                newMsgs.forEach(m => {
                    if (m.sender_id != uid) {
                        playNotificationSound();
                        showNotification(toName || 'Someone', m.message || 'New message');
                        var ub = document.getElementById('ub_' + m.sender_id);
                        if (ub) {
                            var count = parseInt(ub.textContent) || 0;
                            ub.textContent = count + 1;
                            ub.style.display = '';
                        }
                        updateUnreadCount();
                    }
                });
            }
            lastMsgCount = data.length;

            var html = '';
            var lastD = '';

            if (data.length === 0) {
                html = `<div class="no-msg"><i class="fas fa-comment-dots" style="font-size:40px;opacity:0.15;display:block;margin-bottom:10px"></i><p>Send your first message!</p></div>`;
            } else {
                data.forEach(m => {
                    var d = new Date(m.created_at).toLocaleDateString('en-US', {day:'numeric', month:'long', year:'numeric'});
                    if (d !== lastD) {
                        html += `<div class="dt-div"><span>${d}</span></div>`;
                        lastD = d;
                    }

                    var mine = (m.sender_id == uid);
                    var t = new Date(m.created_at).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});
                    var body = buildMsgBody(m);

                    html += `
                    <div class="mw ${mine ? 'mine' : 'their'}" id="msg_${m.id}">
                        <div class="mb ${mine ? 'sent' : 'recv'}">
                            ${body}
                            <div class="mt">
                                <span>${t}</span>
                                ${mine ? `<span>${m.is_read ? '<i class="fas fa-check-double rd"></i>' : '<i class="fas fa-check"></i>'}</span>` : ''}
                            </div>
                        </div>
                    </div>`;
                });
            }

            box.innerHTML = html;
            if (atBt) scrollBottom();

            if (data.length > 0) {
                var last = data[data.length - 1];
                var lmEl = document.getElementById('lm_' + toId);
                if (lmEl && last.message) {
                    lmEl.textContent = last.message.substring(0, 32) + '...';
                }
                var ctEl = document.getElementById('ct_' + toId);
                if (ctEl && last.created_at) {
                    var ts = new Date(last.created_at);
                    var diff = (Date.now() - ts.getTime()) / 1000;
                    if (diff < 86400) ctEl.textContent = ts.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});
                    else if (diff < 604800) ctEl.textContent = ts.toLocaleDateString('en-US', {weekday:'short'});
                    else ctEl.textContent = ts.toLocaleDateString('en-US', {day:'numeric', month:'numeric', year:'numeric'});
                }
            }
        })
        .catch(() => {
            document.getElementById('connStatus').innerHTML = '<i class="fas fa-circle" style="color:var(--red);font-size:8px"></i> Disconnected';
        });
}

function buildMsgBody(m) {
    var type = m.msg_type || 'text';
    if (type === 'text') return `<p>${esc(m.message)}</p>`;
    else if (type === 'image') return `<img src="${m.file_path}" class="cimg" onclick="viewImg('${m.file_path}')" onerror="this.style.display='none'">`;
    else if (type === 'video') return `<video src="${m.file_path}" controls class="cvid"></video>`;
    else if (type === 'audio') return `<audio src="${m.file_path}" controls class="caud"></audio>`;
    else {
        var fname = m.file_path ? m.file_path.split('/').pop() : 'File';
        return `<a href="${m.file_path}" download class="cfile"><i class="fas fa-file" style="font-size:20px"></i><span>${esc(fname)}</span></a>`;
    }
}

// ── SEND MESSAGE ──
function sendMsg() {
    var txt = document.getElementById('msgBox').value.trim();
    if (!txt && !pendingImage) return;
    if (!toId) return;

    if (pendingImage) {
        sendImage();
        return;
    }

    document.getElementById('msgBox').value = '';
    document.getElementById('msgBox').style.height = 'auto';

    var payload = { to: toId, msg: txt, type: 'text' };

    fetch('api/send_message.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) { lastMsgCount = 0; loadMsgs(); }
        else toast('❌ Failed to send!');
    })
    .catch(() => toast('❌ Failed to send!'));
}

// ── SEND IMAGE ──
function sendImage() {
    if (!pendingImage || !toId) return;

    var fd = new FormData();
    fd.append('file', pendingImage);
    fd.append('to', toId);

    toast('📤 Uploading image...');

    fetch('api/upload_file.php', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                cancelImage();
                lastMsgCount = 0;
                loadMsgs();
                toast('✅ Image sent!');
            } else {
                toast('❌ ' + (d.msg || 'Upload failed!'));
            }
        })
        .catch(() => toast('❌ Upload failed!'));
}

// ── IMAGE PREVIEW ──
function previewImage(input) {
    var file = input.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
        toast('Please select an image file!');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        toast('Image size should be less than 5MB!');
        return;
    }

    pendingImage = file;
    var reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('previewImg').src = e.target.result;
        document.getElementById('previewSize').textContent = (file.size / 1024).toFixed(1) + ' KB';
        document.getElementById('imagePreview').classList.add('show');
        document.getElementById('msgBox').placeholder = 'Add a caption...';
    };
    reader.readAsDataURL(file);
    input.value = '';
}

function cancelImage() {
    pendingImage = null;
    document.getElementById('imagePreview').classList.remove('show');
    document.getElementById('msgBox').placeholder = 'Type a message...';
    document.getElementById('fileInput').value = '';
}

// ── PROFILE PICTURE UPLOAD ──
function uploadProfilePic(input) {
    var file = input.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
        toast('Please select an image file!');
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        toast('Image size should be less than 2MB!');
        return;
    }

    var fd = new FormData();
    fd.append('profile_pic', file);

    toast('📤 Uploading profile picture...');

    fetch('api/upload_profile_pic.php', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                toast('✅ Profile picture updated!');
                location.reload();
            } else {
                toast('❌ ' + (d.msg || 'Upload failed!'));
            }
        })
        .catch(() => toast('❌ Upload failed!'));
}

// ── UPDATE NAME ──
function updateName() {
    var newName = document.getElementById('editName').value.trim();
    if (!newName) { toast('Please enter a name!'); return; }

    fetch('api/update_profile.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({field: 'full_name', value: newName})
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            toast('✅ Name updated!');
            document.getElementById('profileNameDisplay').textContent = newName;
            document.querySelector('.sb-user b').textContent = newName;
            if (document.getElementById('chatName')) document.getElementById('chatName').textContent = newName;
            myName = newName;
            setTimeout(() => location.reload(), 1500);
        } else toast('❌ ' + (d.msg || 'Failed!'));
    });
}

function updatePhone() {
    var newPhone = document.getElementById('editPhone').value.trim();

    fetch('api/update_profile.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({field: 'phone', value: newPhone})
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            toast('✅ Phone updated!');
            document.getElementById('profilePhoneDisplay').textContent = newPhone || 'Not set';
            setTimeout(() => location.reload(), 1500);
        } else toast('❌ ' + (d.msg || 'Failed!'));
    });
}

// ── SEARCH USER ──
function searchUser(q) {
    q = q.replace('@', '').trim();
    if (q.length < 2) {
        document.getElementById('srchRes').innerHTML = `<div style="text-align:center;color:var(--gr);padding:20px;font-size:13px"><i class="fas fa-search" style="font-size:28px;opacity:0.2;display:block;margin-bottom:8px"></i>Type username to search</div>`;
        return;
    }

    document.getElementById('srchRes').innerHTML = '<div style="text-align:center;padding:16px;color:var(--gr);font-size:13px"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';

    fetch('search_user.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            var h = '';
            if (!data || data.length === 0) {
                h = `<div style="text-align:center;color:var(--gr);padding:20px;font-size:13px"><i class="fas fa-user-times" style="font-size:28px;opacity:0.2;display:block;margin-bottom:8px"></i>No users found</div>`;
            } else {
                data.forEach(u => {
                    h += `<div class="sr-item" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-bottom:1px solid var(--brd)">
                        <div class="av av-sm" style="width:36px;height:36px;font-size:14px;flex-shrink:0">${u.full_name ? u.full_name[0].toUpperCase() : '?'}</div>
                        <div style="flex:1"><b style="display:block;font-size:13px">${esc(u.full_name)}</b><span style="font-size:11px;color:var(--gr)">@${esc(u.username)}</span></div>
                        ${!u.is_contact ? `<button onclick="addContact(${u.id},'${esc(u.username)}','${esc(u.full_name)}')" style="background:var(--p);border:none;color:var(--wht);padding:4px 12px;border-radius:6px;cursor:pointer;font-size:12px"><i class="fas fa-plus"></i> Add</button>` : `<span style="color:var(--grn);font-size:12px"><i class="fas fa-check-circle"></i> Added</span>`}
                    </div>`;
                });
            }
            document.getElementById('srchRes').innerHTML = h;
        })
        .catch(() => toast('Error searching!'));
}

function addContact(id, uname, name) {
    fetch('api/add_contact.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({cid: id})
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            closeModal('addModal');
            toast('✅ Contact added!');
            location.reload();
        } else toast('❌ ' + (d.msg || 'Failed!'));
    });
}

// ── CALL SYSTEM ──
function startCall(type) {
    if (!toId || isCalling) return;
    isCalling = true;

    var callAv = document.getElementById('callAv');
    if (toPic) callAv.innerHTML = '<img src="' + toPic + '" alt="Profile">';
    else callAv.textContent = toName[0].toUpperCase();

    document.getElementById('callName').textContent = toName;
    document.getElementById('callStatus').textContent = type === 'video' ? 'Video call connecting...' : 'Audio call connecting...';
    openModal('callModal');

    // Simulate call connection
    setTimeout(() => {
        document.getElementById('callStatus').textContent = 'Connected';
        toast('🔊 Call connected with ' + toName);
        // Show incoming call notification to the other user
    }, 2000);

    // Auto end after 60 seconds
    setTimeout(() => {
        if (isCalling) {
            document.getElementById('callStatus').textContent = 'Call ended';
            setTimeout(() => { endCall(); }, 1000);
        }
    }, 60000);
}

function endCall() {
    isCalling = false;
    closeModal('callModal');
    toast('📞 Call ended');
}

// ── INCOMING CALL SIMULATION ──
function simulateIncomingCall() {
    // This would be triggered by server push in real implementation
    var users = document.querySelectorAll('.c-item');
    if (users.length > 0) {
        var randomUser = users[Math.floor(Math.random() * users.length)];
        var name = randomUser.querySelector('b')?.textContent || 'Unknown';
        var av = randomUser.querySelector('.av')?.innerHTML || name[0] || '?';
        
        document.getElementById('incomingAv').innerHTML = av;
        document.getElementById('incomingName').textContent = name;
        document.getElementById('incomingType').textContent = '📞 Incoming call...';
        document.getElementById('incomingCall').style.display = 'block';
        
        setTimeout(() => {
            document.getElementById('incomingCall').style.display = 'none';
        }, 10000);
    }
}

function acceptCall() {
    document.getElementById('incomingCall').style.display = 'none';
    toast('✅ Call accepted!');
    // Start call
    var name = document.getElementById('incomingName').textContent;
    document.getElementById('callName').textContent = name;
    document.getElementById('callAv').innerHTML = document.getElementById('incomingAv').innerHTML;
    document.getElementById('callStatus').textContent = 'Connected';
    openModal('callModal');
    isCalling = true;
    
    setTimeout(() => {
        if (isCalling) {
            document.getElementById('callStatus').textContent = 'Call ended';
            setTimeout(() => { endCall(); }, 1000);
        }
    }, 60000);
}

function declineCall() {
    document.getElementById('incomingCall').style.display = 'none';
    toast('📵 Call declined');
}

// Simulate incoming call every 30 seconds (for demo)
setInterval(() => {
    if (!isCalling && document.querySelector('.c-item')) {
        simulateIncomingCall();
    }
}, 30000);

// ── SHARE ──
function shareUser() {
    if (!toUname) return;
    var txt = 'Chat with @' + toUname + ' on ChatApp!';
    if (navigator.share) navigator.share({title: 'User Share', text: txt});
    else { navigator.clipboard.writeText(txt); toast('✅ User info copied!'); }
}

// ── KEYBOARD ──
function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
}

function handleInput(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 100) + 'px';
}

function scrollBottom() {
    var box = document.getElementById('msgs');
    if (box) box.scrollTop = box.scrollHeight;
}

// ── MODALS ──
function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});

// ── VIEW IMAGE ──
function viewImg(src) {
    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.92);z-index:2000;display:flex;align-items:center;justify-content:center;flex-direction:column';
    overlay.innerHTML = `
        <button onclick="this.parentElement.remove()" style="position:absolute;top:18px;right:18px;background:rgba(255,255,255,0.15);border:none;color:#fff;width:38px;height:38px;border-radius:50%;font-size:16px;cursor:pointer"><i class="fas fa-times"></i></button>
        <img src="${src}" style="max-width:90%;max-height:85vh;border-radius:10px;object-fit:contain">
    `;
    document.body.appendChild(overlay);
}

// ── NOTIFICATIONS ──
function showNotification(sender, message) {
    if (toId && document.getElementById('chatWin').style.display !== 'none') return;

    var notif = document.createElement('div');
    notif.className = 'notification-popup';
    notif.innerHTML = `<div class="notif-title"><i class="fas fa-comment" style="color:var(--p)"></i> ${esc(sender)}</div><div class="notif-msg">${esc(message)}</div>`;
    notif.onclick = function() {
        this.remove();
        document.querySelectorAll('.c-item').forEach(el => {
            if (el.textContent.includes(sender)) el.click();
        });
    };
    document.body.appendChild(notif);
    setTimeout(() => { if (notif.parentNode) notif.remove(); }, 5000);
}

function playNotificationSound() {
    try {
        var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        var osc = audioCtx.createOscillator();
        var gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.frequency.value = 800;
        osc.type = 'sine';
        gain.gain.value = 0.1;
        osc.start();
        setTimeout(() => osc.stop(), 200);
    } catch(e) {}
}

// ── TOAST ──
function toast(msg) {
    var existing = document.querySelector('.toast');
    if (existing) existing.remove();
    var t = document.createElement('div');
    t.className = 'toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { if (t.parentNode) t.remove(); }, 3000);
}

// ── ESCAPE ──
function esc(t) {
    if (!t) return '';
    return t.toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── UPDATE UNREAD COUNT ──
function updateUnreadCount() {
    var total = 0;
    document.querySelectorAll('.badge').forEach(b => {
        if (b.style.display !== 'none') total += parseInt(b.textContent) || 0;
    });
    document.title = total > 0 ? '(' + total + ') ChatApp' : 'ChatApp';
}

// ── FILTER CONTACTS ──
function filterContacts(q) {
    q = q.toLowerCase();
    var items = document.querySelectorAll('.c-item');
    items.forEach(el => {
        el.style.display = el.dataset.n.includes(q) ? 'flex' : 'none';
    });
}

// ── UPDATE ONLINE COUNT ──
function updateOnlineCount() {
    document.getElementById('onlineCount').textContent = document.querySelectorAll('.dot').length;
}
updateOnlineCount();

// ── PING ──
setInterval(() => {
    fetch('api/get_messages.php?ping=1')
        .then(() => document.getElementById('connStatus').innerHTML = '<i class="fas fa-circle" style="color:var(--grn);font-size:8px"></i> Connected')
        .catch(() => document.getElementById('connStatus').innerHTML = '<i class="fas fa-circle" style="color:var(--red);font-size:8px"></i> Disconnected');
}, 30000);

// ── AUTO FOCUS ──
document.addEventListener('keydown', function(e) {
    if (!toId) return;
    var box = document.getElementById('msgBox');
    if (document.activeElement !== box && !e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
        box.focus();
    }
});

// ── VISIBILITY ──
document.addEventListener('visibilitychange', function() {
    if (!document.hidden && toId) {
        lastMsgCount = 0;
        loadMsgs();
        fetch('api/get_messages.php?to=' + toId + '&read=1');
    }
});

// ── UPDATE UNREAD ──
setInterval(updateUnreadCount, 2000);

console.log('✅ ChatApp Dashboard loaded!');
console.log('👤 ' + myName + ' (@' + myUname + ')');
</script>

</body>
</html>
