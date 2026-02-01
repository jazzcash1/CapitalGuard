<?php
// Configuration
define('ADMIN_CODE', '999999');
define('CHANNELS_FILE', 'channels.json');

// Initialize channels data
if (!file_exists(CHANNELS_FILE)) {
    file_put_contents(CHANNELS_FILE, json_encode([]));
}

$channels = json_decode(file_get_contents(CHANNELS_FILE), true) ?: [];
$is_admin = false;
$admin_panel_active = false;
$current_channel = null;

// Process admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    
    // Check admin code
    if (isset($_POST['admin_code']) && $_POST['admin_code'] === ADMIN_CODE) {
        $_SESSION['is_admin'] = true;
        $is_admin = true;
    }
    
    // Verify admin session
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
        $is_admin = true;
        
        // Handle channel actions
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    if (!empty($_POST['name']) && !empty($_POST['url'])) {
                        $channels[] = [
                            'id' => uniqid(),
                            'name' => htmlspecialchars($_POST['name']),
                            'url' => filter_var($_POST['url'], FILTER_SANITIZE_URL)
                        ];
                        saveChannels($channels);
                    }
                    break;
                    
                case 'edit':
                    if (!empty($_POST['id']) && !empty($_POST['name']) && !empty($_POST['url'])) {
                        foreach ($channels as &$channel) {
                            if ($channel['id'] === $_POST['id']) {
                                $channel['name'] = htmlspecialchars($_POST['name']);
                                $channel['url'] = filter_var($_POST['url'], FILTER_SANITIZE_URL);
                                break;
                            }
                        }
                        saveChannels($channels);
                    }
                    break;
                    
                case 'delete':
                    if (!empty($_POST['id'])) {
                        $channels = array_filter($channels, function($ch) {
                            return $ch['id'] !== $_POST['id'];
                        });
                        $channels = array_values($channels);
                        saveChannels($channels);
                    }
                    break;
                    
                case 'logout':
                    session_destroy();
                    $is_admin = false;
                    break;
            }
        }
    }
    
    // Handle channel selection
    if (isset($_POST['select_channel']) && !empty($_POST['channel_id'])) {
        foreach ($channels as $channel) {
            if ($channel['id'] === $_POST['channel_id']) {
                $current_channel = $channel;
                break;
            }
        }
    }
}

// Toggle admin panel if admin code is submitted via GET (for easy access)
if (isset($_GET['admin']) && $_GET['admin'] === ADMIN_CODE) {
    session_start();
    $_SESSION['is_admin'] = true;
    $is_admin = true;
    $admin_panel_active = true;
}

// Check admin session
session_start();
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    $is_admin = true;
}

function saveChannels($channels) {
    file_put_contents(CHANNELS_FILE, json_encode($channels, JSON_PRETTY_PRINT));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live TV Streaming - Watch Free TV Channels</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --dark: #1e293b;
            --light: #f8fafc;
            --danger: #ef4444;
            --success: #10b981;
            --radius: 12px;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--light);
            min-height: 100vh;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            text-align: center;
            padding: 30px 0;
            margin-bottom: 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            font-size: 2.8rem;
            font-weight: 800;
            background: linear-gradient(90deg, var(--primary), #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }

        .tagline {
            color: var(--secondary);
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
        }

        .player-section {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            padding: 25px;
            margin-bottom: 40px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .player-container {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 aspect ratio */
            height: 0;
            overflow: hidden;
            border-radius: var(--radius);
            background: #000;
            margin-bottom: 20px;
        }

        .player-container iframe,
        .player-container video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
        }

        .player-placeholder {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: linear-gradient(45deg, #1e293b, #334155);
            color: var(--secondary);
        }

        .player-placeholder i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: var(--primary);
        }

        .current-channel {
            font-size: 1.5rem;
            font-weight: 600;
            color: white;
            margin-bottom: 10px;
        }

        .channel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 50px;
        }

        .channel-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.1);
            cursor: pointer;
        }

        .channel-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .channel-thumbnail {
            height: 160px;
            background: linear-gradient(45deg, #1e293b, #334155);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 3rem;
        }

        .channel-info {
            padding: 20px;
        }

        .channel-name {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: white;
        }

        .channel-url {
            font-size: 0.9rem;
            color: var(--secondary);
            word-break: break-all;
        }

        .admin-section {
            background: rgba(30, 41, 59, 0.9);
            border-radius: var(--radius);
            padding: 30px;
            margin-top: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .admin-title {
            font-size: 1.8rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-form {
            background: rgba(15, 23, 42, 0.7);
            padding: 25px;
            border-radius: var(--radius);
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: white;
        }

        input[type="text"],
        input[type="url"],
        input[type="password"] {
            width: 100%;
            padding: 14px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            color: white;
            font-size: 1rem;
            transition: var(--transition);
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            opacity: 0.9;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-login {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 100;
            background: rgba(30, 41, 59, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .channel-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .admin-channels-list {
            background: rgba(15, 23, 42, 0.7);
            border-radius: var(--radius);
            padding: 20px;
        }

        .admin-channel-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .admin-channel-info {
            flex-grow: 1;
        }

        .admin-channel-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: white;
        }

        .admin-channel-url {
            font-size: 0.9rem;
            color: var(--secondary);
        }

        footer {
            text-align: center;
            padding: 40px 0;
            margin-top: 60px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--secondary);
            font-size: 0.9rem;
        }

        .mobile-warning {
            display: none;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .channel-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 20px;
            }
            
            .logo {
                font-size: 2.2rem;
            }
            
            .mobile-warning {
                display: block;
            }
            
            .btn-login {
                bottom: 20px;
                right: 20px;
                padding: 12px 20px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .channel-grid {
                grid-template-columns: 1fr;
            }
            
            .admin-section {
                padding: 20px;
            }
            
            .channel-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1 class="logo"><i class="fas fa-satellite-dish"></i> LiveStream TV</h1>
            <p class="tagline">Watch thousands of live TV channels from around the world. Free streaming, no registration required.</p>
        </header>

        <div class="mobile-warning">
            <i class="fas fa-info-circle"></i> Some streams may not work on mobile devices due to format restrictions.
        </div>

        <section class="player-section">
            <h2 class="current-channel">
                <?php if ($current_channel): ?>
                    <i class="fas fa-tv"></i> Now Playing: <?php echo $current_channel['name']; ?>
                <?php else: ?>
                    <i class="fas fa-play-circle"></i> Select a channel to start watching
                <?php endif; ?>
            </h2>
            
            <div class="player-container">
                <?php if ($current_channel): ?>
                    <?php if (strpos($current_channel['url'], 'youtube.com') !== false || strpos($current_channel['url'], 'youtu.be') !== false): ?>
                        <?php
                        // Extract YouTube ID
                        preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $current_channel['url'], $matches);
                        $youtube_id = $matches[1] ?? '';
                        ?>
                        <iframe src="https://www.youtube.com/embed/<?php echo $youtube_id; ?>?autoplay=1" 
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                allowfullscreen></iframe>
                    <?php elseif (strpos($current_channel['url'], 'twitch.tv') !== false): ?>
                        <?php
                        // Extract Twitch channel
                        preg_match('/twitch\.tv\/([^\/\?]+)/', $current_channel['url'], $matches);
                        $twitch_channel = $matches[1] ?? '';
                        ?>
                        <iframe src="https://player.twitch.tv/?channel=<?php echo $twitch_channel; ?>&parent=<?php echo $_SERVER['HTTP_HOST']; ?>" 
                                allowfullscreen></iframe>
                    <?php else: ?>
                        <video controls autoplay>
                            <source src="<?php echo $current_channel['url']; ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="player-placeholder">
                        <i class="fas fa-satellite"></i>
                        <h3>No channel selected</h3>
                        <p>Choose a channel from the list below to start streaming</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section>
            <h2 style="margin-bottom: 25px; font-size: 1.8rem; color: white;">
                <i class="fas fa-broadcast-tower"></i> Available Channels (<?php echo count($channels); ?>)
            </h2>
            
            <div class="channel-grid">
                <?php foreach ($channels as $channel): ?>
                    <div class="channel-card" onclick="selectChannel('<?php echo $channel['id']; ?>')">
                        <div class="channel-thumbnail">
                            <i class="fas fa-tv"></i>
                        </div>
                        <div class="channel-info">
                            <h3 class="channel-name"><?php echo $channel['name']; ?></h3>
                            <p class="channel-url"><?php echo strlen($channel['url']) > 50 ? substr($channel['url'], 0, 50) . '...' : $channel['url']; ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($channels)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 50px; color: var(--secondary);">
                        <i class="fas fa-tv" style="font-size: 4rem; margin-bottom: 20px;"></i>
                        <h3>No channels available</h3>
                        <p>Use the admin panel to add channels (Code: 999999)</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($is_admin): ?>
            <section class="admin-section" id="adminPanel">
                <div class="admin-header">
                    <h2 class="admin-title"><i class="fas fa-cog"></i> Admin Panel</h2>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </button>
                    </form>
                </div>

                <div class="admin-form">
                    <h3 style="margin-bottom: 20px; color: white;"><?php echo isset($_POST['edit_id']) ? 'Edit Channel' : 'Add New Channel'; ?></h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="<?php echo isset($_POST['edit_id']) ? 'edit' : 'add'; ?>">
                        <?php if (isset($_POST['edit_id'])): ?>
                            <input type="hidden" name="id" value="<?php echo $_POST['edit_id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="name">Channel Name</label>
                            <input type="text" id="name" name="name" 
                                   value="<?php echo isset($_POST['edit_name']) ? $_POST['edit_name'] : ''; ?>"
                                   placeholder="e.g., BBC News" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="url">Stream URL</label>
                            <input type="url" id="url" name="url" 
                                   value="<?php echo isset($_POST['edit_url']) ? $_POST['edit_url'] : ''; ?>"
                                   placeholder="https://example.com/stream.m3u8 or YouTube/Twitch URL" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo isset($_POST['edit_id']) ? 'Update Channel' : 'Add Channel'; ?>
                        </button>
                        
                        <?php if (isset($_POST['edit_id'])): ?>
                            <a href="?" class="btn btn-secondary" style="margin-left: 10px;">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="admin-channels-list">
                    <h3 style="margin-bottom: 20px; color: white;">Manage Channels</h3>
                    
                    <?php if (!empty($channels)): ?>
                        <?php foreach ($channels as $channel): ?>
                            <div class="admin-channel-item">
                                <div class="admin-channel-info">
                                    <div class="admin-channel-name"><?php echo $channel['name']; ?></div>
                                    <div class="admin-channel-url"><?php echo $channel['url']; ?></div>
                                </div>
                                <div class="channel-actions">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="edit_id" value="<?php echo $channel['id']; ?>">
                                        <input type="hidden" name="edit_name" value="<?php echo $channel['name']; ?>">
                                        <input type="hidden" name="edit_url" value="<?php echo $channel['url']; ?>">
                                        <button type="submit" class="btn btn-secondary">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $channel['id']; ?>">
                                        <button type="submit" class="btn btn-danger" 
                                                onclick="return confirm('Delete this channel?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; color: var(--secondary); padding: 20px;">
                            No channels added yet. Use the form above to add your first channel.
                        </p>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <footer>
            <p>LiveStream TV &copy; <?php echo date('Y'); ?> - Free Live Television Streaming</p>
            <p style="margin-top: 10px; font-size: 0.8rem; opacity: 0.7;">
                All streams are publicly available. We do not host any content.
            </p>
        </footer>
    </div>

    <?php if (!$is_admin): ?>
        <button class="btn btn-login" onclick="showAdminLogin()">
            <i class="fas fa-lock"></i> Admin
        </button>
    <?php endif; ?>

    <script>
        function selectChannel(channelId) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'channel_id';
            input.value = channelId;
            
            const action = document.createElement('input');
            action.type = 'hidden';
            action.name = 'select_channel';
            action.value = '1';
            
            form.appendChild(input);
            form.appendChild(action);
            document.body.appendChild(form);
            form.submit();
        }
        
        function showAdminLogin() {
            const code = prompt('Enter admin code:');
            if (code) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'admin_code';
                input.value = code;
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Auto-refresh player every 30 seconds if playing
        <?php if ($current_channel): ?>
        setInterval(() => {
            const player = document.querySelector('iframe, video');
            if (player && player.src) {
                // Force refresh for direct streams
                if (player.tagName === 'VIDEO') {
                    player.load();
                }
            }
        }, 30000);
        <?php endif; ?>
        
        // Smooth scroll to admin panel if activated
        <?php if ($admin_panel_active): ?>
        window.addEventListener('load', () => {
            document.getElementById('adminPanel').scrollIntoView({ behavior: 'smooth' });
        });
        <?php endif; ?>
    </script>
</body>
</html>
