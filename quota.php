<?php
/**
 * File: quota.php
 * Chức năng: Quản lý và tính toán giới hạn (quota) của YouTube API
 */

require_once 'config.php';

/**
 * Tính toán số liệu thống kê về Quota
 */
function get_quota_stats(): array
{
    $totalKeys     = defined('YOUTUBE_API_KEYS') ? count(YOUTUBE_API_KEYS) : 0;
    $exhausted     = get_exhausted_keys_list(); // từ config.php
    $exhaustedCount = count($exhausted);

    $remaining = max(0, $totalKeys - $exhaustedCount);
    $percent   = $totalKeys > 0 ? round(($remaining / $totalKeys) * 100) : 0;

    return [
        'total'           => $totalKeys,
        'remaining'       => $remaining,
        'percent'         => $percent,
        'exhausted_count' => $exhaustedCount,
    ];
}

/**
 * Xác định màu sắc hiển thị dựa trên phần trăm còn lại
 */
function get_quota_bar_color(int $percent): string
{
    if ($percent > 50) {
        return '#28a745'; // Xanh lá (An toàn)
    } elseif ($percent > 20) {
        return '#ffc107'; // Vàng (Cảnh báo)
    } else {
        return '#dc3545'; // Đỏ (Sắp hết)
    }
}
