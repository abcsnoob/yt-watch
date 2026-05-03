# YouTube Personal Viewer

Dịch vụ xem YouTube cá nhân bằng PHP thuần và Vanilla JS SPA. Backend dùng cache file 24h để giảm quota YouTube Data API v3; frontend dùng thumbnail tĩnh và iframe `youtube-nocookie.com`.

## Cấu trúc

- `config.php`: API key, TTL cache, số lượng kết quả.
- `api.php`: router JSON, YouTube API handler, file cache, validate input, batch channel avatar.
- `oauth.php`: Google OAuth login/callback/logout/status bằng PHP thuần.
- `index.php`: HTML/CSS dark mode responsive.
- `assets/js/app.js`: SPA History API, Shorts, channel header, infinite scroll, fetch JSON.
- `cache/`: nơi lưu cache `.json` theo hash request.


### HƯỚNG DẪN CÀI ĐẶT

1.  **Triển khai mã nguồn:**
    Giải nén source code vào thư mục của web server (ví dụ: `C:\nginx\html` hoặc `/var/www/html`).

2.  **Cấu hình API Keys:**
    *   Đổi tên tệp `api-key.json.example` thành `api-key.json`.
    *   Mở tệp và điền danh sách các API Key (YouTube Data API v3) của bạn vào mảng `api_keys`.
    *   **Lưu ý:** Xóa bỏ toàn bộ các dòng chú thích nếu có (bắt đầu bằng `//`) để đảm bảo tệp đúng định dạng JSON chuẩn.

3.  **Cấu hình Google OAuth:**
    *   Nếu sử dụng tính năng yêu cầu đăng nhập, hãy điền `client_id` và `client_secret` vào mục `oauth_configs` tương ứng với vị trí của API Key đó (ví dụ: Key thứ nhất ứng với ID `0`).
    *   Các Key dùng chung (Community API) từ vị trí thứ 3 trở đi không cần cấu hình OAuth.

4.  **Thiết lập Google Cloud Console:**
    Tại mục Credentials, thêm đường dẫn sau vào phần **Authorized redirect URIs**:
    *   `[http://127.0.0.1:8080/oauth.php?action=callback](http://127.0.0.1:8080/oauth.php?action=callback)` (hoặc URL thực tế trên server của bạn).

5.  **Cấp quyền thư mục:**
    Đảm bảo thư mục `cache` có quyền ghi (**Write Permission**) để hệ thống lưu trữ dữ liệu tạm thời.

6.  **Khởi chạy:**
    Truy cập ứng dụng qua trình duyệt tại địa chỉ: `http://localhost/index.php`.

---

### Lưu ý về tệp `api-key.json`
Cấu trúc tệp phải sạch và không chứa ghi chú ngoài lề để tránh lỗi hệ thống:

```json
{
    "api_keys": [
        "KEY_1",
        "KEY_2",
        "KEY_3"
    ],
    "personal_key_count": 2,
    "oauth_configs": {
        "0": {
            "client_id": "YOUR_CLIENT_ID_1",
            "client_secret": "YOUR_CLIENT_SECRET_1"
        },
        "1": {
            "client_id": "YOUR_CLIENT_ID_2",
            "client_secret": "YOUR_CLIENT_SECRET_2"
        }
    }
}
```

## Ghi chú quota

- Mỗi request YouTube API được hash bằng SHA-256 và lưu tại `cache/{hash}.json`.
- Nếu file cache chưa quá `CACHE_TTL_SECONDS` (mặc định 86400 giây), backend trả từ cache.
- Request OAuth GET như `Kênh đăng ký`, `Video đã thích`, `Đề xuất riêng` có private cache theo từng user/session với `OAUTH_CACHE_TTL_SECONDS` (mặc định 900 giây).
- Request ghi như `Đăng ký kênh` không cache; sau khi đăng ký, cache OAuth đổi version để lần tải sau lấy dữ liệu mới.
- `status.php` là trang quản trị bảo trì, có password, toggle mode và lịch sử thay đổi. Link có sẵn trên sidebar homepage.
- `status.php` lưu và hiển thị `lý do bảo trì`, `thời gian dự kiến`, `đã diễn ra`, và `còn lại`.
- Khi bật bảo trì, `index.php`, `api.php` và `oauth.php` đều trả 503; `status.php` vẫn mở được.
- Mật khẩu quản trị mặc định trong bản này là `admin`; đổi ngay trong `config.php` sau khi mở lần đầu.
- Thumbnail lấy bằng URL tĩnh `https://img.youtube.com/vi/{video_id}/mqdefault.jpg`, không gọi API.
- Avatar channel được lấy bằng một request `channels.list` dạng batch cho mỗi trang kết quả, có cache 24h giống request video.
- Trang kênh lấy thêm banner/avatar/thống kê bằng `channels.list` có cache và nút đăng ký trỏ tới `?sub_confirmation=1`.
- Shorts dùng `search.list` với `videoDuration=short` và `#shorts`, vẫn cache theo hash request.
- Google OAuth dùng scope `openid email profile https://www.googleapis.com/auth/youtube.readonly` và lưu token trong PHP session.
- Khi bật OAuth, tab `Kênh đăng ký` dùng `subscriptions.list(mine=true)` và có infinite load.
- Nút `Đăng ký` gọi trực tiếp `subscriptions.insert` qua OAuth token, cần scope `https://www.googleapis.com/auth/youtube.force-ssl`.
- `Đề xuất riêng` lấy video mới từ các trang kênh đã đăng ký và có infinite load theo trang subscription. YouTube Data API không mở endpoint homepage recommendations giống YouTube web.
- `Video đã thích` dùng `videos.list(myRating=like)` và có infinite load.
- `Lịch sử xem` lưu cục bộ bằng `localStorage` trong trình duyệt và có infinite load theo từng trang cục bộ. YouTube Data API hiện không cung cấp watch history cá nhân cho ứng dụng dạng này.
- Nếu avatar thiếu, ảnh lỗi hoặc quota hết, frontend tự fallback về initials.
- Request API dùng `fields` để chỉ lấy `id`, `snippet/title`, `snippet/channelId`, `snippet/channelTitle`, `snippet/publishedAt`.
