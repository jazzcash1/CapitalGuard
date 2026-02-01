<?php
session_start();
ob_start();

/**************************************************
 * CONFIGURATION SECTION
 **************************************************/
define('DB_HOST', 'localhost');
define('DB_NAME', 'betting_sim');
define('DB_USER', 'root'); // Change for production
define('DB_PASS', ''); // Change for production
define('ADMIN_CODE', '999999');
define('SITE_NAME', 'BetSim BD');
define('DEMO_BALANCE', 10000); // Initial demo balance for new users

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**************************************************
 * DATABASE CONNECTION & SETUP
 **************************************************/
function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $db;
}

function initDatabase() {
    $db = getDB();
    
    // Create users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        balance DECIMAL(10,2) DEFAULT 10000.00,
        role ENUM('user','admin') DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username (username)
    )");
    
    // Create matches table
    $db->exec("CREATE TABLE IF NOT EXISTS matches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sport VARCHAR(50) DEFAULT 'Football',
        team1 VARCHAR(100) NOT NULL,
        team2 VARCHAR(100) NOT NULL,
        start_time DATETIME NOT NULL,
        status ENUM('upcoming','live','finished','cancelled') DEFAULT 'upcoming',
        locked BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_start_time (start_time)
    )");
    
    // Create odds table
    $db->exec("CREATE TABLE IF NOT EXISTS odds (
        match_id INT PRIMARY KEY,
        home_odds DECIMAL(5,2) DEFAULT 1.80,
        draw_odds DECIMAL(5,2) DEFAULT 3.50,
        away_odds DECIMAL(5,2) DEFAULT 2.10,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
    )");
    
    // Create bets table
    $db->exec("CREATE TABLE IF NOT EXISTS bets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        match_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        selection ENUM('home','draw','away') NOT NULL,
        potential_win DECIMAL(10,2) NOT NULL,
        status ENUM('pending','won','lost','refunded') DEFAULT 'pending',
        placed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        settled_at TIMESTAMP NULL,
        INDEX idx_user_status (user_id, status),
        INDEX idx_match (match_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
    )");
    
    // Create wallet_requests table
    $db->exec("CREATE TABLE IF NOT EXISTS wallet_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type ENUM('deposit','withdraw') NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        method VARCHAR(50) NOT NULL,
        transaction_id VARCHAR(100),
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        INDEX idx_user_status (user_id, status),
        INDEX idx_type (type),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Create default admin if not exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (username, password, balance, role) 
                     VALUES ('admin', ?, 0, 'admin')")->execute([$hashedPassword]);
    }
}

// Initialize database on first run
initDatabase();

/**************************************************
 * HELPER FUNCTIONS
 **************************************************/
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function getUserBalance($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

function updateUserBalance($user_id, $amount) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    return $stmt->execute([$amount, $user_id]);
}

function formatMoney($amount) {
    return '৳ ' . number_format($amount, 2);
}

function getStatusBadge($status) {
    $badges = [
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'won' => 'success',
        'lost' => 'danger',
        'refunded' => 'info',
        'upcoming' => 'primary',
        'live' => 'danger',
        'finished' => 'secondary',
        'cancelled' => 'dark'
    ];
    $color = $badges[$status] ?? 'secondary';
    return "<span class='badge bg-$color'>" . ucfirst($status) . "</span>";
}

/**************************************************
 * REQUEST HANDLING
 **************************************************/
$action = $_GET['action'] ?? 'home';
$message = $_SESSION['message'] ?? '';
$message_type = $_SESSION['message_type'] ?? '';
unset($_SESSION['message'], $_SESSION['message_type']);

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    switch ($post_action) {
        case 'login':
            handleLogin();
            break;
        case 'register':
            handleRegister();
            break;
        case 'logout':
            session_destroy();
            header("Location: ?action=home");
            exit;
        case 'place_bet':
            handlePlaceBet();
            break;
        case 'create_match':
            handleCreateMatch();
            break;
        case 'update_odds':
            handleUpdateOdds();
            break;
        case 'settle_match':
            handleSettleMatch();
            break;
        case 'submit_wallet_request':
            handleWalletRequest();
            break;
        case 'process_wallet_request':
            handleProcessWalletRequest();
            break;
        case 'adjust_balance':
            handleAdjustBalance();
            break;
    }
}

/**************************************************
 * HANDLER FUNCTIONS
 **************************************************/
function handleLogin() {
    $db = getDB();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['balance'] = $user['balance'];
        
        if ($user['role'] === 'admin' && isset($_POST['admin_code']) && $_POST['admin_code'] === ADMIN_CODE) {
            $_SESSION['admin_access'] = true;
            header("Location: ?action=admin");
        } else {
            header("Location: ?action=dashboard");
        }
        exit;
    } else {
        $_SESSION['message'] = "Invalid username or password";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=login");
        exit;
    }
}

function handleRegister() {
    $db = getDB();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (strlen($username) < 3) {
        $_SESSION['message'] = "Username must be at least 3 characters";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=register");
        exit;
    }
    
    if (strlen($password) < 6) {
        $_SESSION['message'] = "Password must be at least 6 characters";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=register");
        exit;
    }
    
    if ($password !== $confirm_password) {
        $_SESSION['message'] = "Passwords do not match";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=register");
        exit;
    }
    
    // Check if username exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->rowCount() > 0) {
        $_SESSION['message'] = "Username already taken";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=register");
        exit;
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, balance) VALUES (?, ?, ?)");
    
    if ($stmt->execute([$username, $hashedPassword, DEMO_BALANCE])) {
        $_SESSION['message'] = "Registration successful! You can now login with your demo balance of ৳10,000";
        $_SESSION['message_type'] = "success";
        header("Location: ?action=login");
        exit;
    } else {
        $_SESSION['message'] = "Registration failed. Please try again.";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=register");
        exit;
    }
}

function handlePlaceBet() {
    if (!isLoggedIn()) {
        $_SESSION['message'] = "Please login to place bets";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=login");
        exit;
    }
    
    $db = getDB();
    $user_id = $_SESSION['user_id'];
    $match_id = intval($_POST['match_id']);
    $amount = floatval($_POST['amount']);
    $selection = $_POST['selection'];
    
    // Validate bet amount
    if ($amount < 10) {
        $_SESSION['message'] = "Minimum bet amount is ৳10";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=place_bet&match=$match_id");
        exit;
    }
    
    // Check user balance
    $balance = getUserBalance($user_id);
    if ($amount > $balance) {
        $_SESSION['message'] = "Insufficient balance";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=place_bet&match=$match_id");
        exit;
    }
    
    // Check match status
    $stmt = $db->prepare("SELECT m.*, o.home_odds, o.draw_odds, o.away_odds 
                         FROM matches m 
                         LEFT JOIN odds o ON m.id = o.match_id 
                         WHERE m.id = ? AND m.locked = FALSE AND m.status = 'upcoming'");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch();
    
    if (!$match) {
        $_SESSION['message'] = "Match not available for betting";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=matches");
        exit;
    }
    
    // Get odds for selection
    $odds_map = [
        'home' => $match['home_odds'],
        'draw' => $match['draw_odds'],
        'away' => $match['away_odds']
    ];
    
    $odds = $odds_map[$selection] ?? 1.0;
    $potential_win = $amount * $odds;
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Deduct amount from user balance
        updateUserBalance($user_id, -$amount);
        
        // Place bet
        $stmt = $db->prepare("INSERT INTO bets (user_id, match_id, amount, selection, potential_win) 
                             VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $match_id, $amount, $selection, $potential_win]);
        
        $db->commit();
        
        $_SESSION['balance'] = getUserBalance($user_id);
        $_SESSION['message'] = "Bet placed successfully! Potential win: " . formatMoney($potential_win);
        $_SESSION['message_type'] = "success";
        header("Location: ?action=my_bets");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['message'] = "Failed to place bet: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=place_bet&match=$match_id");
        exit;
    }
}

function handleCreateMatch() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    $team1 = trim($_POST['team1']);
    $team2 = trim($_POST['team2']);
    $sport = $_POST['sport'];
    $start_time = $_POST['start_time'];
    $home_odds = floatval($_POST['home_odds']);
    $draw_odds = floatval($_POST['draw_odds']);
    $away_odds = floatval($_POST['away_odds']);
    
    $db->beginTransaction();
    
    try {
        // Create match
        $stmt = $db->prepare("INSERT INTO matches (sport, team1, team2, start_time) VALUES (?, ?, ?, ?)");
        $stmt->execute([$sport, $team1, $team2, $start_time]);
        $match_id = $db->lastInsertId();
        
        // Create odds
        $stmt = $db->prepare("INSERT INTO odds (match_id, home_odds, draw_odds, away_odds) VALUES (?, ?, ?, ?)");
        $stmt->execute([$match_id, $home_odds, $draw_odds, $away_odds]);
        
        $db->commit();
        
        $_SESSION['message'] = "Match created successfully";
        $_SESSION['message_type'] = "success";
        header("Location: ?action=admin_matches");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['message'] = "Failed to create match: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=admin_create_match");
        exit;
    }
}

function handleUpdateOdds() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    $match_id = intval($_POST['match_id']);
    $home_odds = floatval($_POST['home_odds']);
    $draw_odds = floatval($_POST['draw_odds']);
    $away_odds = floatval($_POST['away_odds']);
    
    $stmt = $db->prepare("UPDATE odds SET home_odds = ?, draw_odds = ?, away_odds = ? WHERE match_id = ?");
    if ($stmt->execute([$home_odds, $draw_odds, $away_odds, $match_id])) {
        $_SESSION['message'] = "Odds updated successfully";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Failed to update odds";
        $_SESSION['message_type'] = "danger";
    }
    
    header("Location: ?action=admin_matches");
    exit;
}

function handleSettleMatch() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    $match_id = intval($_POST['match_id']);
    $result = $_POST['result']; // home, draw, away
    
    // Update match status
    $stmt = $db->prepare("UPDATE matches SET status = 'finished', locked = TRUE WHERE id = ?");
    $stmt->execute([$match_id]);
    
    // Get all pending bets for this match
    $stmt = $db->prepare("SELECT b.*, u.balance FROM bets b 
                         JOIN users u ON b.user_id = u.id 
                         WHERE b.match_id = ? AND b.status = 'pending'");
    $stmt->execute([$match_id]);
    $bets = $stmt->fetchAll();
    
    $db->beginTransaction();
    
    try {
        foreach ($bets as $bet) {
            $new_status = 'lost';
            $win_amount = 0;
            
            if ($result === 'refund') {
                $new_status = 'refunded';
                $win_amount = $bet['amount']; // Refund stake
            } elseif ($bet['selection'] === $result) {
                $new_status = 'won';
                $win_amount = $bet['potential_win'];
            }
            
            // Update bet status
            $stmt = $db->prepare("UPDATE bets SET status = ?, settled_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $bet['id']]);
            
            // Update user balance if won or refunded
            if ($new_status === 'won' || $new_status === 'refunded') {
                updateUserBalance($bet['user_id'], $win_amount);
            }
        }
        
        $db->commit();
        
        $_SESSION['message'] = "Match settled successfully";
        $_SESSION['message_type'] = "success";
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['message'] = "Failed to settle match: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
    }
    
    header("Location: ?action=admin_settle");
    exit;
}

function handleWalletRequest() {
    if (!isLoggedIn()) {
        header("Location: ?action=login");
        exit;
    }
    
    $db = getDB();
    $user_id = $_SESSION['user_id'];
    $type = $_POST['type'];
    $amount = floatval($_POST['amount']);
    $method = $_POST['method'];
    $transaction_id = trim($_POST['transaction_id'] ?? '');
    
    // Validation
    if ($amount <= 0) {
        $_SESSION['message'] = "Amount must be greater than 0";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=wallet");
        exit;
    }
    
    if ($type === 'deposit') {
        if ($amount < 100) {
            $_SESSION['message'] = "Minimum deposit is ৳100";
            $_SESSION['message_type'] = "danger";
            header("Location: ?action=wallet");
            exit;
        }
        if ($amount > 50000) {
            $_SESSION['message'] = "Maximum deposit is ৳50,000";
            $_SESSION['message_type'] = "danger";
            header("Location: ?action=wallet");
            exit;
        }
    } elseif ($type === 'withdraw') {
        $balance = getUserBalance($user_id);
        if ($amount < 500) {
            $_SESSION['message'] = "Minimum withdrawal is ৳500";
            $_SESSION['message_type'] = "danger";
            header("Location: ?action=wallet");
            exit;
        }
        if ($amount > $balance) {
            $_SESSION['message'] = "Insufficient balance for withdrawal";
            $_SESSION['message_type'] = "danger";
            header("Location: ?action=wallet");
            exit;
        }
        if ($amount > 20000) {
            $_SESSION['message'] = "Maximum withdrawal is ৳20,000";
            $_SESSION['message_type'] = "danger";
            header("Location: ?action=wallet");
            exit;
        }
        
        // Hold withdrawal amount
        updateUserBalance($user_id, -$amount);
        $_SESSION['balance'] = getUserBalance($user_id);
    }
    
    $stmt = $db->prepare("INSERT INTO wallet_requests (user_id, type, amount, method, transaction_id) 
                         VALUES (?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$user_id, $type, $amount, $method, $transaction_id])) {
        $_SESSION['message'] = ucfirst($type) . " request submitted successfully. Waiting for admin approval.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Failed to submit request";
        $_SESSION['message_type'] = "danger";
    }
    
    header("Location: ?action=wallet");
    exit;
}

function handleProcessWalletRequest() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    $request_id = intval($_POST['request_id']);
    $status = $_POST['status'];
    $admin_notes = trim($_POST['admin_notes'] ?? '');
    
    // Get request details
    $stmt = $db->prepare("SELECT * FROM wallet_requests WHERE id = ?");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        $_SESSION['message'] = "Request not found";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=admin_wallet");
        exit;
    }
    
    $db->beginTransaction();
    
    try {
        // Update request status
        $stmt = $db->prepare("UPDATE wallet_requests SET status = ?, admin_notes = ?, processed_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $admin_notes, $request_id]);
        
        // If approved, update user balance for deposits
        if ($status === 'approved' && $request['type'] === 'deposit') {
            updateUserBalance($request['user_id'], $request['amount']);
        }
        
        // If rejected and it was a withdrawal, refund the held amount
        if ($status === 'rejected' && $request['type'] === 'withdraw') {
            updateUserBalance($request['user_id'], $request['amount']);
        }
        
        $db->commit();
        
        $_SESSION['message'] = "Request " . $status . " successfully";
        $_SESSION['message_type'] = "success";
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['message'] = "Failed to process request: " . $e->getMessage();
        $_SESSION['message_type'] = "danger";
    }
    
    header("Location: ?action=admin_wallet");
    exit;
}

function handleAdjustBalance() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    $user_id = intval($_POST['user_id']);
    $amount = floatval($_POST['amount']);
    $reason = trim($_POST['reason']);
    
    if (updateUserBalance($user_id, $amount)) {
        $_SESSION['message'] = "Balance adjusted successfully";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Failed to adjust balance";
        $_SESSION['message_type'] = "danger";
    }
    
    header("Location: ?action=admin_users");
    exit;
}

/**************************************************
 * VIEW RENDERING
 **************************************************/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?> - Betting Simulation</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #28a745;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --dark-color: #343a40;
            --light-color: #f8f9fa;
            --bd-color: #008000;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-brand {
            font-weight: bold;
            color: var(--bd-color) !important;
        }
        
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: none;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--bd-color));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            font-weight: bold;
        }
        
        .btn-success {
            background: linear-gradient(to right, var(--primary-color), var(--bd-color));
            border: none;
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: bold;
        }
        
        .btn-success:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .balance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        .balance-amount {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .match-card {
            transition: transform 0.3s;
            cursor: pointer;
        }
        
        .match-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.2);
        }
        
        .odds-btn {
            min-width: 80px;
            margin: 5px;
            font-weight: bold;
        }
        
        .payment-method {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            background: white;
            transition: all 0.3s;
            cursor: pointer;
            border: 2px solid transparent;
        }
        
        .payment-method:hover {
            border-color: var(--primary-color);
            transform: scale(1.05);
        }
        
        .payment-icon {
            font-size: 3rem;
            margin-bottom: 10px;
        }
        
        .bkash { color: #e2136e; }
        .nagad { color: #f8a61c; }
        .rocket { color: #5d2d91; }
        .upay { color: #00a8ff; }
        .card-icon { color: #1a1a1a; }
        
        .admin-nav {
            background: var(--dark-color) !important;
        }
        
        .stat-card {
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card.users { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card.bets { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card.pending { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-card.revenue { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        
        .badge {
            font-size: 0.8em;
            padding: 5px 10px;
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table th {
            background-color: var(--primary-color);
            color: white;
            border: none;
        }
        
        .login-container {
            max-width: 400px;
            margin: 100px auto;
        }
        
        .footer {
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 20px 0;
            margin-top: 50px;
            border-radius: 15px 15px 0 0;
        }
        
        .sports-nav {
            background: white;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 20px;
        }
        
        .sports-nav .nav-link {
            color: var(--dark-color);
            font-weight: bold;
        }
        
        .sports-nav .nav-link.active {
            color: var(--bd-color);
            border-bottom: 3px solid var(--bd-color);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="?action=home">
                <i class="fas fa-chart-line"></i> <?= SITE_NAME ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= $action === 'home' ? 'active' : '' ?>" href="?action=home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $action === 'matches' ? 'active' : '' ?>" href="?action=matches">Matches</a>
                    </li>
                    <?php if (isLoggedIn()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $action === 'my_bets' ? 'active' : '' ?>" href="?action=my_bets">My Bets</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $action === 'wallet' ? 'active' : '' ?>" href="?action=wallet">Wallet</a>
                    </li>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($action, 'admin') === 0 ? 'active' : '' ?> admin-nav" href="?action=admin">
                            <i class="fas fa-crown"></i> Admin Panel
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <?php if (isLoggedIn()): ?>
                        <li class="nav-item">
                            <span class="nav-link">
                                <i class="fas fa-wallet"></i> 
                                <span id="currentBalance"><?= formatMoney($_SESSION['balance']) ?></span>
                            </span>
                        </li>
                        <li class="nav-item">
                            <span class="nav-link">
                                <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['username']) ?>
                            </span>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn btn-outline-light btn-sm" href="?action=logout">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link btn btn-success btn-sm me-2" href="?action=login">
                                <i class="fas fa-sign-in-alt"></i> Login
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn btn-outline-light btn-sm" href="?action=register">
                                <i class="fas fa-user-plus"></i> Register
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4">
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php
        // ROUTING - Main Content
        switch ($action) {
            case 'home':
                renderHome();
                break;
            case 'login':
                renderLogin();
                break;
            case 'register':
                renderRegister();
                break;
            case 'dashboard':
                renderDashboard();
                break;
            case 'matches':
                renderMatches();
                break;
            case 'place_bet':
                renderPlaceBet($_GET['match'] ?? 0);
                break;
            case 'my_bets':
                renderMyBets();
                break;
            case 'wallet':
                renderWallet();
                break;
            case 'admin':
                renderAdminDashboard();
                break;
            case 'admin_matches':
                renderAdminMatches();
                break;
            case 'admin_create_match':
                renderAdminCreateMatch();
                break;
            case 'admin_settle':
                renderAdminSettle();
                break;
            case 'admin_wallet':
                renderAdminWallet();
                break;
            case 'admin_users':
                renderAdminUsers();
                break;
            default:
                renderHome();
        }
        ?>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><i class="fas fa-chart-line"></i> <?= SITE_NAME ?></h5>
                    <p>This is a betting simulation website for entertainment purposes only. No real money is involved. Please gamble responsibly.</p>
                </div>
                <div class="col-md-6 text-end">
                    <h5>Payment Methods</h5>
                    <div class="d-flex justify-content-end gap-3">
                        <i class="fab fa-cc-visa fa-2x"></i>
                        <i class="fab fa-cc-mastercard fa-2x"></i>
                        <i class="fas fa-mobile-alt fa-2x"></i>
                    </div>
                    <p class="mt-3">© 2024 BetSim BD. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-update odds every 30 seconds
        setInterval(() => {
            document.querySelectorAll('.odds-value').forEach(el => {
                const current = parseFloat(el.textContent);
                const change = (Math.random() - 0.5) * 0.1; // +/- 5%
                const newValue = Math.max(1.1, Math.min(10, current + change));
                el.textContent = newValue.toFixed(2);
            });
        }, 30000);
        
        // Update balance display
        function updateBalanceDisplay(newBalance) {
            document.getElementById('currentBalance').textContent = '৳ ' + newBalance.toLocaleString('en-IN', {minimumFractionDigits: 2});
        }
        
        // Bet slip calculation
        function calculatePotentialWin() {
            const stake = parseFloat(document.getElementById('stake')?.value) || 0;
            const odds = parseFloat(document.getElementById('selectedOdds')?.value) || 0;
            const potentialWin = stake * odds;
            document.getElementById('potentialWin')?.textContent = potentialWin.toFixed(2);
            document.getElementById('totalReturn')?.textContent = (stake + potentialWin).toFixed(2);
        }
        
        // Select odds
        function selectOdds(matchId, selection, odds) {
            document.getElementById('selectedMatch').value = matchId;
            document.getElementById('selectedSelection').value = selection;
            document.getElementById('selectedOdds').value = odds;
            document.getElementById('oddsDisplay').textContent = odds;
            calculatePotentialWin();
        }
    </script>
</body>
</html>

<?php
/**************************************************
 * VIEW RENDER FUNCTIONS
 **************************************************/
function renderHome() {
    ?>
    <div class="row">
        <div class="col-lg-8 mx-auto text-center text-white mb-5">
            <h1 class="display-4 mb-4">Welcome to <?= SITE_NAME ?></h1>
            <p class="lead">Experience the thrill of sports betting in a safe, simulated environment. No real money involved!</p>
            <?php if (!isLoggedIn()): ?>
                <div class="mt-4">
                    <a href="?action=register" class="btn btn-success btn-lg me-3">
                        <i class="fas fa-user-plus"></i> Get Started with ৳10,000 Demo Balance
                    </a>
                    <a href="?action=login" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-futbol"></i> Football Betting
                </div>
                <div class="card-body">
                    <p>Bet on upcoming football matches with realistic odds. Premier League, La Liga, Champions League and more!</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-baseball-ball"></i> Cricket Betting
                </div>
                <div class="card-body">
                    <p>Experience cricket betting with IPL, BPL, International matches and tournaments.</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-shield-alt"></i> Safe & Secure
                </div>
                <div class="card-body">
                    <p>This is a simulation only. No real money involved. Perfect for learning and entertainment.</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-5">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-trophy"></i> Today's Featured Matches
                </div>
                <div class="card-body">
                    <?php
                    $db = getDB();
                    $stmt = $db->prepare("SELECT m.*, o.home_odds, o.draw_odds, o.away_odds 
                                         FROM matches m 
                                         LEFT JOIN odds o ON m.id = o.match_id 
                                         WHERE m.status = 'upcoming' 
                                         ORDER BY m.start_time ASC 
                                         LIMIT 3");
                    $stmt->execute();
                    $matches = $stmt->fetchAll();
                    
                    if ($matches):
                        foreach ($matches as $match):
                    ?>
                    <div class="row match-card mb-3 p-3 border rounded">
                        <div class="col-md-4">
                            <h5><?= htmlspecialchars($match['team1']) ?> vs <?= htmlspecialchars($match['team2']) ?></h5>
                            <small class="text-muted">
                                <i class="far fa-clock"></i> <?= date('d M, H:i', strtotime($match['start_time'])) ?>
                            </small>
                        </div>
                        <div class="col-md-8">
                            <div class="d-flex justify-content-between">
                                <button class="btn btn-outline-success odds-btn" onclick="selectOdds(<?= $match['id'] ?>, 'home', <?= $match['home_odds'] ?>)">
                                    1<br><span class="odds-value"><?= $match['home_odds'] ?></span>
                                </button>
                                <button class="btn btn-outline-primary odds-btn" onclick="selectOdds(<?= $match['id'] ?>, 'draw', <?= $match['draw_odds'] ?>)">
                                    X<br><span class="odds-value"><?= $match['draw_odds'] ?></span>
                                </button>
                                <button class="btn btn-outline-danger odds-btn" onclick="selectOdds(<?= $match['id'] ?>, 'away', <?= $match['away_odds'] ?>)">
                                    2<br><span class="odds-value"><?= $match['away_odds'] ?></span>
                                </button>
                                <?php if (isLoggedIn()): ?>
                                <a href="?action=place_bet&match=<?= $match['id'] ?>" class="btn btn-success align-self-center">
                                    <i class="fas fa-coins"></i> Bet Now
                                </a>
                                <?php else: ?>
                                <a href="?action=login" class="btn btn-outline-success align-self-center">
                                    Login to Bet
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <p class="text-center text-muted">No upcoming matches at the moment.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderLogin() {
    if (isLoggedIn()) {
        header("Location: ?action=dashboard");
        exit;
    }
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card login-container">
                <div class="card-header text-center">
                    <h4><i class="fas fa-sign-in-alt"></i> Login to <?= SITE_NAME ?></h4>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" name="remember">
                            <label class="form-check-label">Remember me</label>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-sign-in-alt"></i> Login
                            </button>
                            <a href="?action=register" class="btn btn-outline-primary">
                                Don't have an account? Register
                            </a>
                        </div>
                    </form>
                    
                    <hr>
                    
                    <div class="mt-3">
                        <h6>Admin Login:</h6>
                        <form method="POST">
                            <input type="hidden" name="action" value="login">
                            <div class="mb-2">
                                <input type="text" class="form-control" name="username" placeholder="Admin username" value="admin">
                            </div>
                            <div class="mb-2">
                                <input type="password" class="form-control" name="password" placeholder="Admin password" value="admin123">
                            </div>
                            <div class="mb-2">
                                <input type="text" class="form-control" name="admin_code" placeholder="Admin code (999999)" required>
                            </div>
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="fas fa-crown"></i> Admin Login
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderRegister() {
    if (isLoggedIn()) {
        header("Location: ?action=dashboard");
        exit;
    }
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card login-container">
                <div class="card-header text-center">
                    <h4><i class="fas fa-user-plus"></i> Register for <?= SITE_NAME ?></h4>
                </div>
                <div class="card-body">
                    <p class="text-center text-success mb-4">
                        <i class="fas fa-gift"></i> Get ৳10,000 demo balance upon registration!
                    </p>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="register">
                        
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" required minlength="3">
                            <div class="form-text">Minimum 3 characters</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" required minlength="6">
                            <div class="form-text">Minimum 6 characters</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password" required>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" required>
                            <label class="form-check-label">
                                I confirm this is a simulation and no real money is involved
                            </label>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-user-plus"></i> Create Account
                            </button>
                            <a href="?action=login" class="btn btn-outline-primary">
                                Already have an account? Login
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderDashboard() {
    if (!isLoggedIn()) {
        header("Location: ?action=login");
        exit;
    }
    ?>
    <div class="row">
        <div class="col-md-4">
            <div class="balance-card">
                <h5><i class="fas fa-wallet"></i> Your Balance</h5>
                <div class="balance-amount"><?= formatMoney($_SESSION['balance']) ?></div>
                <a href="?action=wallet" class="btn btn-light btn-sm">
                    <i class="fas fa-plus-circle"></i> Add Funds
                </a>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-history"></i> Recent Activity
                </div>
                <div class="card-body">
                    <?php
                    $db = getDB();
                    $stmt = $db->prepare("SELECT b.*, m.team1, m.team2 
                                         FROM bets b 
                                         JOIN matches m ON b.match_id = m.id 
                                         WHERE b.user_id = ? 
                                         ORDER BY b.placed_at DESC 
                                         LIMIT 5");
                    $stmt->execute([$_SESSION['user_id']]);
                    $bets = $stmt->fetchAll();
                    
                    if ($bets): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Match</th>
                                        <th>Amount</th>
                                        <th>Selection</th>
                                        <th>Potential Win</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bets as $bet): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($bet['team1']) ?> vs <?= htmlspecialchars($bet['team2']) ?></td>
                                        <td><?= formatMoney($bet['amount']) ?></td>
                                        <td><?= ucfirst($bet['selection']) ?></td>
                                        <td><?= formatMoney($bet['potential_win']) ?></td>
                                        <td><?= getStatusBadge($bet['status']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <a href="?action=my_bets" class="btn btn-outline-success btn-sm">View All Bets</a>
                    <?php else: ?>
                        <p class="text-center text-muted">No bets placed yet.</p>
                        <div class="text-center">
                            <a href="?action=matches" class="btn btn-success">
                                <i class="fas fa-coins"></i> Place Your First Bet
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-bolt"></i> Quick Bet - Today's Matches
                </div>
                <div class="card-body">
                    <?php
                    $db = getDB();
                    $stmt = $db->prepare("SELECT m.*, o.home_odds, o.draw_odds, o.away_odds 
                                         FROM matches m 
                                         LEFT JOIN odds o ON m.id = o.match_id 
                                         WHERE m.status = 'upcoming' AND m.locked = FALSE 
                                         ORDER BY m.start_time ASC 
                                         LIMIT 5");
                    $stmt->execute();
                    $matches = $stmt->fetchAll();
                    
                    if ($matches):
                        foreach ($matches as $match):
                    ?>
                    <div class="row match-card mb-3 p-3 border rounded">
                        <div class="col-md-4">
                            <h6><?= htmlspecialchars($match['team1']) ?> vs <?= htmlspecialchars($match['team2']) ?></h6>
                            <small class="text-muted">
                                <i class="fas fa-<?= strtolower($match['sport']) ?>"></i> <?= $match['sport'] ?>
                                • <?= date('H:i', strtotime($match['start_time'])) ?>
                            </small>
                        </div>
                        <div class="col-md-8">
                            <div class="d-flex justify-content-between">
                                <button class="btn btn-outline-success btn-sm odds-btn" onclick="selectOdds(<?= $match['id'] ?>, 'home', <?= $match['home_odds'] ?>)">
                                    1<br><span class="odds-value"><?= $match['home_odds'] ?></span>
                                </button>
                                <button class="btn btn-outline-primary btn-sm odds-btn" onclick="selectOdds(<?= $match['id'] ?>, 'draw', <?= $match['draw_odds'] ?>)">
                                    X<br><span class="odds-value"><?= $match['draw_odds'] ?></span>
                                </button>
                                <button class="btn btn-outline-danger btn-sm odds-btn" onclick="selectOdds(<?= $match['id'] ?>, 'away', <?= $match['away_odds'] ?>)">
                                    2<br><span class="odds-value"><?= $match['away_odds'] ?></span>
                                </button>
                                <a href="?action=place_bet&match=<?= $match['id'] ?>" class="btn btn-success btn-sm align-self-center">
                                    <i class="fas fa-coins"></i> Bet
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <p class="text-center text-muted">No upcoming matches at the moment.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderMatches() {
    $sport = $_GET['sport'] ?? 'Football';
    ?>
    <div class="row">
        <div class="col-12">
            <div class="sports-nav">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <a class="nav-link <?= $sport === 'Football' ? 'active' : '' ?>" href="?action=matches&sport=Football">
                            <i class="fas fa-futbol"></i> Football
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $sport === 'Cricket' ? 'active' : '' ?>" href="?action=matches&sport=Cricket">
                            <i class="fas fa-baseball-ball"></i> Cricket
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list"></i> <?= $sport ?> Matches
                </div>
                <div class="card-body">
                    <?php
                    $db = getDB();
                    $stmt = $db->prepare("SELECT m.*, o.home_odds, o.draw_odds, o.away_odds 
                                         FROM matches m 
                                         LEFT JOIN odds o ON m.id = o.match_id 
                                         WHERE m.sport = ? AND m.status = 'upcoming' AND m.locked = FALSE 
                                         ORDER BY m.start_time ASC");
                    $stmt->execute([$sport]);
                    $matches = $stmt->fetchAll();
                    
                    if ($matches):
                        foreach ($matches as $match):
                    ?>
                    <div class="row match-card mb-3 p-3 border rounded">
                        <div class="col-md-4">
                            <h5><?= htmlspecialchars($match['team1']) ?> vs <?= htmlspecialchars($match['team2']) ?></h5>
                            <small class="text-muted">
                                <i class="far fa-clock"></i> <?= date('d M Y, H:i', strtotime($match['start_time'])) ?>
                            </small>
                        </div>
                        <div class="col-md-8">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-center">
                                    <small>1</small>
                                    <div class="odds-btn" onclick="selectOdds(<?= $match['id'] ?>, 'home', <?= $match['home_odds'] ?>)">
                                        <span class="odds-value"><?= $match['home_odds'] ?></span>
                                    </div>
                                </div>
                                <div class="text-center">
                                    <small>X</small>
                                    <div class="odds-btn" onclick="selectOdds(<?= $match['id'] ?>, 'draw', <?= $match['draw_odds'] ?>)">
                                        <span class="odds-value"><?= $match['draw_odds'] ?></span>
                                    </div>
                                </div>
                                <div class="text-center">
                                    <small>2</small>
                                    <div class="odds-btn" onclick="selectOdds(<?= $match['id'] ?>, 'away', <?= $match['away_odds'] ?>)">
                                        <span class="odds-value"><?= $match['away_odds'] ?></span>
                                    </div>
                                </div>
                                <?php if (isLoggedIn()): ?>
                                <a href="?action=place_bet&match=<?= $match['id'] ?>" class="btn btn-success">
                                    <i class="fas fa-coins"></i> Place Bet
                                </a>
                                <?php else: ?>
                                <a href="?action=login" class="btn btn-outline-success">
                                    Login to Bet
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <p class="text-center text-muted">No <?= strtolower($sport) ?> matches available at the moment.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderPlaceBet($match_id) {
    if (!isLoggedIn()) {
        header("Location: ?action=login");
        exit;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT m.*, o.home_odds, o.draw_odds, o.away_odds 
                         FROM matches m 
                         LEFT JOIN odds o ON m.id = o.match_id 
                         WHERE m.id = ? AND m.locked = FALSE AND m.status = 'upcoming'");
    $stmt->execute([$match_id]);
    $match = $stmt->fetch();
    
    if (!$match) {
        $_SESSION['message'] = "Match not found or not available for betting";
        $_SESSION['message_type'] = "danger";
        header("Location: ?action=matches");
        exit;
    }
    ?>
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-coins"></i> Place Your Bet
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <h4><?= htmlspecialchars($match['team1']) ?> vs <?= htmlspecialchars($match['team2']) ?></h4>
                        <p class="text-muted">
                            <i class="far fa-clock"></i> <?= date('d M Y, H:i', strtotime($match['start_time'])) ?>
                            • <i class="fas fa-<?= strtolower($match['sport']) ?>"></i> <?= $match['sport'] ?>
                        </p>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="place_bet">
                        <input type="hidden" name="match_id" value="<?= $match_id ?>">
                        
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6><?= htmlspecialchars($match['team1']) ?></h6>
                                        <div class="odds-display"><?= $match['home_odds'] ?></div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="selection" value="home" id="home" required>
                                            <label class="form-check-label" for="home">Win</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6>Draw</h6>
                                        <div class="odds-display"><?= $match['draw_odds'] ?></div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="selection" value="draw" id="draw">
                                            <label class="form-check-label" for="draw">Draw</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <h6><?= htmlspecialchars($match['team2']) ?></h6>
                                        <div class="odds-display"><?= $match['away_odds'] ?></div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="selection" value="away" id="away">
                                            <label class="form-check-label" for="away">Win</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Stake Amount (৳)</label>
                            <input type="number" class="form-control" name="amount" id="stake" 
                                   min="10" max="<?= $_SESSION['balance'] ?>" step="10" 
                                   value="100" required oninput="calculatePotentialWin()">
                            <div class="form-text">
                                Minimum: ৳10 | Maximum: <?= formatMoney($_SESSION['balance']) ?>
                            </div>
                        </div>
                        
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p>Odds: <span id="oddsDisplay">-</span></p>
                                        <p>Potential Win: <strong id="potentialWin">0.00</strong></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p>Stake: <strong id="stakeDisplay">100.00</strong></p>
                                        <p>Total Return: <strong id="totalReturn">100.00</strong></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-check-circle"></i> Confirm Bet
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize bet calculator
        document.getElementById('stakeDisplay').textContent = document.getElementById('stake').value;
        calculatePotentialWin();
        
        // Update selection odds display
        document.querySelectorAll('input[name="selection"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const odds = this.value === 'home' ? <?= $match['home_odds'] ?> :
                           this.value === 'draw' ? <?= $match['draw_odds'] ?> :
                           <?= $match['away_odds'] ?>;
                document.getElementById('oddsDisplay').textContent = odds.toFixed(2);
                calculatePotentialWin();
            });
        });
    </script>
    <?php
}

function renderMyBets() {
    if (!isLoggedIn()) {
        header("Location: ?action=login");
        exit;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT b.*, m.team1, m.team2, m.status as match_status, 
                         CASE b.selection
                             WHEN 'home' THEN o.home_odds
                             WHEN 'draw' THEN o.draw_odds
                             WHEN 'away' THEN o.away_odds
                         END as odds
                         FROM bets b 
                         JOIN matches m ON b.match_id = m.id 
                         JOIN odds o ON m.id = o.match_id 
                         WHERE b.user_id = ? 
                         ORDER BY b.placed_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $bets = $stmt->fetchAll();
    ?>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-history"></i> My Bet History
                </div>
                <div class="card-body">
                    <?php if ($bets): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Match</th>
                                        <th>Selection</th>
                                        <th>Odds</th>
                                        <th>Stake</th>
                                        <th>Potential Win</th>
                                        <th>Status</th>
                                        <th>Match Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bets as $bet): ?>
                                    <tr>
                                        <td><?= date('d M H:i', strtotime($bet['placed_at'])) ?></td>
                                        <td><?= htmlspecialchars($bet['team1']) ?> vs <?= htmlspecialchars($bet['team2']) ?></td>
                                        <td><?= ucfirst($bet['selection']) ?></td>
                                        <td><?= number_format($bet['odds'], 2) ?></td>
                                        <td><?= formatMoney($bet['amount']) ?></td>
                                        <td><?= formatMoney($bet['potential_win']) ?></td>
                                        <td><?= getStatusBadge($bet['status']) ?></td>
                                        <td><?= getStatusBadge($bet['match_status']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-md-3">
                                <div class="card text-center bg-light">
                                    <div class="card-body">
                                        <h6>Pending</h6>
                                        <?php
                                        $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total 
                                                             FROM bets 
                                                             WHERE user_id = ? AND status = 'pending'");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        $pending = $stmt->fetch();
                                        ?>
                                        <h4><?= $pending['count'] ?></h4>
                                        <small><?= formatMoney($pending['total']) ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center bg-light">
                                    <div class="card-body">
                                        <h6>Won</h6>
                                        <?php
                                        $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total 
                                                             FROM bets 
                                                             WHERE user_id = ? AND status = 'won'");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        $won = $stmt->fetch();
                                        ?>
                                        <h4><?= $won['count'] ?></h4>
                                        <small><?= formatMoney($won['total']) ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center bg-light">
                                    <div class="card-body">
                                        <h6>Lost</h6>
                                        <?php
                                        $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total 
                                                             FROM bets 
                                                             WHERE user_id = ? AND status = 'lost'");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        $lost = $stmt->fetch();
                                        ?>
                                        <h4><?= $lost['count'] ?></h4>
                                        <small><?= formatMoney($lost['total']) ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center bg-light">
                                    <div class="card-body">
                                        <h6>Total Profit/Loss</h6>
                                        <?php
                                        $stmt = $db->prepare("SELECT 
                                            SUM(CASE WHEN status = 'won' THEN potential_win - amount ELSE 0 END) as profit,
                                            SUM(CASE WHEN status = 'lost' THEN -amount ELSE 0 END) as loss,
                                            SUM(CASE WHEN status = 'refunded' THEN 0 ELSE 0 END) as refunded
                                            FROM bets WHERE user_id = ?");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        $stats = $stmt->fetch();
                                        $total = ($stats['profit'] ?? 0) + ($stats['loss'] ?? 0);
                                        ?>
                                        <h4 class="<?= $total >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= formatMoney($total) ?>
                                        </h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No bets placed yet.</p>
                        <div class="text-center">
                            <a href="?action=matches" class="btn btn-success btn-lg">
                                <i class="fas fa-coins"></i> Place Your First Bet
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderWallet() {
    if (!isLoggedIn()) {
        header("Location: ?action=login");
        exit;
    }
    
    $db = getDB();
    $user_id = $_SESSION['user_id'];
    
    // Get wallet requests
    $stmt = $db->prepare("SELECT * FROM wallet_requests WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $requests = $stmt->fetchAll();
    ?>
    <div class="row">
        <div class="col-md-4">
            <div class="balance-card mb-4">
                <h5><i class="fas fa-wallet"></i> Wallet Balance</h5>
                <div class="balance-amount"><?= formatMoney($_SESSION['balance']) ?></div>
                <p class="small">Demo Balance - Not Real Money</p>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-exchange-alt"></i> Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#depositModal">
                            <i class="fas fa-plus-circle"></i> Deposit
                        </button>
                        <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#withdrawModal">
                            <i class="fas fa-minus-circle"></i> Withdraw
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Limits
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Min Deposit</span>
                            <span>৳100</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Max Deposit</span>
                            <span>৳50,000</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Min Withdrawal</span>
                            <span>৳500</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Max Withdrawal</span>
                            <span>৳20,000</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-history"></i> Transaction History
                </div>
                <div class="card-body">
                    <?php if ($requests): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td><?= date('d M H:i', strtotime($request['created_at'])) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $request['type'] === 'deposit' ? 'success' : 'warning' ?>">
                                                <?= ucfirst($request['type']) ?>
                                            </span>
                                        </td>
                                        <td><?= formatMoney($request['amount']) ?></td>
                                        <td><?= htmlspecialchars($request['method']) ?></td>
                                        <td><?= getStatusBadge($request['status']) ?></td>
                                        <td>
                                            <?= $request['admin_notes'] ? 
                                                '<small class="text-muted">' . htmlspecialchars($request['admin_notes']) . '</small>' : 
                                                '-' ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No transactions yet.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header">
                    <i class="fas fa-credit-card"></i> Payment Methods
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-2 col-4">
                            <div class="payment-method" data-method="bKash">
                                <div class="payment-icon bkash">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <span>bKash</span>
                            </div>
                        </div>
                        <div class="col-md-2 col-4">
                            <div class="payment-method" data-method="Nagad">
                                <div class="payment-icon nagad">
                                    <i class="fas fa-wallet"></i>
                                </div>
                                <span>Nagad</span>
                            </div>
                        </div>
                        <div class="col-md-2 col-4">
                            <div class="payment-method" data-method="Rocket">
                                <div class="payment-icon rocket">
                                    <i class="fas fa-rocket"></i>
                                </div>
                                <span>Rocket</span>
                            </div>
                        </div>
                        <div class="col-md-2 col-4">
                            <div class="payment-method" data-method="Upay">
                                <div class="payment-icon upay">
                                    <i class="fas fa-bolt"></i>
                                </div>
                                <span>Upay</span>
                            </div>
                        </div>
                        <div class="col-md-2 col-4">
                            <div class="payment-method" data-method="Visa">
                                <div class="payment-icon card-icon">
                                    <i class="fab fa-cc-visa"></i>
                                </div>
                                <span>Visa</span>
                            </div>
                        </div>
                        <div class="col-md-2 col-4">
                            <div class="payment-method" data-method="Mastercard">
                                <div class="payment-icon card-icon">
                                    <i class="fab fa-cc-mastercard"></i>
                                </div>
                                <span>Mastercard</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Deposit Modal -->
    <div class="modal fade" id="depositModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Deposit Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="submit_wallet_request">
                    <input type="hidden" name="type" value="deposit">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Amount (৳)</label>
                            <input type="number" class="form-control" name="amount" min="100" max="50000" required>
                            <div class="form-text">Min: ৳100 | Max: ৳50,000</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-control" name="method" required>
                                <option value="">Select method</option>
                                <option value="bKash">bKash</option>
                                <option value="Nagad">Nagad</option>
                                <option value="Rocket">Rocket</option>
                                <option value="Upay">Upay</option>
                                <option value="Visa">Visa</option>
                                <option value="Mastercard">Mastercard</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Transaction ID (Optional)</label>
                            <input type="text" class="form-control" name="transaction_id" placeholder="Reference number if any">
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> This is a simulation. No real money is involved.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Withdraw Modal -->
    <div class="modal fade" id="withdrawModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-minus-circle"></i> Withdraw Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="submit_wallet_request">
                    <input type="hidden" name="type" value="withdraw">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Amount (৳)</label>
                            <input type="number" class="form-control" name="amount" 
                                   min="500" max="<?= min(20000, $_SESSION['balance']) ?>" required>
                            <div class="form-text">
                                Min: ৳500 | Max: ৳20,000 | Available: <?= formatMoney($_SESSION['balance']) ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-control" name="method" required>
                                <option value="">Select method</option>
                                <option value="bKash">bKash</option>
                                <option value="Nagad">Nagad</option>
                                <option value="Rocket">Rocket</option>
                                <option value="Upay">Upay</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Account Details</label>
                            <input type="text" class="form-control" name="transaction_id" 
                                   placeholder="bKash/Nagad number or account details" required>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Withdrawal requests require admin approval.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-select payment method on click
        document.querySelectorAll('.payment-method').forEach(el => {
            el.addEventListener('click', function() {
                const method = this.dataset.method;
                document.querySelector('select[name="method"]').value = method;
            });
        });
    </script>
    <?php
}

function renderAdminDashboard() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    
    // Get stats
    $stats = [
        'total_users' => $db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(),
        'total_bets' => $db->query("SELECT COUNT(*) FROM bets")->fetchColumn(),
        'pending_requests' => $db->query("SELECT COUNT(*) FROM wallet_requests WHERE status = 'pending'")->fetchColumn(),
        'total_bets_amount' => $db->query("SELECT COALESCE(SUM(amount), 0) FROM bets")->fetchColumn(),
    ];
    ?>
    <div class="row">
        <div class="col-12">
            <h2 class="text-white mb-4"><i class="fas fa-crown"></i> Admin Dashboard</h2>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-3">
            <div class="stat-card users">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6>Total Users</h6>
                        <h3><?= $stats['total_users'] ?></h3>
                    </div>
                    <i class="fas fa-users fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="stat-card bets">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6>Total Bets</h6>
                        <h3><?= $stats['total_bets'] ?></h3>
                        <small>৳<?= number_format($stats['total_bets_amount'], 2) ?></small>
                    </div>
                    <i class="fas fa-chart-line fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="stat-card pending">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6>Pending Requests</h6>
                        <h3><?= $stats['pending_requests'] ?></h3>
                    </div>
                    <i class="fas fa-clock fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="stat-card revenue">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6>Total Balance</h6>
                        <?php
                        $total_balance = $db->query("SELECT COALESCE(SUM(balance), 0) FROM users")->fetchColumn();
                        ?>
                        <h3>৳<?= number_format($total_balance, 2) ?></h3>
                    </div>
                    <i class="fas fa-wallet fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-cogs"></i> Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="?action=admin_create_match" class="btn btn-success">
                            <i class="fas fa-plus"></i> Create Match
                        </a>
                        <a href="?action=admin_matches" class="btn btn-primary">
                            <i class="fas fa-futbol"></i> Manage Matches
                        </a>
                        <a href="?action=admin_settle" class="btn btn-warning">
                            <i class="fas fa-gavel"></i> Settle Bets
                        </a>
                        <a href="?action=admin_wallet" class="btn btn-info">
                            <i class="fas fa-wallet"></i> Wallet Requests
                        </a>
                        <a href="?action=admin_users" class="btn btn-dark">
                            <i class="fas fa-users-cog"></i> Manage Users
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-exclamation-circle"></i> Recent Pending Requests
                </div>
                <div class="card-body">
                    <?php
                    $stmt = $db->prepare("SELECT wr.*, u.username 
                                         FROM wallet_requests wr 
                                         JOIN users u ON wr.user_id = u.id 
                                         WHERE wr.status = 'pending' 
                                         ORDER BY wr.created_at DESC 
                                         LIMIT 5");
                    $stmt->execute();
                    $pending_requests = $stmt->fetchAll();
                    
                    if ($pending_requests): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_requests as $req): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($req['username']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $req['type'] === 'deposit' ? 'success' : 'warning' ?>">
                                                <?= ucfirst($req['type']) ?>
                                            </span>
                                        </td>
                                        <td><?= formatMoney($req['amount']) ?></td>
                                        <td><?= htmlspecialchars($req['method']) ?></td>
                                        <td><?= date('d M H:i', strtotime($req['created_at'])) ?></td>
                                        <td>
                                            <a href="?action=admin_wallet" class="btn btn-sm btn-outline-primary">
                                                Process
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <a href="?action=admin_wallet" class="btn btn-success btn-sm">View All Requests</a>
                    <?php else: ?>
                        <p class="text-center text-muted">No pending requests.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderAdminMatches() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    $stmt = $db->query("SELECT m.*, o.home_odds, o.draw_odds, o.away_odds 
                       FROM matches m 
                       LEFT JOIN odds o ON m.id = o.match_id 
                       ORDER BY m.start_time DESC");
    $matches = $stmt->fetchAll();
    ?>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-futbol"></i> Manage Matches</h5>
                    <a href="?action=admin_create_match" class="btn btn-success btn-sm">
                        <i class="fas fa-plus"></i> New Match
                    </a>
                </div>
                <div class="card-body">
                    <?php if ($matches): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Match</th>
                                        <th>Sport</th>
                                        <th>Start Time</th>
                                        <th>Odds (1-X-2)</th>
                                        <th>Status</th>
                                        <th>Locked</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($matches as $match): ?>
                                    <tr>
                                        <td><?= $match['id'] ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($match['team1']) ?></strong> vs 
                                            <strong><?= htmlspecialchars($match['team2']) ?></strong>
                                        </td>
                                        <td><?= $match['sport'] ?></td>
                                        <td><?= date('d M H:i', strtotime($match['start_time'])) ?></td>
                                        <td>
                                            <?= $match['home_odds'] ?> - 
                                            <?= $match['draw_odds'] ?> - 
                                            <?= $match['away_odds'] ?>
                                        </td>
                                        <td><?= getStatusBadge($match['status']) ?></td>
                                        <td>
                                            <?= $match['locked'] ? 
                                                '<span class="badge bg-danger">Locked</span>' : 
                                                '<span class="badge bg-success">Open</span>' ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editOddsModal<?= $match['id'] ?>">
                                                <i class="fas fa-edit"></i> Odds
                                            </button>
                                            <?php if (!$match['locked']): ?>
                                                <a href="?action=admin_settle&match=<?= $match['id'] ?>" 
                                                   class="btn btn-sm btn-outline-warning">
                                                    <i class="fas fa-gavel"></i> Settle
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    
                                    <!-- Edit Odds Modal -->
                                    <div class="modal fade" id="editOddsModal<?= $match['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Odds</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_odds">
                                                    <input type="hidden" name="match_id" value="<?= $match['id'] ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label"><?= $match['team1'] ?> Win</label>
                                                            <input type="number" class="form-control" 
                                                                   name="home_odds" step="0.01" min="1.01" 
                                                                   value="<?= $match['home_odds'] ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Draw</label>
                                                            <input type="number" class="form-control" 
                                                                   name="draw_odds" step="0.01" min="1.01" 
                                                                   value="<?= $match['draw_odds'] ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label"><?= $match['team2'] ?> Win</label>
                                                            <input type="number" class="form-control" 
                                                                   name="away_odds" step="0.01" min="1.01" 
                                                                   value="<?= $match['away_odds'] ?>" required>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-success">Update Odds</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No matches found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderAdminCreateMatch() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    ?>
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-plus"></i> Create New Match</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_match">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sport</label>
                                <select class="form-control" name="sport" required>
                                    <option value="Football">Football</option>
                                    <option value="Cricket">Cricket</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Time</label>
                                <input type="datetime-local" class="form-control" name="start_time" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Team 1</label>
                                <input type="text" class="form-control" name="team1" placeholder="e.g., Manchester United" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Team 2</label>
                                <input type="text" class="form-control" name="team2" placeholder="e.g., Liverpool" required>
                            </div>
                        </div>
                        
                        <div class="card bg-light mb-3">
                            <div class="card-header">Set Odds</div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Team 1 Win</label>
                                        <input type="number" class="form-control" name="home_odds" step="0.01" min="1.01" value="1.80" required>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Draw</label>
                                        <input type="number" class="form-control" name="draw_odds" step="0.01" min="1.01" value="3.50" required>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Team 2 Win</label>
                                        <input type="number" class="form-control" name="away_odds" step="0.01" min="1.01" value="2.10" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-plus-circle"></i> Create Match
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderAdminSettle() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    $match_id = $_GET['match'] ?? 0;
    
    // Get matches that have pending bets
    $stmt = $db->prepare("SELECT m.*, COUNT(b.id) as pending_bets 
                         FROM matches m 
                         LEFT JOIN bets b ON m.id = b.match_id AND b.status = 'pending' 
                         WHERE m.status = 'upcoming' OR m.status = 'live' 
                         GROUP BY m.id 
                         HAVING pending_bets > 0 
                         ORDER BY m.start_time ASC");
    $stmt->execute();
    $matches = $stmt->fetchAll();
    
    if ($match_id > 0) {
        $stmt = $db->prepare("SELECT * FROM matches WHERE id = ?");
        $stmt->execute([$match_id]);
        $selected_match = $stmt->fetch();
        
        $stmt = $db->prepare("SELECT b.*, u.username 
                             FROM bets b 
                             JOIN users u ON b.user_id = u.id 
                             WHERE b.match_id = ? AND b.status = 'pending'");
        $stmt->execute([$match_id]);
        $pending_bets = $stmt->fetchAll();
    }
    ?>
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list"></i> Matches with Pending Bets
                </div>
                <div class="card-body">
                    <?php if ($matches): ?>
                        <div class="list-group">
                            <?php foreach ($matches as $match): ?>
                            <a href="?action=admin_settle&match=<?= $match['id'] ?>" 
                               class="list-group-item list-group-item-action <?= $match_id == $match['id'] ? 'active' : '' ?>">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?= htmlspecialchars($match['team1']) ?> vs <?= htmlspecialchars($match['team2']) ?></h6>
                                    <span class="badge bg-primary rounded-pill"><?= $match['pending_bets'] ?></span>
                                </div>
                                <small><?= date('d M H:i', strtotime($match['start_time'])) ?></small>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No matches with pending bets.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <?php if ($match_id > 0 && $selected_match): ?>
                <div class="card">
                    <div class="card-header">
                        <h5>Settle Match: <?= htmlspecialchars($selected_match['team1']) ?> vs <?= htmlspecialchars($selected_match['team2']) ?></h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="settle_match">
                            <input type="hidden" name="match_id" value="<?= $match_id ?>">
                            
                            <div class="mb-4">
                                <label class="form-label">Match Result</label>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="card text-center">
                                            <div class="card-body">
                                                <h5><?= htmlspecialchars($selected_match['team1']) ?> Win</h5>
                                                <input type="radio" name="result" value="home" id="result_home" required>
                                                <label for="result_home" class="btn btn-outline-success w-100 mt-2">Select</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card text-center">
                                            <div class="card-body">
                                                <h5>Draw</h5>
                                                <input type="radio" name="result" value="draw" id="result_draw">
                                                <label for="result_draw" class="btn btn-outline-primary w-100 mt-2">Select</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card text-center">
                                            <div class="card-body">
                                                <h5><?= htmlspecialchars($selected_match['team2']) ?> Win</h5>
                                                <input type="radio" name="result" value="away" id="result_away">
                                                <label for="result_away" class="btn btn-outline-danger w-100 mt-2">Select</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div class="card text-center">
                                            <div class="card-body">
                                                <h5>Cancel/Refund All Bets</h5>
                                                <input type="radio" name="result" value="refund" id="result_refund">
                                                <label for="result_refund" class="btn btn-outline-warning w-100 mt-2">Refund All</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($pending_bets): ?>
                                <div class="mb-3">
                                    <h6>Pending Bets (<?= count($pending_bets) ?>)</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Selection</th>
                                                    <th>Stake</th>
                                                    <th>Potential Win</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pending_bets as $bet): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($bet['username']) ?></td>
                                                    <td><?= ucfirst($bet['selection']) ?></td>
                                                    <td><?= formatMoney($bet['amount']) ?></td>
                                                    <td><?= formatMoney($bet['potential_win']) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> 
                                This action cannot be undone. All pending bets will be settled based on the selected result.
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-danger btn-lg">
                                    <i class="fas fa-gavel"></i> Settle Match
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center">
                        <i class="fas fa-futbol fa-3x text-muted mb-3"></i>
                        <h5>Select a match to settle</h5>
                        <p class="text-muted">Choose a match from the list to view and settle pending bets.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function renderAdminWallet() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    $status = $_GET['status'] ?? 'pending';
    
    $stmt = $db->prepare("SELECT wr.*, u.username 
                         FROM wallet_requests wr 
                         JOIN users u ON wr.user_id = u.id 
                         WHERE wr.status = ? 
                         ORDER BY wr.created_at DESC");
    $stmt->execute([$status]);
    $requests = $stmt->fetchAll();
    ?>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-wallet"></i> Wallet Requests</h5>
                    <div class="btn-group">
                        <a href="?action=admin_wallet&status=pending" 
                           class="btn btn-<?= $status === 'pending' ? 'warning' : 'outline-warning' ?> btn-sm">
                            Pending (<?= $db->query("SELECT COUNT(*) FROM wallet_requests WHERE status = 'pending'")->fetchColumn() ?>)
                        </a>
                        <a href="?action=admin_wallet&status=approved" 
                           class="btn btn-<?= $status === 'approved' ? 'success' : 'outline-success' ?> btn-sm">
                            Approved
                        </a>
                        <a href="?action=admin_wallet&status=rejected" 
                           class="btn btn-<?= $status === 'rejected' ? 'danger' : 'outline-danger' ?> btn-sm">
                            Rejected
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($requests): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Details</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $req): ?>
                                    <tr>
                                        <td><?= $req['id'] ?></td>
                                        <td><?= htmlspecialchars($req['username']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $req['type'] === 'deposit' ? 'success' : 'warning' ?>">
                                                <?= ucfirst($req['type']) ?>
                                            </span>
                                        </td>
                                        <td><?= formatMoney($req['amount']) ?></td>
                                        <td><?= htmlspecialchars($req['method']) ?></td>
                                        <td>
                                            <?= $req['transaction_id'] ? 
                                                '<small class="text-muted">' . htmlspecialchars($req['transaction_id']) . '</small>' : 
                                                '-' ?>
                                        </td>
                                        <td><?= date('d M H:i', strtotime($req['created_at'])) ?></td>
                                        <td><?= getStatusBadge($req['status']) ?></td>
                                        <td>
                                            <?php if ($req['status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-success" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#processModal<?= $req['id'] ?>">
                                                    <i class="fas fa-check"></i> Process
                                                </button>
                                            <?php else: ?>
                                                <small class="text-muted">
                                                    <?= $req['processed_at'] ? date('d M', strtotime($req['processed_at'])) : '' ?>
                                                    <?= $req['admin_notes'] ? '<br><small>Note: ' . htmlspecialchars($req['admin_notes']) . '</small>' : '' ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    
                                    <!-- Process Modal -->
                                    <?php if ($req['status'] === 'pending'): ?>
                                    <div class="modal fade" id="processModal<?= $req['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Process Request #<?= $req['id'] ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="process_wallet_request">
                                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <p><strong>User:</strong> <?= htmlspecialchars($req['username']) ?></p>
                                                            <p><strong>Type:</strong> <?= ucfirst($req['type']) ?></p>
                                                            <p><strong>Amount:</strong> <?= formatMoney($req['amount']) ?></p>
                                                            <p><strong>Method:</strong> <?= htmlspecialchars($req['method']) ?></p>
                                                            <?php if ($req['transaction_id']): ?>
                                                                <p><strong>Details:</strong> <?= htmlspecialchars($req['transaction_id']) ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Action</label>
                                                            <select class="form-control" name="status" required>
                                                                <option value="approved">Approve</option>
                                                                <option value="rejected">Reject</option>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Admin Notes (Optional)</label>
                                                            <textarea class="form-control" name="admin_notes" rows="2"></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-success">Submit</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No <?= $status ?> requests found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderAdminUsers() {
    if (!isAdmin()) {
        header("Location: ?action=home");
        exit;
    }
    
    $db = getDB();
    $stmt = $db->query("SELECT * FROM users WHERE role = 'user' ORDER BY created_at DESC");
    $users = $stmt->fetchAll();
    ?>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-users-cog"></i> Manage Users</h5>
                </div>
                <div class="card-body">
                    <?php if ($users): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Balance</th>
                                        <th>Joined</th>
                                        <th>Total Bets</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): 
                                        $bet_stats = $db->prepare("SELECT 
                                            COUNT(*) as total_bets,
                                            SUM(CASE WHEN status = 'won' THEN potential_win - amount ELSE 0 END) as profit,
                                            SUM(CASE WHEN status = 'lost' THEN -amount ELSE 0 END) as loss
                                            FROM bets WHERE user_id = ?");
                                        $bet_stats->execute([$user['id']]);
                                        $stats = $bet_stats->fetch();
                                    ?>
                                    <tr>
                                        <td><?= $user['id'] ?></td>
                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                        <td><?= formatMoney($user['balance']) ?></td>
                                        <td><?= date('d M Y', strtotime($user['created_at'])) ?></td>
                                        <td><?= $stats['total_bets'] ?? 0 ?></td>
                                        <td>
                                            <?php
                                            $total_profit = ($stats['profit'] ?? 0) + ($stats['loss'] ?? 0);
                                            if ($total_profit > 0) {
                                                echo '<span class="badge bg-success">Profit: ' . formatMoney($total_profit) . '</span>';
                                            } elseif ($total_profit < 0) {
                                                echo '<span class="badge bg-danger">Loss: ' . formatMoney(abs($total_profit)) . '</span>';
                                            } else {
                                                echo '<span class="badge bg-secondary">Even</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#adjustModal<?= $user['id'] ?>">
                                                <i class="fas fa-edit"></i> Adjust Balance
                                            </button>
                                        </td>
                                    </tr>
                                    
                                    <!-- Adjust Balance Modal -->
                                    <div class="modal fade" id="adjustModal<?= $user['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Adjust Balance: <?= htmlspecialchars($user['username']) ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="adjust_balance">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <p>Current Balance: <strong><?= formatMoney($user['balance']) ?></strong></p>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Adjustment Amount (৳)</label>
                                                            <div class="input-group">
                                                                <select class="form-select" name="type" style="max-width: 100px;">
                                                                    <option value="add">Add</option>
                                                                    <option value="subtract">Subtract</option>
                                                                </select>
                                                                <input type="number" class="form-control" name="amount" 
                                                                       step="100" min="100" value="1000" required>
                                                            </div>
                                                            <div class="form-text">Use negative numbers to subtract</div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label">Reason</label>
                                                            <input type="text" class="form-control" name="reason" 
                                                                   placeholder="e.g., Bonus, Correction, etc." required>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-success">Apply Adjustment</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No users found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>