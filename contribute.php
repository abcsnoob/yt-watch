<?php
declare(strict_types=1);

define('ROOT_DIR', __DIR__);
$apiKeyFile = ROOT_DIR . DIRECTORY_SEPARATOR . 'api-key.json';

$message = '';
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['api_key'])) {
    $newKey = trim($_POST['api_key']);
    $testVideoId = 'oDIpSDOYfY0';
    $apiUrl = "https://www.googleapis.com/youtube/v3/videos?part=snippet&id={$testVideoId}&key={$newKey}";

    // 1. Kiểm tra Key bằng cách gọi YouTube API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode === 200 && isset($data['items'][0])) {
        // 2. Nếu OK, tiến hành đọc và cập nhật file JSON
        if (file_exists($apiKeyFile)) {
            $config = json_decode((string)file_get_contents($apiKeyFile), true);
            
            // Kiểm tra xem key đã tồn tại chưa để tránh trùng lặp
            if (!in_array($newKey, $config['api_keys'])) {
                $config['api_keys'][] = $newKey;
                
                if (file_put_contents($apiKeyFile, json_encode($config, JSON_PRETTY_PRINT))) {
                    $status = 'success';
                    $message = "Thành công! API Key đã được xác thực và thêm vào hệ thống.";
                } else {
                    $status = 'error';
                    $message = "Lỗi: Không thể ghi vào file api-key.json. Kiểm tra quyền ghi (permission).";
                }
            } else {
                $status = 'info';
                $message = "API Key này đã tồn tại trong hệ thống rồi.";
            }
        } else {
            $status = 'error';
            $message = "Lỗi: Không tìm thấy file api-key.json ở root.";
        }
    } else {
        $status = 'error';
        $errorMsg = $data['error']['message'] ?? 'Key không hợp lệ hoặc hết quota.';
        $message = "Thử nghiệm thất bại: {$errorMsg}";
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Contribute YouTube API Key</title>
    <style>
        body { font-family: sans-serif; max-width: 500px; margin: 50px auto; line-height: 1.6; }
        .card { border: 1px solid #ddd; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        input[type="text"] { width: 100%; padding: 10px; margin: 10px 0; box-sizing: border-box; }
        button { background: #ff0000; color: white; border: none; padding: 10px 20px; cursor: pointer; border-radius: 4px; }
        .msg { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .info { background: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <a href="index.php" style="display: inline-block; margin-bottom: 20px; text-decoration: none; color: #007bff;">&larr; Quay lại Trang chủ</a>
    <div class="card">
        <h2>Đóng góp YouTube API Key</h2>
        <p>Key của bạn sẽ được dùng cho mục đích cộng đồng.</p>
        
        <?php if ($message): ?>
            <div class="msg <?= $status ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label>Dán API Key vào đây:</label>
            <input type="text" name="api_key" placeholder="AIzaSy..." required>
            <button type="submit">Kiểm tra và Lưu</button>
        </form>
    </div>
</body>
</html>