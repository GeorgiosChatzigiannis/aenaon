<?php
// Simple API - No dependencies
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Config
$PIN = '1234';
$DATA_FILE = 'data.js';
$STRAVA_FILE = 'strava.js';
$MENU_FILE = 'menu.js';
$TRAINING_FILE = 'training.json';
$BACKUP_DIR = 'backups/';
$MAX_BACKUPS = 10;

// Create backup dir
if (!is_dir($BACKUP_DIR)) {
    @mkdir($BACKUP_DIR, 0755, true);
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Test endpoint
if ($action === 'test' || empty($action)) {
    echo json_encode(['status' => 'ok', 'message' => 'API is working', 'php' => phpversion()]);
    exit;
}

// Login
if ($action === 'login') {
    $pin = isset($_POST['pin']) ? $_POST['pin'] : '';
    echo json_encode(['status' => ($pin === $PIN) ? 'success' : 'error']);
    exit;
}

// Save
if ($action === 'save') {
    $target = isset($_POST['target']) ? $_POST['target'] : '';
    $data = isset($_POST['data']) ? $_POST['data'] : '';
    
    if (empty($data)) {
        echo json_encode(['status' => 'error', 'message' => 'No data']);
        exit;
    }
    
    $decoded = json_decode($data);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit;
    }
    
    $content = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $success = false;
    
    if ($target === 'course') {
        $success = @file_put_contents($DATA_FILE, "const COURSE_DATA = " . $content . ";");
    } elseif ($target === 'menu') {
        $success = @file_put_contents($MENU_FILE, "const MENU_DATA = " . $content . ";");
    } elseif ($target === 'strava') {
        $success = @file_put_contents($STRAVA_FILE, "const STRAVA_DATA = " . $content . ";");
    } elseif ($target === 'training') {
        $success = @file_put_contents($TRAINING_FILE, $content);
    }
    
    echo json_encode(['status' => $success ? 'success' : 'error', 'message' => $success ? 'Saved' : 'Write failed - check permissions']);
    exit;
}

// List backups
if ($action === 'list_backups') {
    $backups = [];
    if (is_dir($BACKUP_DIR)) {
        $files = @scandir($BACKUP_DIR, SCANDIR_SORT_DESCENDING);
        if ($files) {
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && substr($file, -5) === '.json') {
                    $backups[] = [
                        'name' => $file,
                        'date' => date('d/m/Y H:i', filemtime($BACKUP_DIR . $file))
                    ];
                }
            }
        }
    }
    echo json_encode(['status' => 'success', 'backups' => array_slice($backups, 0, $MAX_BACKUPS)]);
    exit;
}

// Create backup
if ($action === 'create_backup' || $action === 'auto_backup') {
    $backup = ['created' => date('c'), 'data' => [], 'strava' => [], 'menu' => [], 'training' => []];
    
    // Read files
    if (file_exists($DATA_FILE)) {
        $c = file_get_contents($DATA_FILE);
        if (preg_match('/const COURSE_DATA = (.+);/s', $c, $m)) {
            $backup['data'] = json_decode($m[1], true);
        }
    }
    if (file_exists($STRAVA_FILE)) {
        $c = file_get_contents($STRAVA_FILE);
        if (preg_match('/const STRAVA_DATA = (.+);/s', $c, $m)) {
            $backup['strava'] = json_decode($m[1], true);
        }
    }
    if (file_exists($MENU_FILE)) {
        $c = file_get_contents($MENU_FILE);
        if (preg_match('/const MENU_DATA = (.+);/s', $c, $m)) {
            $backup['menu'] = json_decode($m[1], true);
        }
    }
    if (file_exists($TRAINING_FILE)) {
        $backup['training'] = json_decode(file_get_contents($TRAINING_FILE), true);
    }
    
    $filename = date('Y-m-d_H-i-s') . '.json';
    $success = @file_put_contents($BACKUP_DIR . $filename, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Clean old backups
    if ($success) {
        $files = glob($BACKUP_DIR . '*.json');
        if (count($files) > $MAX_BACKUPS) {
            usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($files, 0, count($files) - $MAX_BACKUPS) as $f) {
                @unlink($f);
            }
        }
    }
    
    echo json_encode(['status' => $success ? 'success' : 'error', 'filename' => $filename]);
    exit;
}

// Restore backup
if ($action === 'restore') {
    $filename = isset($_POST['filename']) ? basename($_POST['filename']) : '';
    $filepath = $BACKUP_DIR . $filename;
    
    if (!file_exists($filepath)) {
        echo json_encode(['status' => 'error', 'message' => 'File not found']);
        exit;
    }
    
    $backup = json_decode(file_get_contents($filepath), true);
    if (!$backup) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid backup']);
        exit;
    }
    
    $errors = [];
    if (!empty($backup['data'])) {
        if (!@file_put_contents($DATA_FILE, "const COURSE_DATA = " . json_encode($backup['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ";")) {
            $errors[] = 'data';
        }
    }
    if (!empty($backup['strava'])) {
        if (!@file_put_contents($STRAVA_FILE, "const STRAVA_DATA = " . json_encode($backup['strava'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ";")) {
            $errors[] = 'strava';
        }
    }
    if (!empty($backup['menu'])) {
        if (!@file_put_contents($MENU_FILE, "const MENU_DATA = " . json_encode($backup['menu'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ";")) {
            $errors[] = 'menu';
        }
    }
    if (!empty($backup['training'])) {
        if (!@file_put_contents($TRAINING_FILE, json_encode($backup['training'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            $errors[] = 'training';
        }
    }
    
    echo json_encode(['status' => empty($errors) ? 'success' : 'error', 'errors' => $errors]);
    exit;
}

// Strava sync (requires curl)
if ($action === 'strava_sync') {
    $token = isset($_POST['access_token']) ? $_POST['access_token'] : '';
    
    if (empty($token)) {
        echo json_encode(['status' => 'error', 'message' => 'No access token']);
        exit;
    }
    
    if (!function_exists('curl_init')) {
        echo json_encode(['status' => 'error', 'message' => 'cURL not available on this server']);
        exit;
    }
    
    $ch = curl_init('https://www.strava.com/api/v3/athlete/activities?per_page=100');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo json_encode(['status' => 'error', 'message' => 'Connection error: ' . $error]);
        exit;
    }
    
    if ($code !== 200) {
        echo json_encode(['status' => 'error', 'message' => 'Strava error (HTTP ' . $code . '). Try refreshing your token.']);
        exit;
    }
    
    $activities = json_decode($response, true);
    if (!is_array($activities)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Strava response']);
        exit;
    }
    
    // Load existing to keep customizations
    $existing = [];
    if (file_exists($STRAVA_FILE)) {
        $c = file_get_contents($STRAVA_FILE);
        if (preg_match('/const STRAVA_DATA = (.+);/s', $c, $m)) {
            foreach (json_decode($m[1], true) ?: [] as $e) {
                $existing[$e['id']] = $e;
            }
        }
    }
    
    $formatted = [];
    foreach ($activities as $a) {
        if ($a['type'] === 'Run') {
            $id = $a['id'];
            $d = new DateTime($a['start_date_local']);
            $formatted[] = [
                'id' => $id,
                'name' => $a['name'],
                'date' => $d->format('d/m/Y'),
                'distance' => $a['distance'],
                'moving_time' => $a['moving_time'],
                'elapsed_time' => $a['elapsed_time'],
                'total_elevation_gain' => $a['total_elevation_gain'] ?? 0,
                'average_speed' => $a['average_speed'] ?? 0,
                'max_speed' => $a['max_speed'] ?? 0,
                'average_heartrate' => $a['average_heartrate'] ?? null,
                'max_heartrate' => $a['max_heartrate'] ?? null,
                'hidden' => isset($existing[$id]) ? $existing[$id]['hidden'] : false,
                'customTitle' => isset($existing[$id]) ? $existing[$id]['customTitle'] : ''
            ];
        }
    }
    
    $success = @file_put_contents($STRAVA_FILE, "const STRAVA_DATA = " . json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ";");
    echo json_encode(['status' => $success ? 'success' : 'error', 'count' => count($formatted)]);
    exit;
}

// Strava token refresh
if ($action === 'strava_refresh') {
    $clientId = isset($_POST['client_id']) ? $_POST['client_id'] : '';
    $clientSecret = isset($_POST['client_secret']) ? $_POST['client_secret'] : '';
    $refreshToken = isset($_POST['refresh_token']) ? $_POST['refresh_token'] : '';
    
    if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing credentials']);
        exit;
    }
    
    if (!function_exists('curl_init')) {
        echo json_encode(['status' => 'error', 'message' => 'cURL not available']);
        exit;
    }
    
    $ch = curl_init('https://www.strava.com/oauth/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code === 200) {
        $data = json_decode($response, true);
        echo json_encode([
            'status' => 'success',
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => $data['expires_at']
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Token refresh failed']);
    }
    exit;
}

// Unknown action
echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);